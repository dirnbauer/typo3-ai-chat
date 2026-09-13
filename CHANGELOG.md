# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.1] - 2026-09-13

### Fixed

- The upgrade-wizard functional test re-added the legacy `messages` column in
  every `setUp()`. On MariaDB the testing framework keeps the schema for the
  whole test class, so five of the six tests failed with "Duplicate column
  name". SQLite hid it by rebuilding the database per test method. No shipped
  code changed.

## [2.0.0] - 2026-09-13

TYPO3's own MCP tools are executed **in-process** now, as the acting backend
user, through nr-llm's agent runtime — with human approval for every write.

### Breaking

- **TYPO3 13 is no longer supported.** 2.0 requires TYPO3 14.3.7+ and PHP 8.4.
- **`hn/typo3-mcp-server` is now a hard requirement** (^0.7), not a suggestion.
  The tool catalogue is the tool surface, so it is not optional.
- **The MCP server registry is gone.** `tx_webconsultingaichat_mcp_server`, its
  TCA and its cache-flush hook are removed — there is nothing to configure,
  because the installation's own catalogue is the tool set. Drop the table with
  the database analyser.
- **The CLI processing lane is gone.** `webconsulting-ai-chat:process` and
  `webconsulting-ai-chat:worker` are removed; a turn runs in the request that
  asked for it. Remove any scheduler task or systemd unit that runs them.
- **The optional Flue workflow lane is removed**, with its extension
  configuration (`enableFlue`, `flueFlowUid`) and its routes.
- **The legacy frontend is removed**: the Lit chat UI, the vendored
  `marked`/`DOMPurify` import-map entries, the assistant-ui operator bundle and
  their Jest/Playwright suites. The 2.0 UI is a React/shadcn bundle mounting
  `<wc-ai-chat>` in a Shadow DOM, built with Vite and committed to
  `Resources/Public/JavaScript/Dist/app.js`. `Configuration/JavaScriptModules.php`
  publishes one import specifier and no libraries, so an extension that relied
  on this one to provide `marked` or `dompurify` must bundle its own.
- **`webconsulting-ai-chat:migrate-nr-mcp-agent` is removed. Data migration
  from `nr_mcp_agent` is only available in the 1.x line.** A site still holding
  nr-mcp-agent data must run that migration on 1.x *before* upgrading to 2.0.0.
- The conversation table is slimmed (`messages`, `execution_trace`,
  `flue_run_uid`, `current_request_id` are gone; `auto_approve_tools` and
  `last_message_at` are new) and its status enum drops from seven values to
  four: `idle`, `processing`, `awaiting_approval`, `failed`.
- The API routes are renamed and every mutating route is POST. See
  `Documentation/Developer/Api.rst`.

### Added

- A React/shadcn interface in a Shadow DOM, as two surfaces from one bundle: a
  resizable toolbar panel that survives module navigation, and a three-column
  module with a conversation list, the thread and an activity rail. Streaming
  assistant messages, tool cards carrying the call's effect, an approval card
  bound to the turn digest, markdown through `rehype-sanitize`, and the
  backend's light/dark setting mirrored onto the shadow host.
- MCP tool projection into nr-llm (`nr_llm.tool_provider`): one tool per
  catalogue entry, named `typo3_<McpName>`, in the group `typo3_mcp`. Effect,
  data class and admin-only status are derived from the tool's own MCP
  annotations and the capability manifest — never from configuration.
- Human approval for every write, bound to the reviewed turn by nr-llm's turn
  digest, with an explicit deny path that reaches the transcript.
- Server-sent event streaming for a turn, negotiated on the same route that
  starts it (`Accept: text/event-stream`), with the identical event list
  returned as JSON otherwise.
- `tx_webconsultingaichat_message`: one row per message, with
  `UNIQUE (conversation, sequence)`.
- Upgrade wizard `webconsultingAiChat_messagesToRows`, converting 1.x transcript
  blobs into message rows. Idempotent; empties but does not drop the legacy
  column.
- Per-user turn rate limiting (`turnsPerMinute`), attachment retention
  (`attachmentRetentionDays`), a configurable `uploadFolder` and per-user tool
  narrowing through user TSconfig (`tx_webconsultingaichat.tools.allow|deny`).
- `conversations/events` reads a run's execution trace back from nr-llm, so the
  trace is not duplicated into this extension's own tables.
- ADR-015 (native in-process MCP tool execution), ADR-016 (shadcn chat UI in a
  Shadow DOM) and ADR-017 (server-sent events for turn streaming). ADR-001,
  -002, -003, -007, -008, -012 and -014 are marked superseded; ADR-006's phpat
  enforcement no longer applies.

### Changed

- Require `netresearch/nr-llm` ^0.34.
- `webconsulting-ai-chat:cleanup` gains a pass that releases conversations left
  claimed by a request that died — nothing else can, so it should be scheduled
  daily — and now deletes messages and uploaded files with the conversation.
- The dev toolchain is reduced to what CI runs: phpstan (level 10, one root
  `phpstan.neon`), php-cs-fixer with `typo3/coding-standards`, phpunit and the
  testing framework. captainhook, infection, rector and phpat are removed with
  their configs, the Makefile and the Docker test runner.
- CI is a single self-contained workflow: lint, coding standards, PHPStan, unit
  tests on PHP 8.4 and 8.5 (allowed to fail), functional tests against MariaDB
  10.11.

## [0.7.0] - 2026-07-24

### Changed
- Require `nr-llm` `^0.25` (raised from `^0.23.1`). The agent run request now carries the full acting identity: `AgentRunRequest` takes a required `AiActorContext` instead of a bare `beUserUid`. `ChatService` sources the actor from the live backend user the worker commands already initialise, preserving the exact backend-user authorization (admin flag + groups) that the previous `beUserUid` gave — never a scopeless service account.

### Note
- nr-llm 0.25 flips the tool data-class gate default to `enforce` for fresh installs; some of nr-llm's builtin backend tools may be withheld from the model on configurations whose trust zone is below the tool's data class. Upgraded sites are pinned to `observe` by nr-llm's `DataClassEnforcementDefaultUpdateWizard` and stay unchanged until the operator opts in. Run the nr-llm upgrade wizard and DB schema update after upgrading.

## [0.6.0] - 2026-07-19

### Changed
- Require `nr-llm` `^0.22` (drops support for nr-llm 0.12–0.19). No code changes: every consumed nr-llm symbol (`ProviderAdapterRegistryInterface`, the `Provider\Contract` interfaces, `CompletionResponse`, `ToolSpec`, `ToolCall`, `Model`) is unchanged across 0.20–0.22.

## [0.5.0] - 2026-06-12

### Added
- FAL file picker: users can now select existing TYPO3 FAL files as chat attachments via the TYPO3 Element Browser, in addition to uploading new files
- New backend endpoint `GET /ai-chat/file-info` resolves FAL file metadata (name, MIME type, size) by UID
- Integrated AI chat module in the TYPO3 backend (Admin Tools > AI Chat)
- Floating chat panel in the backend toolbar, persistent across module navigation
- Conversation history with resume, pin, and auto-archive support
- Background processing via CLI commands (`webconsulting-ai-chat:process`, `webconsulting-ai-chat:worker`)
- MCP (Model Context Protocol) integration for TYPO3 content management tools
- File/image upload support with per-provider capability detection (PNG, JPEG, WebP)
- PDF attachment support for providers implementing `DocumentCapableInterface` (Claude, Gemini); file picker accept filter is set dynamically per provider
- Document text extraction fallback: PDF, DOCX, TXT, and XLSX files can now be uploaded as chat attachments regardless of LLM provider. Text is extracted server-side using smalot/pdfparser (PDF) and phpoffice/phpword (DOCX). XLSX support is optional via phpoffice/phpspreadsheet.
- Group-based access control and concurrency caps
- Sanitized error messages (API keys and URLs are redacted)
- Transient error retry logic (429, 503, overloaded) with configurable backoff
- Architecture layer enforcement via phpat tests
- Markdown rendering for LLM responses in the chat UI: headings, lists, code blocks, tables, blockquotes, and inline formatting are rendered via vendored marked.js v15 and DOMPurify v3 (no build step; XSS-safe)
- JavaScript unit test suite (Jest) covering markdown rendering and XSS sanitization

### Changed
- nr-llm dependency raised to `^0.12.0`: tool definitions are converted to typed `ToolSpec` value objects before each provider call, and `ToolCall` responses are normalised back to the legacy wire shape before persisting — conversations store tool calls as JSON and resumed conversations replay plain arrays
- `ChatService` and unit tests depend on `ProviderAdapterRegistryInterface` (`ProviderAdapterRegistry` became `final` in nr-llm 0.12)
- CI test matrix re-resolves the full dependency tree for the older TYPO3 branch instead of a partial `composer require -W` downgrade
- Chat `sendMessage` endpoint now accepts any FAL file the backend user has read permission for, not only files previously uploaded via the chat upload endpoint
