import type { SequencedEvent, TurnEvent } from '@/state/types';

/**
 * A server-sent event reader that works over POST.
 *
 * The browser's `EventSource` only issues GET, and `conversations/turn` is POST
 * because it changes state — a turn reachable by GET is a turn an `<img>` tag
 * on any page can start. So the stream is read off `fetch`'s body instead, and
 * this is the parser that turns those bytes back into frames.
 *
 * It implements the wire format rather than approximating it, because the cases
 * an approximation gets wrong are the ones that only happen under load:
 *
 * - A frame is delimited by a BLANK LINE, not by a chunk boundary. A network
 *   read can end in the middle of `data: {"run` and the next one carries the
 *   rest; anything that parses per chunk loses that event.
 * - Line endings may be `\n`, `\r\n` or `\r`. The specification allows all
 *   three and a proxy is free to rewrite them.
 * - `data:` may appear more than once in a frame and the values rejoin with
 *   newlines between them. (PHP's `SseEvent` already splits that way.)
 * - A single leading space after the colon is part of the syntax, not the
 *   value.
 * - A line starting with `:` is a comment and carries nothing.
 * - `id:` is the stream's position. It is remembered so a caller can resume,
 *   and reported so the reducer can recognise a frame it has already applied.
 */

const EVENT_NAMES = new Set<TurnEvent['event']>([
  'run.started',
  'step.llm',
  'step.tool.call',
  'step.tool.result',
  'approval.required',
  'message.final',
  'run.finished',
  'run.error',
  'ping',
]);

function isKnownEvent(name: string): name is TurnEvent['event'] {
  return EVENT_NAMES.has(name as TurnEvent['event']);
}

interface Frame {
  event: string;
  data: string;
  id?: number;
}

/**
 * The parser as a value: feed it text, take frames out.
 *
 * Separated from the fetch call because the interesting failures are all in
 * here and none of them need a network to reproduce.
 */
export class SseParser {
  private buffer = '';

  private lastEventId: number | undefined;

  /**
   * @param final true when the body has ended and nothing more can arrive
   *
   * @returns every complete frame the added text finished, in order
   */
  push(chunk: string, final = false): Frame[] {
    this.buffer += chunk;

    // Normalise the three legal line endings to one. Done on the buffer rather
    // than on the chunk because a `\r\n` can be split across two reads: a lone
    // trailing `\r` might be the first half of one, so it waits for its partner
    // — unless the stream has ended, in which case it was a line ending all
    // along and holding it would swallow the last frame.
    this.buffer = this.buffer.replace(/\r\n/g, '\n');
    const trailingCarriageReturn = !final && this.buffer.endsWith('\r');
    const body = trailingCarriageReturn ? this.buffer.slice(0, -1) : this.buffer;
    const normalised = body.replace(/\r/g, '\n');

    const frames: Frame[] = [];
    let rest = normalised;

    for (;;) {
      const boundary = rest.indexOf('\n\n');
      if (boundary === -1) {
        break;
      }
      const frame = this.parseFrame(rest.slice(0, boundary));
      rest = rest.slice(boundary + 2);
      if (frame !== null) {
        frames.push(frame);
      }
    }

    this.buffer = rest + (trailingCarriageReturn ? '\r' : '');

    return frames;
  }

  /**
   * The last `id:` seen, for a caller that wants to resume after `?after=`.
   */
  get lastId(): number | undefined {
    return this.lastEventId;
  }

  private parseFrame(raw: string): Frame | null {
    let event = '';
    const data: string[] = [];
    let id: number | undefined;

    for (const line of raw.split('\n')) {
      if (line === '' || line.startsWith(':')) {
        continue;
      }
      const colon = line.indexOf(':');
      const field = colon === -1 ? line : line.slice(0, colon);
      let value = colon === -1 ? '' : line.slice(colon + 1);
      if (value.startsWith(' ')) {
        value = value.slice(1);
      }

      if (field === 'event') {
        event = value;
      } else if (field === 'data') {
        data.push(value);
      } else if (field === 'id') {
        const parsed = Number.parseInt(value, 10);
        if (Number.isFinite(parsed)) {
          id = parsed;
          this.lastEventId = parsed;
        }
      }
    }

    if (event === '') {
      return null;
    }

    return id === undefined ? { event, data: data.join('\n') } : { event, data: data.join('\n'), id };
  }
}

/**
 * A frame the client understands, or nothing.
 *
 * An unknown event name and an unparseable payload are both survivable: the
 * server may have learned a frame this bundle predates, and dropping one event
 * is always better than ending the run over it.
 */
export function toSequencedEvent(frame: Frame): SequencedEvent | null {
  if (!isKnownEvent(frame.event)) {
    return null;
  }

  let data: unknown = {};
  if (frame.data !== '') {
    try {
      data = JSON.parse(frame.data);
    } catch {
      return null;
    }
  }

  return frame.id === undefined
    ? { event: frame.event, data }
    : { id: frame.id, event: frame.event, data };
}

/**
 * Read a streaming response to its end, reporting every frame as it lands.
 *
 * Throws only for a body that cannot be read at all. Everything else — an
 * unknown frame, a malformed payload, a stream that stops early — is reported
 * through the events the caller already has to handle.
 */
export async function readEventStream(
  body: ReadableStream<Uint8Array>,
  onEvent: (event: SequencedEvent) => void,
  signal?: AbortSignal,
): Promise<void> {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  const parser = new SseParser();

  const abort = () => {
    void reader.cancel().catch(() => undefined);
  };
  signal?.addEventListener('abort', abort);

  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) {
        break;
      }
      // `stream: true` is what makes a multi-byte character split across two
      // network reads survive: the decoder holds the partial sequence instead
      // of emitting a replacement character.
      for (const frame of parser.push(decoder.decode(value, { stream: true }))) {
        const event = toSequencedEvent(frame);
        if (event !== null) {
          onEvent(event);
        }
      }
    }

    // Flush: the decoder's tail, and a final `\r` that was waiting for a `\n`
    // that is never coming.
    for (const frame of parser.push(decoder.decode(), true)) {
      const event = toSequencedEvent(frame);
      if (event !== null) {
        onEvent(event);
      }
    }
  } finally {
    signal?.removeEventListener('abort', abort);
    reader.releaseLock();
  }
}
