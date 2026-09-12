..  include:: /Includes.rst.txt

====================
AI Chat for TYPO3
====================

:Extension key:
    webconsulting_ai_chat

:Package name:
    webconsulting/typo3-ai-chat

:Version:
    |release|

:Language:
    en

:Author:
    Webconsulting; inspired by Netresearch DTT GmbH

:License:
    This document is published under the
    `GPL-2.0-or-later <https://spdx.org/licenses/GPL-2.0-or-later>`__
    license.

:Rendered:
    |today|

----

TYPO3 AI Chat lets a backend user ask their installation a question in plain
language, and lets the model answer it by using the installation — through the
same MCP tools an external AI client would use, executed in-process, as the
user themselves, with every write pausing for a human decision.

`nr-llm <https://github.com/netresearch/t3x-nr-llm>`__ owns the agent loop and
the governance; `hn/typo3-mcp-server
<https://github.com/dirnbauer/typo3-mcp-server>`__ owns the tools.

It is derived from Netresearch nr-mcp-agent. Thank you, Netresearch, for the
original extension and the open TYPO3 AI foundation.

----

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Developer/Index
    Changelog
