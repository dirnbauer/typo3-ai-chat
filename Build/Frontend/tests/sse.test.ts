import { describe, expect, it } from 'vitest';
import { SseParser, readEventStream, toSequencedEvent } from '@/lib/sse';
import type { SequencedEvent } from '@/state/types';

/**
 * The reader that replaces EventSource.
 *
 * Everything here is a failure that only happens over a real network — a frame
 * cut in half by a TCP read, a proxy that rewrote the line endings — which is
 * exactly why it is worth being able to reproduce without one.
 */

function frames(parser: SseParser, chunk: string) {
  return parser.push(chunk);
}

describe('SseParser', () => {
  it('parses one complete frame', () => {
    const parser = new SseParser();
    const result = frames(parser, 'id: 1\nevent: ping\ndata: {"at":1}\n\n');

    expect(result).toEqual([{ id: 1, event: 'ping', data: '{"at":1}' }]);
  });

  it('holds a frame back until its blank line arrives', () => {
    const parser = new SseParser();

    expect(frames(parser, 'event: run.started\ndata: {"runU')).toEqual([]);
    expect(frames(parser, 'uid":"r1"}\n')).toEqual([]);
    expect(frames(parser, '\n')).toEqual([{ event: 'run.started', data: '{"runUuid":"r1"}' }]);
  });

  it('reassembles a frame split mid-field-name', () => {
    const parser = new SseParser();
    frames(parser, 'ev');
    frames(parser, 'ent: ping\nda');

    expect(frames(parser, 'ta: {}\n\n')).toEqual([{ event: 'ping', data: '{}' }]);
  });

  it('accepts CRLF line endings', () => {
    const parser = new SseParser();

    expect(frames(parser, 'id: 3\r\nevent: ping\r\ndata: {"at":2}\r\n\r\n')).toEqual([
      { id: 3, event: 'ping', data: '{"at":2}' },
    ]);
  });

  it('accepts a CRLF split across two reads', () => {
    const parser = new SseParser();

    expect(frames(parser, 'event: ping\r\ndata: {}\r')).toEqual([]);
    expect(frames(parser, '\n\r\n')).toEqual([{ event: 'ping', data: '{}' }]);
  });

  it('accepts a bare carriage return as a line ending', () => {
    const parser = new SseParser();

    // The next frame's first bytes follow, so the delimiting `\r\r` is not the
    // end of the buffer and nothing has to be held back.
    expect(frames(parser, 'event: ping\rdata: {}\r\revent: run.started')).toEqual([
      { event: 'ping', data: '{}' },
    ]);
  });

  it('releases a trailing carriage return once the body has ended', () => {
    const parser = new SseParser();

    // Held back while more bytes could still arrive: it might be a `\r\n`.
    expect(parser.push('event: ping\rdata: {}\r\r')).toEqual([]);
    expect(parser.push('', true)).toEqual([{ event: 'ping', data: '{}' }]);
  });

  it('rejoins repeated data lines with newlines', () => {
    const parser = new SseParser();
    const result = frames(parser, 'event: run.error\ndata: line one\ndata: line two\n\n');

    expect(result[0]?.data).toBe('line one\nline two');
  });

  it('strips exactly one space after the colon and keeps the rest', () => {
    const parser = new SseParser();
    const result = frames(parser, 'event: ping\ndata:  two spaces\n\n');

    expect(result[0]?.data).toBe(' two spaces');
  });

  it('ignores comment lines', () => {
    const parser = new SseParser();
    const result = frames(parser, ': keep-alive\nevent: ping\ndata: {}\n\n');

    expect(result).toHaveLength(1);
  });

  it('delivers several frames from one chunk, in order', () => {
    const parser = new SseParser();
    const result = frames(
      parser,
      'id: 1\nevent: ping\ndata: {}\n\nid: 2\nevent: run.started\ndata: {"runUuid":"r"}\n\n',
    );

    expect(result.map((frame) => frame.event)).toEqual(['ping', 'run.started']);
    expect(result.map((frame) => frame.id)).toEqual([1, 2]);
  });

  it('tracks the last id for a caller that wants to resume', () => {
    const parser = new SseParser();
    frames(parser, 'id: 4\nevent: ping\ndata: {}\n\nid: 5\nevent: ping\ndata: {}\n\n');

    expect(parser.lastId).toBe(5);
  });

  it('drops a frame with no event name', () => {
    const parser = new SseParser();

    expect(frames(parser, 'data: orphan\n\n')).toEqual([]);
  });
});

describe('toSequencedEvent', () => {
  it('decodes a known frame', () => {
    expect(toSequencedEvent({ id: 2, event: 'run.finished', data: '{"outcome":"completed"}' })).toEqual({
      id: 2,
      event: 'run.finished',
      data: { outcome: 'completed' },
    });
  });

  it('drops a frame this bundle does not know', () => {
    expect(toSequencedEvent({ event: 'step.telepathy', data: '{}' })).toBeNull();
  });

  it('drops a frame whose payload is not JSON rather than ending the run', () => {
    expect(toSequencedEvent({ event: 'ping', data: '{oops' })).toBeNull();
  });

  it('treats an empty payload as an empty object', () => {
    expect(toSequencedEvent({ event: 'ping', data: '' })).toEqual({ event: 'ping', data: {} });
  });

  it('omits the id when the frame carried none, so the reducer cannot mistake 0 for one', () => {
    const decoded = toSequencedEvent({ event: 'ping', data: '{}' });

    expect(decoded).not.toBeNull();
    expect(Object.hasOwn(decoded as object, 'id')).toBe(false);
  });
});

function streamOf(...chunks: string[]): ReadableStream<Uint8Array> {
  const encoder = new TextEncoder();

  return new ReadableStream({
    start(controller) {
      for (const chunk of chunks) {
        controller.enqueue(encoder.encode(chunk));
      }
      controller.close();
    },
  });
}

describe('readEventStream', () => {
  it('reports every frame in order and finishes when the body ends', async () => {
    const seen: SequencedEvent[] = [];
    await readEventStream(
      streamOf(
        'id: 1\nevent: ping\ndata: {"at":1}\n\n',
        'id: 2\nevent: run.started\ndata: {"runUuid":"r1","userMessageUid":4}\n\n',
        'id: 3\nevent: run.finished\ndata: {"outcome":"completed","usage":{}}\n\n',
      ),
      (event) => seen.push(event),
    );

    expect(seen.map((event) => event.event)).toEqual(['ping', 'run.started', 'run.finished']);
  });

  it('survives a multi-byte character split across two network reads', async () => {
    const encoded = new TextEncoder().encode('event: message.final\ndata: {"content":"Grüße"}\n\n');
    const cut = encoded.indexOf(0xc3) + 1; // between the two bytes of "ü"
    const seen: SequencedEvent[] = [];

    await readEventStream(
      new ReadableStream({
        start(controller) {
          controller.enqueue(encoded.slice(0, cut));
          controller.enqueue(encoded.slice(cut));
          controller.close();
        },
      }),
      (event) => seen.push(event),
    );

    expect(seen).toHaveLength(1);
    expect((seen[0]?.data as { content: string }).content).toBe('Grüße');
  });

  it('stops when the caller aborts', async () => {
    const controller = new AbortController();
    const seen: SequencedEvent[] = [];

    const stream = new ReadableStream<Uint8Array>({
      start(streamController) {
        streamController.enqueue(new TextEncoder().encode('event: ping\ndata: {}\n\n'));
        controller.abort();
        streamController.close();
      },
    });

    await readEventStream(stream, (event) => seen.push(event), controller.signal);

    expect(seen.length).toBeLessThanOrEqual(1);
  });
});
