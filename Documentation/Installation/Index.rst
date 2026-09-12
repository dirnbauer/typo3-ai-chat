..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Install the extension
=====================

..  code-block:: bash

    composer require webconsulting/typo3-ai-chat
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 database:updateschema

``hn/typo3-mcp-server`` comes along as a hard requirement — the tools are the
point, so they are not optional.

The MCP server fork is distributed over Git rather than Packagist, so the
consuming project needs a repository entry:

..  code-block:: json

    {
        "repositories": [
            {"type": "vcs", "url": "https://github.com/dirnbauer/typo3-mcp-server.git"},
            {"type": "vcs", "url": "https://github.com/dirnbauer/typo3-abilities.git"}
        ]
    }

Point it at an LLM
==================

The chat does not configure a provider of its own; it runs on an nr-llm
**Task**, so provider keys, models, budgets and governance stay in one place for
the whole installation.

#.  In **AI > Providers**, create a provider and store its API key.
#.  In **AI > Models**, add the model you want to use and tick its
    ``tools`` capability. A model that cannot call tools can chat, but it cannot
    do anything.
#.  In **AI > Configurations**, create a configuration pointing at that model.
#.  In **AI > Tasks**, create a task using that configuration. Its prompt
    template becomes the chat's system instruction.
#.  In **Admin Tools > Settings > Extension Configuration >
    webconsulting_ai_chat**, set ``llmTaskUid`` to the task's UID.

Until ``llmTaskUid`` points at a usable task the toolbar button stays hidden and
the status endpoint says why.

Decide which tools may run
==========================

Freshly installed, only read-only tools are offered. That is deliberate: a write
should be a decision somebody made, not something inherited from an install.

In **AI > Tools**, enable the write tools you want available — the whole group
``typo3_mcp`` can be toggled at once, and individual tools within it. Enabling a
write tool does not make it run unattended; it makes it *available to ask for*
(see :ref:`usage-approvals`).

Upgrading from 1.x
==================

..  warning::

    Migration from ``nr_mcp_agent`` is only available in the **1.x** line. A
    site still holding nr-mcp-agent data must run that migration on 1.x before
    upgrading to 2.0.

After the schema update, run the upgrade wizard
**"AI Chat: migrate conversation transcripts to message rows"** in
**Admin Tools > Upgrade**. It converts each 1.x transcript blob into message
rows and can be re-run safely — a conversation that already has rows is left
alone.

The wizard empties the legacy ``messages`` column but does not drop it. Drop it
with the database analyser once you are satisfied with the result.

What else changes on upgrade
----------------------------

-   ``tx_webconsultingaichat_mcp_server`` is no longer used. The database
    analyser will offer to drop it; there is nothing in it to keep.
-   ``webconsulting-ai-chat:process`` and ``webconsulting-ai-chat:worker`` are
    gone. Remove any scheduler task or systemd unit that runs them.
-   ``webconsulting-ai-chat:cleanup`` is now the one command, and it is worth
    scheduling (see :ref:`configuration`).

Attachments
===========

Uploads land in the FAL folder named by ``uploadFolder``, below a per-user and
per-conversation path. Make sure that storage is writable and is **not** one
you publish.
