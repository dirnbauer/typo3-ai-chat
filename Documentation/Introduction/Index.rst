..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

What it does
============

TYPO3 AI Chat lets a backend user ask their installation a question in plain
language — and lets the model answer it by *using the installation*, through the
same MCP tools an external AI client would use.

"Which pages still mention the old product name?" is answered by running a
search, not by guessing. "Rename this page" pauses and asks you first.

Two surfaces, one chat
======================

A panel in the backend toolbar, and a full module under **Tools**. They are the
same conversation list, the same transcript and the same controls; the panel
follows you between modules, the module gives the transcript room to breathe.

What makes it trustworthy
=========================

**It acts as you.** Every tool call runs under your own backend user, through
the MCP server's own code — so your page permissions, your table access, your
workspace and your language restrictions apply exactly as they do everywhere
else in TYPO3. There is no service account standing in for you.

**A write always stops and asks.** A tool that changes something suspends the
turn. You see which tool, with which arguments, and decide. The decision is
bound to the turn you actually looked at, so a browser tab left open since
yesterday cannot approve work it never showed you.

**Reads are on, writes are off.** Until an administrator enables them in
nr-llm's Tools module, only read-only tools are offered at all.

**Nothing is hidden.** Every step of a run — the model's request, each tool
call, its arguments and its result — is persisted by nr-llm and readable
afterwards, per run.

What it is built on
===================

`nr-llm <https://github.com/netresearch/t3x-nr-llm>`__ owns the agent loop, the
approvals, the run records, the budgets and the provider abstraction.
`hn/typo3-mcp-server <https://github.com/dirnbauer/typo3-mcp-server>`__ owns the
tools. This extension is the part in between: it projects the installation's MCP
catalogue into nr-llm's runtime, runs one turn per request, and presents the
result.

Attribution
===========

This project is derived from
`Netresearch nr-mcp-agent <https://github.com/netresearch/t3x-nr-mcp-agent>`__.
Thank you, Netresearch, for publishing the original extension and for the nr-llm
foundation this still depends on. The upstream Git history is retained in this
repository; the full attribution is in ``THANKS-NETRESEARCH.md``.

Requirements
============

-   PHP 8.4
-   TYPO3 14.3.7 or newer
-   ``netresearch/nr-llm`` 0.34, with a configured Task
-   ``hn/typo3-mcp-server`` 0.7 — required, not optional
-   optional: ``phpoffice/phpspreadsheet`` for XLSX attachment extraction
