..  include:: /Includes.rst.txt

..  _configuration-tool-access:

===========
Tool access
===========

Three gates decide which tools a turn may offer the model. They are intersected,
and it is worth knowing which one you are reaching for.

The three gates
===============

**1. nr-llm's tool policy** — **AI > Tools**.

The installation-wide answer to "does this tool exist for anybody?". Set once by
an administrator, enforced again by the runtime at call time, so nothing further
down can widen it.

**2.** :confval:`allowedGroups` — extension configuration.

Who may use the chat at all. A user outside it has no chat, and therefore no
tools.

**3. User TSconfig** — the per-user narrowing described below.

Use gate 1 to decide what the installation permits, and gate 3 to decide what a
particular editor should be offered out of that.

User TSconfig
=============

..  code-block:: typoscript

    tx_webconsultingaichat {
        tools {
            # Only these tools may be offered. Names are the MODEL-facing ones.
            allow = typo3_GetPage, typo3_GetPageTree, typo3_Search

            # Never offer these, whatever else says.
            deny = typo3_SafeCli
        }
    }

``deny`` wins over ``allow``. A narrowing rule that a broader rule further down
can cancel is not a narrowing rule.

Absent is not the same as empty
-------------------------------

..  code-block:: typoscript

    # No allow key at all: do not narrow. The user is offered whatever
    # nr-llm has enabled.

    # An EMPTY allow key: allow nothing. The chat still answers questions,
    # it just cannot touch the installation.
    tx_webconsultingaichat.tools.allow =

The two are deliberately different. Collapsing them would make ``allow =``
silently permissive, and a security setting should never fail in that direction.

Tool names
==========

Tools from this installation's MCP catalogue are named ``typo3_<McpName>`` —
``typo3_GetPage``, ``typo3_WriteTable``, ``typo3_SafeCli``. The prefix exists so
the model can tell an installation tool from an nr-llm builtin at a glance, and
so the two name spaces cannot collide.

nr-llm's own builtin tools keep their own names and can be allowed or denied
here in the same way.

The whole catalogue shares the group ``typo3_mcp``, which is what lets an
administrator toggle all of it at once in **AI > Tools**.

What a user actually gets
=========================

The ``status`` endpoint reports the resolved set — after all three gates — with
each tool's effect and whether it will require an approval. That is the
authoritative answer for a given user, and it is what the chat's own tool list
displays.

What TSconfig cannot do
=======================

-   It cannot enable a tool that nr-llm has disabled. An allow-list only
    narrows.
-   It cannot lower a tool's approval requirement. That comes from the tool's
    code and is not configurable — an administrator must not be able to relabel
    a write as a read to dodge the gate.
-   It cannot grant permissions the backend user does not have. A tool runs
    under that user, and TYPO3's own checks apply inside it.
