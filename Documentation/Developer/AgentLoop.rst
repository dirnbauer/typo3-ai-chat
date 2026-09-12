..  include:: /Includes.rst.txt

..  _developer-agent-loop:

==========
Agent loop
==========

This extension does not implement an agent loop. nr-llm's ``AgentRuntime`` does
— the model round-trips, the tool gate, the approval suspension, the run
persistence and the budget accounting are all its (nr-llm ADR-101). What follows
is the seam on either side of it.

Building the request
====================

:php:`ChatTurnService::run()` assembles an ``AgentRunRequest``:

``configuration``
    From the configured nr-llm Task: ``llmTaskUid`` → ``Task`` →
    ``getConfiguration()``. A missing task or configuration fails loudly rather
    than degrading quietly into a chat that cannot do anything.

``messages``
    From :php:`TranscriptBuilder`: up to three system messages — the task's
    prompt, the conversation's prompt, the "where the user is standing" snippet,
    general before specific — followed by the tail of the persisted transcript.

``actor``
    From :php:`BackendUserContext`, the one place this extension reads
    ``$GLOBALS['BE_USER']``. Uid, admin flag, groups and nr-llm grants are frozen
    here, at the HTTP boundary, where the ambient user genuinely is the caller.

``allowedToolNames``
    From :php:`ToolAccessService` — see :ref:`configuration-tool-access`.

``options``
    ``ToolOptions`` carrying the backend user uid for the budget pre-flight,
    annotated ``withCallerSource('webconsulting_ai_chat', 'turn')`` so the
    installation's telemetry can tell chat traffic from everything else.

``maxIterations``
    From extension configuration, clamped again by nr-llm's own ceiling.

The transcript window
=====================

A conversation grows without bound; a context window does not. Only the tail is
replayed.

That truncation has one sharp edge worth knowing about: cutting in the middle of
a tool round-trip leaves a ``tool`` turn whose assistant tool-call turn fell off
the front — an answer to a question that was never asked, which providers reject
outright. :php:`TranscriptBuilder` therefore drops orphaned tool turns from the
front of the window.

Reading the steps back
======================

``AgentRuntime::run()`` reports each step through an ``$onStep`` closure as it is
recorded, and returns the full list when it settles.

:php:`TurnStepRecorder` turns those into client events, and does two jobs at
once:

-   **deduplication**, because a step arrives twice — live through the callback
    and again in the settled result;
-   **call correlation**, because a tool step carries the tool's *name* but not
    the id of the call it answers. Within a round the loop executes the requested
    calls in the order the model asked for them, so a per-name queue reunites
    them.

A run resumed after an approval starts a fresh step list, so the assistant turn
that requested the calls is in the previous segment. The pending calls from the
stored approval card are seeded into the correlation for that reason — without
them the approved or denied tool turns would have no call to answer, be dropped,
and leave a transcript the next turn's provider refuses.

Outcomes
========

``AgentRuntime::run()`` never throws for a run outcome; it returns a settled
result. :php:`RunOutcomeMapper` is the **only** place that names an
``AgentRunOutcome`` case:

..  list-table::
    :header-rows: 1
    :widths: 38 22 40

    *   - Outcome
        - Conversation
        - Note
    *   - ``COMPLETED``
        - idle
        -
    *   - ``AWAITING_APPROVAL``
        - awaiting_approval
        - Pending calls and digest stored on the row.
    *   - ``AWAITING_INPUT``
        - awaiting_approval
        - Continue it in nr-llm's Agent Runs module.
    *   - ``GUARDRAIL_BLOCKED``
        - failed
        - Reason from the guardrail, sanitised.
    *   - ``GUARDRAIL_APPROVAL_REQUIRED``
        - failed
        - Same.
    *   - ``SUSPEND_FAILED``
        - failed
        - An approval was required but could not be stored, so no resume may be
          offered (nr-llm ADR-092).
    *   - ``CANCELLED``
        - idle
        -
    *   - ``LEASE_LOST`` / ``REQUEUED``
        - unchanged
        - Another executor owns the run; this request must not settle it.
    *   - ``FAILED``
        - failed
        - Reason sanitised.
    *   - *anything else*
        - failed
        - Default arm. nr-llm may add outcomes in a minor release.

The default arm is deliberate redundancy: a run whose meaning this version does
not know must not leave the conversation idle and invite another message on top
of it. ``RunOutcomeMapperTest`` walks ``AgentRunOutcome::cases()`` and fails when
nr-llm adds one, so the decision gets made in review rather than in production.
