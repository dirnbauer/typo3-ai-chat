..  include:: /Includes.rst.txt

.. _adr-017:

================================================
ADR-017: Server-sent events for turn streaming
================================================

**Status:** Accepted

**Date:** 2026-09-12

**Supersedes:** :ref:`ADR-007 <adr-007>`

Context
=======

1.x polled. The turn ran somewhere else — a forked CLI process — so the browser
had nothing to hold on to and asked every second whether anything had changed.
That was the right call while the work was elsewhere, and it stopped being the
right call the moment the turn moved into the request that asked for it
(:ref:`ADR-015 <adr-015>`).

Polling also reads badly for this particular workload. An agent turn is a
sequence of visible moments — the model thought, it asked for a tool, the tool
answered, it thought again — and a poll turns that into a staircase of
identical requests that mostly say "nothing yet", with a second of latency on
each step that did happen.

Decision
========

Stream the turn as **server-sent events**, from the same route that starts it.

#.  **One route, negotiated.** ``conversations/turn`` starts a turn and reports
    it: as SSE when the client sends ``Accept: text/event-stream``, and as one
    JSON document otherwise. The same producer and the same event list feed
    both, so the two transports cannot drift into disagreeing about what
    happened — and the JSON form is what the functional suite asserts against.

#.  **The response body IS the turn.** The body implements TYPO3's
    :php:`SelfEmittableStreamInterface`, and the producer runs while it is
    emitted. A normal PSR-7 body has to exist before it is written, and a
    turn's body does not exist until the turn is over — which is the one thing
    streaming is for. Every other ``StreamInterface`` method throws rather than
    answering plausibly, so a middleware cannot silently buffer the stream and
    undo it.

#.  **SSE, not WebSockets.** The traffic is one-directional and short-lived;
    the client's other half of the conversation is an ordinary POST. A
    WebSocket would need a second protocol, a second authentication path and a
    server that TYPO3 does not ship.

#.  **A heartbeat at step boundaries.** A synchronous turn is one blocking call
    chain, and a step boundary is the only moment control returns — there is no
    timer to hang a ping on. A provider call longer than the interval therefore
    still passes without one; that is the honest limit of the design, and why
    15 s sits well under a typical proxy's idle timeout.

#.  **A disconnect cancels the run.** ``connection_aborted()`` only updates
    after a write, so the check runs after each flush. A user who closes the
    tab stops paying for the rest of the turn.

Consequences
============

-   Buffering anywhere in the chain defeats it. The stream unwinds PHP's own
    output buffers and sends ``X-Accel-Buffering: no`` for nginx, which is the
    failure that otherwise looks exactly like "streaming does not work" and has
    nothing to do with this code.
-   Every mutating route is POST for CSRF reasons, so the client cannot use the
    browser's ``EventSource`` (GET only) and needs a fetch-based SSE reader.
-   A turn occupies a PHP worker for its duration. That is the same cost the
    synchronous execution already implies; it is now visible rather than hidden
    behind a poll.
-   The event list is a public contract. It is documented in
    :ref:`developer-api`, and a frame added to it is additive.
