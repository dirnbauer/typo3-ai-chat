..  include:: /Includes.rst.txt

..  _developer-architecture:

============
Architecture
============

Where the parts live
====================

Three packages, and the division is deliberate:

..  code-block:: text

    hn/typo3-mcp-server     the TOOLS      — what can be done to TYPO3
    netresearch/nr-llm      the RUNTIME    — the loop, approvals, runs, budgets
    webconsulting_ai_chat   the SEAM       — projects one into the other,
                                             and presents the result

This extension owns very little on purpose. It does not implement tools (the MCP
server does) and it does not implement an agent loop (nr-llm does). What it owns
is the join: projecting the catalogue, running one turn per request, persisting
the transcript, and the HTTP surface.

One turn, end to end
====================

..  code-block:: text

    POST conversations/turn
      │
      ├─ ChatApiController          authorise, parse, rate-limit, CLAIM the
      │                             conversation (the per-conversation lock)
      │
      ├─ ChatTurnService            persist the user message
      │                             build an AgentRunRequest:
      │                               · configuration  ← nr-llm Task
      │                               · messages       ← TranscriptBuilder
      │                               · actor          ← BackendUserContext
      │                               · allowedTools   ← ToolAccessService
      │                               · options        ← ToolOptions + caller source
      │
      ├─ AgentRuntime::run()        nr-llm owns everything in here
      │    └─ ToolRegistry
      │         └─ McpCatalogToolProvider  ← our projection
      │              └─ McpCatalogTool
      │                   └─ McpToolCatalogService::execute()   IN-PROCESS
      │
      ├─ TurnStepRecorder           steps → client events, as they happen
      ├─ TurnPersister              steps → message rows
      ├─ RunOutcomeMapper           outcome → conversation status
      └─ SSE or JSON                the same events either way

The tool projection
===================

nr-llm knows its builtin tools at container-compile time because they are
DI-tagged classes. The MCP catalogue cannot be known then — which tools exist
depends on which extensions are installed and on what the capability manifest
permits, both runtime facts. :php:`ToolProviderInterface` exists for exactly
that, so :php:`McpCatalogToolProvider` is tagged ``nr_llm.tool_provider`` and
yields one :php:`McpCatalogTool` per catalogue entry.

Each projected tool carries three declarations the runtime acts on:

``effect``
    Does it write? From the tool's MCP annotations first, the capability
    manifest's required subsystems second. An **empty** declaration is read-only
    (``GetCapabilities`` really does touch nothing); an **unknown** subsystem is
    a write, because that is the safe guess about a capability this version has
    never heard of.

``dataClass``
    How sensitive is its output? Derived from the same subsystems. Without it
    every projected tool would fall to nr-llm's fail-closed default for an
    unknown group and be withheld from any provider that is not maximally
    trusted.

``requiresAdmin``
    From the MCP ``#[AdminOnly]`` attribute, plus the subsystems whose reach is
    the whole installation or the host.

Only read-only tools are enabled by default; a write stays dark until an
administrator switches it on.

The catalogue metadata is cached, keyed by the registered tool names **and the
acting backend user** — several MCP tools build their schema from what the
current user may reach, so a key without the user would serve one editor's table
list to another.

The identity contract
=====================

This is the part worth understanding before changing anything here.

nr-llm threads the acting user explicitly through :php:`ToolExecutionContext`,
precisely so a run authorises identically in a request and on a queue worker.
The MCP tools do the opposite: they read the ambient :php:`$GLOBALS['BE_USER']`,
and the MCP server's own ``#[AdminOnly]`` gate reads it too.

Bridging the two makes the ambient user load-bearing. :php:`McpCatalogTool`
therefore refuses to execute unless the ambient user is provably the run's
actor — no actor, no ambient user, a mismatch, or an unresolvable uid all
produce an error result.

**That is why a turn is synchronous.** In a queue worker there is no ambient
user, so every tool call would fail closed. ``AgentRuntime::enqueue()`` is not
used and must not be.

What is persisted where
=======================

..  code-block:: text

    tx_webconsultingaichat_conversation   identity, lifecycle, pending approval
    tx_webconsultingaichat_message        one row per message
    nr-llm's run tables                   every step: arguments, results,
                                          timings, artifacts, approvals

The transcript is ours; the trace is nr-llm's. A message row carries
``run_uuid`` and that is the join — ``conversations/events`` reads the trace back
through ``AgentRuntime::events()`` under the caller's own actor.

The tool round-trip *is* stored, and that is not a contradiction: an assistant
tool-call turn and the tool turn answering it are the transcript the next turn
replays, and a provider rejects a tool turn whose call is missing. What is not
copied is the trace proper — full arguments, durations, artifacts, thinking, raw
provider bodies.

Locking
=======

One conversation, one turn. ``claimForTurn()`` is a compare-and-swap into
``status=processing``: two tabs pressing send at the same moment cannot both run
against the same transcript, and the loser is told the conversation is busy.

Nothing else holds that lock, so a request that dies leaves it held.
``webconsulting-ai-chat:cleanup`` is what releases it.
