..  include:: /Includes.rst.txt

..  _developer-commands:

================
Console commands
================

One command. The processing commands of 1.x are gone: a turn now runs in the
request that asked for it (:ref:`ADR-015 <adr-015>`), so there is nothing left
to dispatch.

webconsulting-ai-chat:cleanup
=============================

..  code-block:: bash

    vendor/bin/typo3 webconsulting-ai-chat:cleanup
    vendor/bin/typo3 webconsulting-ai-chat:cleanup --dry-run

Four passes, in the order they depend on each other.

**1. Release stuck conversations.** A turn runs inside a request, so a request
that dies — a PHP timeout, a deployment, a closed connection — leaves a
conversation claimed forever. Nothing else can release that lock, which is why
this pass exists and why it is first. A conversation claimed for longer than 15
minutes is settled as failed with an explanation.

**2. Archive inactive conversations**, after :confval:`autoArchiveDays`.

**3. Delete expired conversations**, after :confval:`attachmentRetentionDays` —
archived and soft-deleted ones, with their messages and their uploaded files.
Files go before rows on purpose: an orphaned file is invisible, an orphaned row
is not.

**4. Sweep orphaned messages** whose conversation no longer exists, from a hard
delete in the List module or an interrupted earlier run.

``--dry-run`` reports what each pass would do and changes nothing.

Scheduling it
=============

Run it daily. Not optional in practice: pass 1 is the only thing that recovers a
conversation whose request died, so an installation that never runs this will
eventually have a conversation nobody can continue.

Add a **Execute console command** scheduler task, or a cron entry:

..  code-block:: text

    15 3 * * *  /usr/bin/php /var/www/site/vendor/bin/typo3 webconsulting-ai-chat:cleanup

Removed in 2.0
==============

..  list-table::
    :header-rows: 1
    :widths: 42 58

    *   - Command
        - What replaced it
    *   - ``webconsulting-ai-chat:process``
        - Nothing. A turn runs in its own request.
    *   - ``webconsulting-ai-chat:worker``
        - Nothing. There is no queue to drain.
    *   - ``webconsulting-ai-chat:migrate-nr-mcp-agent``
        - Only available in the 1.x line. Migrate on 1.x before upgrading.

Remove any scheduler task or systemd unit that still runs the first two.
