..  include:: /Includes.rst.txt

..  _developer-api:

===
API
===

Every route is a TYPO3 backend AJAX route below ``/webconsulting/ai-chat/``, so
every call carries the backend session and TYPO3's CSRF protection.

Every route that changes something is **POST**. That is a security property, not
a style: a state change reachable by GET is reachable from an ``<img>`` tag on
any page a logged-in editor happens to visit.

Routes
======

..  list-table::
    :header-rows: 1
    :widths: 12 34 54

    *   - Method
        - Path
        - Purpose
    *   - GET
        - ``status``
        - Provider, model, resolved tool list with effect and approval
          requirement, budget, limits, suggestions, feature flags.
    *   - GET
        - ``conversations``
        - The user's conversations. ``?archived=1`` includes archived ones.
    *   - GET
        - ``conversations/get``
        - One conversation with its messages. ``?after=<sequence>`` for the tail.
    *   - GET
        - ``conversations/events``
        - The run's execution trace, read from nr-llm. ``?conversation=`` is
          required — the trace is authorised through the conversation, not
          through the run uuid — plus optional ``?runUuid=`` (defaults to the
          conversation's current run) and ``?after=<sequence>``.
    *   - GET
        - ``files/info``
        - FAL metadata for ``?fileUid=``.
    *   - POST
        - ``conversations/create``
        - New conversation. Optional ``title``, ``systemPrompt``.
    *   - POST
        - ``conversations/turn``
        - Start a turn. See below.
    *   - POST
        - ``conversations/approval``
        - Decide a suspended turn: ``approved``, ``turnDigest``.
    *   - POST
        - ``conversations/cancel``
        - Cancel the in-flight run.
    *   - POST
        - ``conversations/archive``
        - ``archived`` (defaults to true).
    *   - POST
        - ``conversations/pin``
        - ``pinned``; omitted toggles.
    *   - POST
        - ``conversations/rename``
        - ``title``, optional ``autoApproveTools``.
    *   - POST
        - ``conversations/delete``
        - Soft delete; the cleanup command prunes it later.
    *   - POST
        - ``files/upload``
        - ``multipart/form-data`` with ``file`` **and** ``conversation``: the
          upload is stored in that conversation's own folder, so it is
          authorised the same way every other write is.

Starting a turn
===============

``conversations/turn`` is one route with two transports, negotiated by the
``Accept`` header:

..  code-block:: text

    Accept: text/event-stream   → server-sent events, as the turn happens
    anything else               → one JSON document with the same event list

Body:

..  code-block:: json

    {
      "conversation": 12,
      "content": "Which pages mention the old product name?",
      "attachments": [{"fileUid": 42}],
      "context": {"module": "web_layout", "pageUid": 7, "workspaceId": 0}
    }

``context`` is optional; it is what makes "this page" mean something.

..  note::

    Because the route is POST, the browser's ``EventSource`` cannot be used —
    it only issues GET. Use a fetch-based SSE reader.

Events
======

The same list reaches the client either way: as SSE frames while streaming, and
as the ``events`` array of the JSON response otherwise.

..  list-table::
    :header-rows: 1
    :widths: 26 74

    *   - Event
        - Payload
    *   - ``run.started``
        - ``{runUuid, userMessageUid}``
    *   - ``step.llm``
        - ``{round, content?, thinking?, tokens:{prompt,completion,total}}``
    *   - ``step.tool.call``
        - ``{round, callId, name, arguments, effect}`` — before it runs
    *   - ``step.tool.result``
        - ``{callId, name, isError, preview, durationMs}``
    *   - ``approval.required``
        - ``{runUuid, turnDigest, calls:[{index, callId, name, arguments}]}``
    *   - ``message.final``
        - ``{messageUid, content}``
    *   - ``run.finished``
        - ``{outcome, usage:{promptTokens, completionTokens, totalTokens}}``
    *   - ``run.error``
        - ``{message}`` — already sanitised
    *   - ``ping``
        - ``{at}`` — heartbeat; also the first frame of every stream

``outcome`` is one of ``completed``, ``awaiting_approval``, ``awaiting_input``,
``guardrail_blocked``, ``guardrail_approval_required``, ``suspend_failed``,
``cancelled``, ``lease_lost``, ``requeued``, ``failed``.

``step.tool.result`` carries a **preview**, not the payload: the full text is
already in the model's context and in nr-llm's persisted run, and pushing it
down the connection a second time costs the browser more than it tells the user.

Approving
=========

``approval.required`` carries a ``turnDigest``. Send it back unchanged:

..  code-block:: json

    {"conversation": 12, "approved": true, "turnDigest": "a1b2c3…"}

nr-llm recomputes the digest from the run's live state and refuses a mismatch,
so a stale tab cannot authorise calls it never displayed. A missing digest is
refused for the same reason — "no digest" and "the wrong digest" prove the same
thing.

Status codes
============

..  list-table::
    :header-rows: 1
    :widths: 12 88

    *   - Code
        - Meaning
    *   - 400
        - Empty or over-long message, missing parameter, or an upload larger
          than 20 MB. (The size cap is a parameter problem — nothing has been
          read yet. 422 is reserved for a file that WAS read and could not be
          made sense of.)
    *   - 403
        - The user may not use the chat.
    *   - 404
        - No such conversation **for this user** — a stranger learns nothing
          about what exists.
    *   - 409
        - The conversation is busy, or the approval is not applicable.
    *   - 422
        - Unsupported or unreadable upload.
    *   - 429
        - Rate limit, conversation cap, or concurrent-run cap.
