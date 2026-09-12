..  include:: /Includes.rst.txt

.. _adr-015:

===============================================
ADR-015: Native in-process MCP tool execution
===============================================

**Status:** Accepted

**Date:** 2026-09-12

**Supersedes:** :ref:`ADR-001 <adr-001>`, :ref:`ADR-002 <adr-002>`,
:ref:`ADR-003 <adr-003>`, :ref:`ADR-014 <adr-014>`

Context
=======

1.x reached TYPO3's own MCP tools the way any external client would: it opened
a connection to an MCP server — a stdio subprocess, or an SSE endpoint — and
spoke the protocol over it. That required a registry table of server records, a
connection checker, a tool-list cache per server, and a CLI worker to own the
long-lived process.

Every one of those parts existed to cross a boundary that did not need
crossing. The MCP server is an extension installed in the same TYPO3
installation, in the same PHP process, behind the same autoloader. Reaching it
through a pipe meant a second bootstrap, a second backend-user session to
authenticate, a serialisation format in the middle, and a whole class of
failures — the subprocess did not start, the token expired, the server record
pointed at the wrong binary — that had nothing to do with what the user asked.

The cost was not only operational. The subprocess ran as *some* backend user,
established by re-authenticating, and keeping that identity truthfully equal to
the person typing in the browser was a standing obligation rather than a
property of the design.

Decision
========

Execute the tools **in-process**, through the MCP server's own application
service.

#.  ``hn/typo3-mcp-server`` moves from ``suggest`` to ``require``. The tool
    catalogue is the tool surface now, so it is not optional.

#.  :php:`Hn\McpServer\Service\McpToolCatalogService` is called directly —
    ``list()``, ``describe()``, ``execute()``. The model reaches exactly the
    code an external MCP client would reach: the same permission checks, the
    same workspace overlays, the same capability manifest.

#.  The catalogue is projected into nr-llm's agent runtime through its
    :php:`ToolProviderInterface`. nr-llm keeps what it is good at — the loop,
    the approvals, the run persistence, the budgets, the telemetry — and this
    extension contributes tools, not a second runtime.

#.  A tool is named ``typo3_<McpName>`` (``typo3_WriteTable``) and joins one
    group, ``typo3_mcp``, so an operator can reason about the whole catalogue
    at once in nr-llm's Tools module.

#.  A turn runs **synchronously, in the AJAX request that asked for it**, and
    never through ``AgentRuntime::enqueue()``.

Point 5 is a consequence, not a preference. The MCP tools read the ambient
:php:`$GLOBALS['BE_USER']` — that is their design, and the server's own
``#[AdminOnly]`` gate reads it too. nr-llm deliberately does the opposite: it
threads the acting user explicitly so a run authorises identically in a request
and on a worker. Bridging the two makes the ambient user load-bearing, so the
adapter refuses to run whenever the ambient user is not provably the run's
actor. In a queue worker there is no ambient user at all, so every tool call in
a queued turn would fail closed. Synchronous execution is what makes the tools
usable.

Consequences
============

-   Nothing to configure, nothing to connect, nothing to check: if the MCP
    server is installed, its tools are there.
-   A tool runs as the person typing. Not as a service account that stands in
    for them, and not as whoever a subprocess last authenticated as.
-   A turn is bounded by the request's own limits. A long agent loop is a long
    request, which is exactly what ``max_execution_time`` is for — and what
    the streaming response (:ref:`ADR-017 <adr-017>`) keeps visible.
-   A conversation left claimed by a request that died needs recovering, since
    nothing else holds the lock. ``webconsulting-ai-chat:cleanup`` does it.
-   The MCP catalogue is user-dependent: several tools build their JSON schema
    from what the current user may reach. The projection is cached per user for
    that reason, and it is empty outside an authenticated request.
-   Data classification had to be supplied. Every projected tool shares one
    group, and nr-llm's fail-closed default for an unknown group would withhold
    the entire catalogue from any provider that is not maximally trusted. The
    class is derived from the sensitivity the capability manifest already
    states per tool.
