# TYPO3 AI Chat by Webconsulting

[![CI](https://github.com/dirnbauer/typo3-ai-chat/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-ai-chat/actions)

Ask your TYPO3 installation a question in plain language — and let the model
answer it by *using* the installation, through the same MCP tools an external AI
client would use.

"Which pages still mention the old product name?" runs a search. "Rename this
page" stops and asks you first.

This project is derived from
[Netresearch nr-mcp-agent](https://github.com/netresearch/t3x-nr-mcp-agent).
Thank you, Netresearch, for publishing the original extension, for the nr-llm
foundation this still depends on, and for investing in open TYPO3 AI
infrastructure. The upstream Git history is retained here and the detailed
attribution is in [THANKS-NETRESEARCH.md](THANKS-NETRESEARCH.md).

## How it works

```text
hn/typo3-mcp-server     the TOOLS     — what can be done to TYPO3
netresearch/nr-llm      the RUNTIME   — the loop, approvals, runs, budgets
webconsulting_ai_chat   the SEAM      — projects one into the other
```

The tools run **in-process**: this extension calls the MCP server's own
application service directly, so the model reaches exactly the code an external
client would — same permission checks, same workspace rules, same capability
manifest. There is no subprocess, no HTTP self-call and no server registry.

A turn therefore runs **synchronously, in the request that started it**. That is
not a shortcut: the MCP tools read the ambient backend user, and the tool adapter
refuses to run unless that user is provably the run's actor. In a queue worker
there is no ambient user, so every tool call would fail closed.

## What makes it trustworthy

- **It acts as you.** Every tool call runs under your own backend user, so your
  page permissions, table access, workspace and language restrictions apply.
- **A write always stops and asks.** You see the tool and its exact arguments
  before anything happens. The decision is bound to the turn you actually looked
  at, so a stale browser tab cannot approve work it never displayed.
- **Reads are on, writes are off** until an administrator enables them.
- **Nothing is hidden.** nr-llm persists every step of every run.

## Requirements

- PHP 8.4
- TYPO3 14.3.7+
- `netresearch/nr-llm` ^0.34 with a configured Task
- `hn/typo3-mcp-server` ^0.7 (required, not optional)

## Installation

```bash
composer require webconsulting/typo3-ai-chat
vendor/bin/typo3 extension:setup
vendor/bin/typo3 database:updateschema
```

The MCP server fork is distributed over Git, so the consuming project needs VCS
repository entries for `dirnbauer/typo3-mcp-server` and `dirnbauer/typo3-abilities`.
Then point `llmTaskUid` at an nr-llm Task in **Admin Tools → Settings →
Extension Configuration**, and enable the write tools you want in **AI → Tools**.
Full instructions: [Documentation/](Documentation/).

## Upgrading from 1.x

> **Migration from `nr_mcp_agent` is only available in the 1.x line.** A site
> still holding nr-mcp-agent data must migrate on 1.x *before* upgrading to
> 2.0.0.

After the schema update, run the upgrade wizard *"AI Chat: migrate conversation
transcripts to message rows"*. The CLI processing commands are gone — remove any
scheduler task that still runs them — and `webconsulting-ai-chat:cleanup` should
be scheduled daily, because it is the only thing that releases a conversation
left claimed by a request that died.

## Development

```bash
composer ci:cgl      # coding standards
composer ci:phpstan  # level 10
composer ci:tests    # unit + functional
```

The functional suite runs on sqlite locally and against MariaDB in CI; the
switch is environment variables only. An agent loop is tested with a scripted
nr-llm adapter driven by a queued response file, registered only outside
production and behind an environment flag. See
[Documentation/Developer/Testing.rst](Documentation/Developer/Testing.rst).

The frontend is a React/shadcn bundle rendering `<wc-ai-chat>` in a Shadow DOM;
the PHP side renders the mount point and serves the JSON/SSE API.

```bash
npm ci && npm run build   # → Resources/Public/JavaScript/Dist/app.js (committed)
npm test && npm run lint  # vitest, then tsc + eslint
```

CI rebuilds the bundle and fails if it differs, so rebuild and commit it with
any source change.

## Credits — thank you, Netresearch

The original architecture, conversation lifecycle, FAL upload endpoint, document
extractors and much of the PHP test foundation came from Netresearch's
GPL-licensed nr-mcp-agent. Thank you to **Netresearch DTT GmbH** for
[nr-mcp-agent](https://github.com/netresearch/t3x-nr-mcp-agent),
[nr-llm](https://github.com/netresearch/t3x-nr-llm) and
[nr-vault](https://github.com/netresearch/t3x-nr-vault).

Also thank you to [hauptsache.net](https://hauptsache.net/) for
[`hn/typo3-mcp-server`](https://github.com/hauptsacheNet/typo3-mcp-server).

## License

GPL-2.0-or-later, matching the original extension. See [LICENSE](LICENSE) and
[THANKS-NETRESEARCH.md](THANKS-NETRESEARCH.md).
