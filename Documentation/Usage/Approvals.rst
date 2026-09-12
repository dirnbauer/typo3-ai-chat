..  include:: /Includes.rst.txt

..  _usage-approvals:

=========
Approvals
=========

When the model wants to change something, it stops and asks.

What you see
============

The turn pauses and the chat shows the pending call: which tool, and the exact
arguments it would run with. Nothing has happened yet — the tool has not been
executed, and no record has been touched.

You then either **approve**, and the turn continues from the tool's result; or
**deny**, and the refusal goes into the transcript so the model can respond to
it. A denial is not a silence: the model is told its request was refused and
usually answers accordingly.

..  note::

    A whole turn is approved at once, not one call at a time. If the model asked
    for three writes, you are deciding about the three together.

Why the pause exists
====================

Two independent things have to be true before a write runs.

**An administrator enabled the tool.** Write tools are off until somebody
switches them on in **AI > Tools**. A freshly installed chat can read the
installation and change nothing.

**You approved this particular call.** Enabling a tool makes it *available to
ask for*, not available to run unattended.

Either one alone would be a weaker promise than it looks. Together they mean a
change requires both a standing decision and a specific one.

Deciding the turn you were shown
================================

The decision carries a digest of the turn it decided, and nr-llm recomputes that
digest from the run's live state before acting on it.

That is what stops a browser tab left open since yesterday from approving work
it never displayed. If the run has moved on — because somebody else decided, or
because the run suspended again on different calls — the decision is refused and
the conversation stays waiting rather than executing something nobody reviewed.

If you see *"the approval must name the turn it decided"*, reload the
conversation and decide again on what it shows you.

What is still checked after you approve
=======================================

Approving is permission, not a bypass. The call still has to pass everything
else:

-   the tool must still be enabled — one disabled while the run was suspended is
    not executed, even though you approved it;
-   your own TYPO3 permissions apply inside the tool;
-   the capability manifest still governs what the MCP server will do at all.

Approving a call you would not be allowed to make yourself does not make it
possible.

Who can approve
===============

The person who started the run, an administrator, or a backend user holding
nr-llm's ``agent_approve`` grant.

Afterwards
==========

The approval is recorded in the run's event stream: who decided, what they
decided, and when. The conversation keeps the transcript; nr-llm keeps the full
trace, including every argument and every result, against the run's uuid.

Cancelling instead
==================

A run that is still in flight can be cancelled. Cancellation is cooperative: the
loop stops at its next step boundary, so a provider call or a tool already
running finishes first. What it guarantees is that nothing *further* is started.
