..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

Opening the chat
================

The chat icon in the top-right toolbar opens a panel over whatever module you
are in, and it stays open as you move between modules. **Tools > TYPO3 AI Chat**
opens the same chat with more room.

Both are the same conversations, the same transcripts and the same controls —
the panel for a quick question, the module for a long session.

..  figure:: /Images/ToolbarButton.png
    :alt: The AI Chat button in the TYPO3 backend toolbar

    The toolbar button appears once an administrator has configured an nr-llm
    task and granted you access.

Asking something
================

Type a question and send it. The chat sees the module you are in, the page you
have open and the workspace you are working in, so "rename this page" means the
page in front of you.

That context is offered to the model, not obeyed by it: the chat only acts on it
when your message refers to it.

What happens during a turn
==========================

A turn is streamed as it happens, so you can watch it rather than wait for it:

-   the model thinks, and its answer appears as it is produced;
-   when it wants to use a tool, you see which tool and with which arguments,
    before it runs;
-   when the tool answers, you see a preview of what it returned;
-   the model may then use another tool, or answer.

How many times it may go round is capped by :confval:`maxIterations`.

If you close the tab, the turn is cancelled. You do not keep paying for an
answer nobody is reading.

Reading, and changing
=====================

Reading is immediate. Asking for a page, searching content, listing records — a
read-only tool runs as soon as the model asks for it, under your own
permissions, and you see the result.

Changing is not. A tool that writes suspends the turn and asks you first — see
:ref:`usage-approvals`.

..  note::

    A tool can only ever do what *you* can do. It runs as your backend user, so
    your page permissions, table access, workspace and language restrictions
    apply inside it exactly as they do in the rest of TYPO3.

Attachments
===========

You can attach a PDF, a Word document, a spreadsheet or a plain text file. The
text is extracted server-side and attached to your message, so it works whatever
the provider supports.

The file type is detected from the file's own bytes rather than what the browser
claims, and a file that cannot be read is refused at upload rather than halfway
through a turn.

Managing conversations
======================

Rename, pin, archive or delete a conversation from its entry in the list.

Delete is a soft delete: the conversation stops appearing immediately, and the
cleanup command removes it — with its messages and its uploaded files — once
the configured retention has passed.

When something goes wrong
=========================

A failed turn says what failed in the conversation itself. Error messages are
sanitised before you see them: API keys and URLs are redacted, so an error that
quotes a provider's response cannot leak a credential into a transcript.

A conversation stuck on "processing" means the request that was running it died
— a timeout, a deployment, a closed connection. ``webconsulting-ai-chat:cleanup``
releases those; if it runs daily, this fixes itself.

..  toctree::
    :maxdepth: 1

    Approvals
