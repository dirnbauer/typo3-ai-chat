..  include:: /Includes.rst.txt

==========
Agent loop
==========

Since version 0.6.3 the backend AI Chat does not run its own tool loop.
``ChatService`` delegates the whole chat turn to nr-llm's ``AgentRuntime``
(nr-llm ADR-101), which drives the model over nr-llm's builtin tool
registry and returns the settled result synchronously.

Processing a turn
=================

``ChatService::processConversation()`` performs the following steps:

1.  If no nr-llm Task is configured (``llmTaskUid`` is ``0``), the
    conversation is set to ``failed`` with a descriptive message.
2.  Resolve the ``LlmConfiguration`` the chat should use from the
    configured Task (``llmTaskUid`` -> ``Task`` -> ``getConfiguration()``).
    A missing Task or Configuration fails loudly rather than silently
    degrading to a no-tools chat.
3.  Set the conversation status to ``processing``.
4.  Build the message transcript: a ``system`` message carrying the
    identity/behaviour contract and the resolved Task/Configuration
    prompts (see Architecture > System prompt priority), followed by the
    stored conversation messages. File attachments are expanded to the
    multimodal wire shape and forwarded as array messages.
5.  Call ``AgentRuntimeInterface::run()`` with an ``AgentRunRequest`` built
    from the configuration, the messages and the initiating backend user
    uid. ``allowedToolNames`` is left at ``null`` so the run is offered the
    whole globally-enabled tool set; nr-llm's own tool gate (RBAC,
    global enable cascade, per-configuration groups) stays authoritative.
6.  Map the returned ``AgentRunResult`` onto the conversation.

Outcome mapping
===============

``AgentRuntime::run()`` never throws for a run outcome; it returns a
settled ``AgentRunResult``. ``ChatService`` maps it as follows:

*   ``COMPLETED`` -- append the final assistant answer
    (``ToolLoopResult::$finalContent``) and set status ``idle``.
*   any other outcome (``FAILED``, ``GUARDRAIL_BLOCKED``,
    ``AWAITING_APPROVAL``, …) -- set status ``failed`` with a sanitized
    reason taken from ``AgentRunResult::$error`` or derived from the
    outcome. The mapping keeps a default arm because ``AgentRunOutcome``
    gains cases in nr-llm minor releases.

The tools the model can call, their execution, retry/back-off on
transient provider errors, budget enforcement and the iteration cap all
live inside nr-llm now.

Synchronous execution and resume
================================

``AgentRuntime::run()`` is synchronous and drives the entire tool loop in
one call, so a turn never leaves persisted "pending tool calls" in the
conversation. The CLI worker (``ai-chat:process`` / ``ai-chat:worker``)
therefore always calls ``processConversation()``.
``resumeConversation()`` re-runs the turn over the existing transcript
for a resumable conversation (``processing``, ``tool_loop`` or
``failed``), which is used to recover a conversation left ``processing``
by a crashed worker.

MCP tool provider
=================

The ``McpToolProvider`` / ``McpConnection`` classes remain in the
codebase but are no longer used by the chat turn. Direct MCP-server
tooling for the backend is superseded by nr-llm's builtin tool registry;
the MCP integration is retained for the planned move into nr-llm.
