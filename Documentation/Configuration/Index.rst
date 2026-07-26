..  include:: /Includes.rst.txt

=============
Configuration
=============

Configure the extension under **Admin Tools > Settings > Extension
Configuration > webconsulting_ai_chat**.

LLM and processing
==================

..  confval:: llmTaskUid
    :type: int
    :default: 0

    UID of the nr-llm Task used by the direct operator lane. Its Configuration
    selects the provider/model and its prompt becomes part of the agent
    instruction stack.

..  confval:: processingStrategy
    :type: string
    :default: exec

    ``exec`` starts a bounded CLI process per turn. ``worker`` uses the
    long-running ``ai-chat:worker`` command.

..  confval:: enableMcp
    :type: boolean
    :default: false

    Retained for MCP server records and compatibility. Interactive execution is
    governed by nr-llm's tool registry; the optional Flue lane is the durable
    MCP execution path.

Flue
====

..  confval:: enableFlue
    :type: boolean
    :default: false

    Shows the Flue lane only when ``webconsulting/flue`` is installed.

..  confval:: flueFlowUid
    :type: int
    :default: 0

    UID of the Flue flow triggered by chat requests. Use a narrow flow whose MCP
    allowlist matches the desired task. Write-capable flows must use Flue's
    strict-sandbox backend actor so changes land in a draft workspace.

Access and limits
=================

..  confval:: allowedGroups
    :type: string
    :default: *(empty)*

    Comma-separated backend group UIDs. Empty means every user with module
    access; individual tools still enforce TYPO3 permissions.

..  confval:: maxMessageLength
    :type: int
    :default: 10000

    Maximum characters in one request.

..  confval:: maxActiveConversationsPerUser
    :type: int
    :default: 3

    Maximum direct/Flue runs active for one backend user.

..  confval:: maxConversationsPerUser
    :type: int
    :default: 50

    Conversation retention limit. ``0`` disables the limit.

..  confval:: autoArchiveDays
    :type: int
    :default: 30

    Age after which unpinned conversations are archived by ``ai-chat:cleanup``.

Attachment security
===================

The endpoint accepts actual MIME types supported by the provider or a registered
server-side extractor. Client Content-Type is never trusted. Uploads are capped
at 20 MB, stored in a per-user FAL folder and expanded only for the LLM call.
Persisted messages contain FAL UIDs, never base64 file bodies.

Approval state
==============

nr-llm stores the authoritative suspended run. The chat mirrors its run UUID
and pending tool names/arguments, and resumes it through
``AgentRuntime::approve``. A conversation owner can decide the run; a second
decision cannot execute the same call twice because nr-llm claims it atomically.

Credits
-------

Thank you to Netresearch for nr-mcp-agent, nr-llm and nr-vault. The derived
extension preserves the upstream history, GPL license, copyright headers and
visible attribution.
