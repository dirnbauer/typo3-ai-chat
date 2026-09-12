..  include:: /Includes.rst.txt

.. _adrs:

=============================
Architecture decision records
=============================

Architecture Decision Records (ADRs) document the key design choices made
during development, including the context, alternatives considered, and
consequences of each decision.

A superseded record is kept, not edited: it says what was true when it was
written, and the record that replaced it says why that stopped being true.
2.0 superseded seven of them at once, because moving the tools in-process
(:ref:`ADR-015 <adr-015>`) removed the reason most of the 1.x architecture
existed.

..  toctree::
    :maxdepth: 1

    ADR-001-embedded-mcp-client-in-typo3-backend
    ADR-002-cli-based-message-processing
    ADR-003-mcp-integration-via-stdio-subprocess
    ADR-004-nr-llm-as-llm-abstraction-layer
    ADR-005-persistent-conversation-model-with-state-machine
    ADR-006-layered-architecture-with-phpat-enforcement
    ADR-007-polling-over-websockets-or-sse
    ADR-008-lit-web-components-without-build-step
    ADR-009-group-based-access-control
    ADR-010-llm-error-message-sanitization
    ADR-011-floating-panel-outside-module-iframe
    ADR-012-markdown-rendering-with-marked-and-dompurify
    ADR-013-server-side-document-text-extraction-fallback
    ADR-014-configurable-mcp-server-registry
    ADR-015-native-in-process-mcp-tool-execution
    ADR-016-shadcn-chat-ui-in-shadow-dom
    ADR-017-server-sent-events-for-turn-streaming
