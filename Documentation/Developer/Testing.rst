..  include:: /Includes.rst.txt

..  _developer-testing:

=======
Testing
=======

Running the suite
=================

..  code-block:: bash

    composer ci:tests:unit          # no database
    composer ci:tests:functional    # sqlite by default
    composer ci:tests

    composer ci:phpstan             # level 10, one root phpstan.neon
    composer ci:cgl                 # dry run
    composer fix:cgl                # apply

CI runs the functional suite against MariaDB 10.11, because that is what
production runs. The switch is environment only — PHPUnit's ``<env>`` entries do
not override an existing environment variable, so setting ``typo3DatabaseDriver``
and friends in the shell points the same suite at a real database:

..  code-block:: bash

    typo3DatabaseDriver=mysqli typo3DatabaseName=func_test \
    typo3DatabaseUsername=root typo3DatabasePassword=funcp \
    typo3DatabaseHost=127.0.0.1 composer ci:tests:functional

The scripted provider
=====================

Testing an agent loop needs a model that can be made to call a tool, then be
asked again, then answer — deterministically, in order, with no network.

:php:`Webconsulting\Typo3AiChat\Testing\ScriptedProvider` is a real nr-llm
provider adapter driven by a queue:

..  code-block:: php

    ScriptedProvider::script([
        ['toolCalls' => [['id' => 'call-1', 'name' => 'typo3_GetPage', 'arguments' => ['uid' => 1]]]],
        ['content' => 'Page 1 is called "Home".'],
    ]);

Running out of scripted responses is an **error**, not an empty answer: a loop
that took one more round than the test scripted has changed behaviour, and
returning ``""`` would let that change pass as a passing test.

The queue is a file rather than a static property, because a functional test
rebuilds the container and takes any in-memory state with it.

Why it is safe to ship
----------------------

An LLM that says whatever a file tells it to is a way to put words in the
assistant's mouth, so it is registered from ``Configuration/Services.php`` behind
**two independent conditions**: a non-production application context **and**
``WEBCONSULTING_AI_CHAT_SCRIPTED_PROVIDER=1``. Either alone is the kind of switch
that gets left on by accident.

It is also not a replacement for a real provider adapter: it never reaches
nr-llm's provider registry unless a provider record explicitly names the adapter
type ``scripted``.

What the suites cover
=====================

**Unit** — the places where a mistake is invisible rather than loud:

-   ``ToolEffectClassifierTest`` — the two fail-safe directions side by side: an
    *empty* declaration is read-only, an *unknown* subsystem is a write. Against
    a real ``CapabilityManifestService`` over a temp manifest, because a double
    would happily agree with a mistaken assumption about the YAML shape.
-   ``McpCatalogToolTest`` — result mapping, and the ambient-identity refusal
    that makes the whole bridge safe.
-   ``RunOutcomeMapperTest`` — every enum case, plus a check that walks
    ``AgentRunOutcome::cases()`` and fails when nr-llm adds one.
-   ``TranscriptBuilderTest`` — the window boundary, where a cut round-trip
    produces a request the *provider* rejects: a failure with no local symptom.
-   ``ToolAccessServiceTest`` — deny beating allow, and absent-vs-empty.
-   ``ServerSentEventStreamTest`` — the wire format, where a missing blank line
    silently buffers and a raw newline silently truncates.

**Functional** — against the installation's real MCP catalogue:

-   the projection: ``typo3_GetPage`` read-only, ``typo3_WriteTable`` a write
    that is off by default, ``typo3_SafeCli`` admin-only;
-   a scripted tool call executing and landing in the transcript as both halves
    of the round-trip;
-   a write suspending, the approve path completing it, the deny path refusing
    it into the transcript, and a decision without a digest being refused;
-   the eleventh turn in a minute being refused, without persisting anything;
-   the upgrade wizard converting a 1.x transcript, twice, without duplicating.

Writing a functional test
=========================

Extend ``AbstractChatFunctionalTestCase``. It sets the scripted provider's
environment flag **before** ``parent::setUp()`` — that is when the container is
compiled and ``Configuration/Services.php`` reads it — loads the nr-llm and MCP
fixtures, and flushes the rate limiter's cache, which is not part of the
per-test database reset.

Call ``$this->signIn()`` in your own ``setUp()``. It is not a convenience: the
MCP tools read the ambient backend user, and a test that signs nobody in
exercises only the refusal path.
