.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

The full, per-release changelog lives in ``CHANGELOG.md`` in the repository
root. What follows is the part an integrator has to act on.

.. _version-2-0-0:

Version 2.0.0
=============

TYPO3's own MCP tools are executed **in-process** now, as the acting backend
user, through nr-llm's agent runtime — with human approval for every write.

Breaking changes
----------------

-   **TYPO3 13 is no longer supported.** 2.0 requires TYPO3 14.3.7+ and PHP 8.4.
-   **``hn/typo3-mcp-server`` is a hard requirement** (^0.7), not a suggestion.
-   **The MCP server registry is gone.** ``tx_webconsultingaichat_mcp_server``
    and its TCA are removed; the installation's own catalogue is the tool set.
    Drop the table with the database analyser.
-   **The CLI processing lane is gone.** ``webconsulting-ai-chat:process`` and
    ``webconsulting-ai-chat:worker`` are removed — remove any scheduler task or
    systemd unit that runs them.
-   **The Flue workflow lane is removed**, with ``enableFlue`` and
    ``flueFlowUid``.
-   **The legacy frontend is removed.** The 2.0 UI is a React/shadcn bundle
    mounting ``<wc-ai-chat>`` in a Shadow DOM.
-   **Migration from ``nr_mcp_agent`` is only available in the 1.x line.** A
    site still holding nr-mcp-agent data must migrate on 1.x *before* upgrading.

What you have to do
-------------------

#.  Run ``vendor/bin/typo3 database:updateschema``.
#.  Run the upgrade wizard **"AI Chat: migrate conversation transcripts to
    message rows"** in **Admin Tools > Upgrade**. It is idempotent, and it
    empties but does not drop the legacy ``messages`` column.
#.  Remove any scheduler task running the deleted processing commands.
#.  Schedule ``webconsulting-ai-chat:cleanup`` daily. It is the only thing that
    releases a conversation left claimed by a request that died.
#.  In **AI > Tools**, enable the write tools you want available. Freshly
    upgraded, only read-only tools are offered.

Highlights
----------

-   Every tool call runs as the acting backend user, through the MCP server's
    own code — so TYPO3's permissions, workspaces and language restrictions
    apply inside it.
-   A write suspends the turn and asks. The decision is bound to the turn it
    decided, so a stale browser tab cannot approve work it never displayed.
-   A turn is streamed as server-sent events from the same route that starts it,
    and returns the identical event list as JSON when the client does not ask
    for a stream.
-   Each message is its own row, so a transcript can be queried, paged and
    pruned instead of rewritten whole on every append.

Earlier versions
================

See ``CHANGELOG.md`` for 0.1.0 through 0.7.0.
