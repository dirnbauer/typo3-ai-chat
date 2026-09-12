<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;
use Webconsulting\Typo3AiChat\Http\ServerSentEventStream;
use Webconsulting\Typo3AiChat\Http\SseEvent;
use Webconsulting\Typo3AiChat\Http\TurnEventSink;

/**
 * The wire format, which is the part of streaming that fails silently: a frame
 * missing its blank line is buffered by the browser until the next one arrives,
 * and a newline inside a value ends the frame early. Neither shows up as an
 * error anywhere — the events simply never appear.
 */
final class ServerSentEventStreamTest extends TestCase
{
    #[Test]
    public function aFrameNamesItsEventAndEndsWithABlankLine(): void
    {
        $frame = (new SseEvent('run.started', ['runUuid' => 'abc']))->encode();

        self::assertSame("event: run.started\ndata: {\"runUuid\":\"abc\"}\n\n", $frame);
    }

    #[Test]
    public function anIdIsWrittenBeforeTheEventName(): void
    {
        $frame = (new SseEvent('step.llm', ['round' => 1], 4))->encode();

        self::assertStringStartsWith("id: 4\nevent: step.llm\n", $frame);
    }

    /**
     * A literal newline in the data would end the frame, so every line carries
     * its own `data:` prefix and the client rejoins them.
     *
     * In practice JSON escaping means a payload never contains a raw newline,
     * so this rule never fires in production — which is why it is tested
     * directly rather than through a payload that cannot trigger it.
     */
    #[Test]
    public function aRawNewlineInTheDataIsSplitIntoSeveralDataLines(): void
    {
        self::assertSame(
            "event: report\ndata: first\ndata: second\n\n",
            SseEvent::frame('report', "first\nsecond"),
        );
    }

    #[Test]
    public function aCarriageReturnDoesNotSurviveIntoTheFrame(): void
    {
        self::assertSame(
            "event: report\ndata: first\ndata: second\n\n",
            SseEvent::frame('report', "first\r\nsecond"),
        );
    }

    #[Test]
    public function aNewlineInAPayloadIsEscapedRatherThanSplittingTheFrame(): void
    {
        $frame = (new SseEvent('message.final', ['content' => "line one\nline two"]))->encode();

        self::assertStringContainsString('line one\nline two', $frame, 'JSON escapes the newline...');
        self::assertSame(1, substr_count($frame, 'data: '), '...so the frame stays one data line.');
        self::assertStringEndsWith("\n\n", $frame);
    }

    #[Test]
    public function anUnencodablePayloadStillProducesAValidFrame(): void
    {
        $frame = (new SseEvent('step.tool.result', ['preview' => "\xB1\x31"]))->encode();

        self::assertStringEndsWith("\n\n", $frame);
        self::assertStringContainsString('event: step.tool.result', $frame);
    }

    #[Test]
    public function thePingIsANamedEventSoAClientCanListenForIt(): void
    {
        $frame = SseEvent::ping()->encode();

        self::assertStringContainsString('event: ping', $frame);
        self::assertStringEndsWith("\n\n", $frame);
    }

    #[Test]
    public function theSinkCollectsEveryEventForTheJsonTransport(): void
    {
        $sink = new TurnEventSink();
        $emit = $sink->emitter();

        $emit('run.started', ['runUuid' => 'r1']);
        $emit('message.final', ['messageUid' => 3, 'content' => 'done']);
        $emit('run.finished', ['outcome' => 'completed']);

        self::assertSame(
            ['run.started', 'message.final', 'run.finished'],
            array_column($sink->collected(), 'event'),
        );
        self::assertSame('done', $sink->collected()[1]['data']['content']);
    }

    #[Test]
    public function theSinkWritesThroughToItsWriterInOrder(): void
    {
        $written = [];
        $sink = new TurnEventSink(writer: static function (SseEvent $event) use (&$written): void {
            $written[] = $event->name;
        });

        $sink->emit('run.started');
        $sink->emit('run.finished');

        self::assertSame(['run.started', 'run.finished'], $written);
    }

    #[Test]
    public function theSinkReportsAnAbortAndRunsTheCallbackOnce(): void
    {
        $aborts = 0;
        $sink = new TurnEventSink(
            writer: static function (SseEvent $event): void {},
            abortCheck: static fn(): bool => true,
            onAbort: static function () use (&$aborts): void {
                ++$aborts;
            },
        );

        $sink->emit('run.started');
        $sink->emit('step.llm');

        self::assertTrue($sink->isAborted());
        self::assertSame(1, $aborts, 'A client only goes away once.');
    }

    #[Test]
    public function theStreamAnnouncesTheHeadersThatMakeStreamingWork(): void
    {
        $headers = ServerSentEventStream::headers();

        self::assertSame('text/event-stream; charset=utf-8', $headers['Content-Type']);
        self::assertStringContainsString('no-cache', $headers['Cache-Control']);
        self::assertSame('no', $headers['X-Accel-Buffering'], 'nginx buffers a proxied response without this.');
    }

    #[Test]
    public function theStreamEmitsAnOpeningPingBeforeTheProducerRuns(): void
    {
        $stream = new ServerSentEventStream(static function (TurnEventSink $sink): void {
            $sink->emit('run.finished', ['outcome' => 'completed']);
        });

        $written = [];
        $stream->produce(new TurnEventSink(writer: static function (SseEvent $event) use (&$written): void {
            $written[] = $event->name;
        }));

        self::assertSame(
            ['ping', 'run.finished'],
            $written,
            'The first byte is what fires EventSource.onopen, so it cannot wait for the model.',
        );
    }

    #[Test]
    public function theStreamIsSelfEmittableAndRefusesEveryOtherStreamOperation(): void
    {
        $stream = new ServerSentEventStream(static function (TurnEventSink $sink): void {});

        self::assertContains(
            SelfEmittableStreamInterface::class,
            class_implements($stream) ?: [],
            'Only a self-emittable body can write while the turn is still running.',
        );
        self::assertFalse($stream->isSeekable());
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isWritable());

        $this->expectException(RuntimeException::class);
        $stream->getContents();
    }
}
