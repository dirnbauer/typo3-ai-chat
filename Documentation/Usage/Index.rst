..  include:: /Includes.rst.txt

=====
Usage
=====

The operator console
====================

Select the chat/tools icon in the top-right TYPO3 toolbar for the inline drawer,
or open **Tools > TYPO3 AI Chat** for the full operator console. Both surfaces
use the same conversations, attachments, approvals and execution ledger. The
drawer's expand button opens the full module when more room is useful.

The full interface has three working areas:

* the dark conversation rail on the left;
* the assistant-ui thread and attachment composer in the centre;
* the execution ledger on the right.

The ledger is the important difference from a normal chat. It records the tools
the agent used, their arguments, their returned result, and failures. A write or
other governed call can pause as **Approval required**. Inspect its arguments,
then choose **Approve once** or **Deny**. TYPO3 permissions and nr-llm policy are
evaluated again when the run resumes.

Direct tools
============

The default **Direct** lane runs through nr-llm's ``AgentRuntime`` as the
authenticated backend user.

1. Create or select a conversation.
2. Describe what you want inspected or changed.
3. Add files if helpful.
4. Send the request.
5. Follow the live status and execution ledger.
6. Review any requested approval before allowing it.

The direct lane is appropriate for interactive questions, TYPO3 inspection and
bounded tool execution.

Durable Flue workflows
======================

When the administrator enables Flue, the top bar offers **Direct** and **Flue**.
Choose Flue, enter the target page UID and send an instruction.

Flue creates a durable run, resolves page/workspace context, mints the MCP token,
applies the configured MCP tool allowlist, and keeps write tools in a draft
workspace. The chat polls the mirrored run and adds its result to both the thread
and execution ledger.

Flue intentionally accepts page workflow requests, not arbitrary shell commands.
It is the Cursor-like MCP lane without a permission bypass.

Images and documents
====================

Use the paperclip button or drag files onto the composer. Multiple files can be
attached to one request.

* Images show a thumbnail before send.
* PDFs show an embedded first-page preview before send.
* DOCX, TXT and XLSX show a document card.
* Remove any pending file with the close button.

TYPO3 validates the detected MIME type, size, FAL permission and document
readability server-side. Files are stored below
``fileadmin/typo3-ai-chat/<backend-user-uid>/``. The limits are 20 MB per file
and five files per conversation.

Conversations
=============

The rail lists recent conversations and their message count. Create a new
conversation with **New conversation**. Select a previous conversation to load
its complete thread and run ledger. The archive action removes a conversation
from the default list without deleting its stored record.

Migration from nr-mcp-agent
===========================

Keep the original extension installed until the replacement works:

..  code-block:: bash

    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 ai-chat:migrate-nr-mcp-agent

The command is idempotent, preserves target records and copies conversations and
MCP server definitions. Only then remove ``netresearch/nr-mcp-agent``.

Thank you, Netresearch
======================

This extension is derived from Netresearch's nr-mcp-agent. Thank you,
Netresearch, for the original backend chat, document pipeline, processing
strategies and test foundation, and for nr-llm and nr-vault. The backend itself
also displays this credit.
