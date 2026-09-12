..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Set these under **Admin Tools > Settings > Extension Configuration >
webconsulting_ai_chat**.

Which LLM
=========

..  confval:: llmTaskUid
    :type: int
    :default: 0

    The nr-llm Task the chat runs on. Its configuration selects the provider
    and model; its prompt template becomes the chat's system instruction.

    Required. While it is 0 the toolbar button is hidden and the chat reports
    itself unavailable rather than guessing a provider.

..  confval:: maxIterations
    :type: int
    :default: 8

    How many times within one turn the model may call tools and be asked again.

    This is the answer to "how much work may one question cause?". Too low and
    a multi-step task stops half-finished; too high and a confused model can
    spend a lot of money being confused. nr-llm clamps it to its own ceiling.

Who may use it
==============

..  confval:: allowedGroups
    :type: string
    :default: (empty)

    Comma-separated backend user group UIDs. Empty means every backend user.

    Administrators are never locked out by this, consistently with every other
    surface in the extension.

..  confval:: turnsPerMinute
    :type: int
    :default: 10

    How many turns one user may start per minute; 0 disables the limit.

    Per **user**, not per conversation — every turn is a paid provider call, and
    opening a second conversation must not double the spend. The
    one-turn-per-conversation rule is a separate mechanism and is not
    configurable.

..  confval:: maxConversationsPerUser
    :type: int
    :default: 50

    Upper bound on conversations one user may keep, archived ones included.
    0 means unlimited.

..  confval:: maxActiveConversationsPerUser
    :type: int
    :default: 3

    How many of a user's conversations may be running or awaiting a decision at
    once. 0 means unlimited.

Limits on what is sent
======================

..  confval:: maxMessageLength
    :type: int
    :default: 10000

    Longest message a user may send, in characters. 0 means unlimited.

Attachments
===========

..  confval:: uploadFolder
    :type: string
    :default: 1:/ai_chat/

    FAL combined identifier for the folder attachments are stored under. Each
    conversation gets ``<uploadFolder>/<be_user>/<conversation>/``.

    Both halves of that path earn their place: retention deletes one
    conversation's files without touching another's, and an administrator
    looking at an upload can see whose it was from the path alone.

Retention
=========

Both of these are applied by ``webconsulting-ai-chat:cleanup``, which is worth
running daily as a scheduler task. The same command also releases conversations
left claimed by a request that died — nothing else can, so an installation that
never runs it will eventually have a conversation nobody can continue.

..  confval:: autoArchiveDays
    :type: int
    :default: 30

    Archive conversations nobody has touched for this many days. 0 never
    archives.

..  confval:: attachmentRetentionDays
    :type: int
    :default: 90

    Permanently delete archived and deleted conversations — with their messages
    and their uploaded files — after this many days. 0 keeps them forever.

Per-user tool access
====================

Narrowing the tool set for a particular user or group is done in TSconfig, not
here.

..  toctree::
    :maxdepth: 1

    ToolAccess
