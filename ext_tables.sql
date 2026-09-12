CREATE TABLE tx_webconsultingaichat_conversation (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    deleted smallint(5) unsigned DEFAULT 0 NOT NULL,
    be_user int(11) unsigned DEFAULT 0 NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    message_count int(11) unsigned DEFAULT 0 NOT NULL,
    status varchar(20) DEFAULT 'idle' NOT NULL,
    run_uuid varchar(64) DEFAULT '' NOT NULL,
    pending_approval mediumtext,
    system_prompt text,
    auto_approve_tools tinyint(1) unsigned DEFAULT 0 NOT NULL,
    archived tinyint(1) unsigned DEFAULT 0 NOT NULL,
    pinned tinyint(1) unsigned DEFAULT 0 NOT NULL,
    error_message text,
    last_message_at int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY be_user_archived (be_user, archived, last_message_at),
    KEY be_user_status (be_user, status, deleted),
    KEY run_uuid (run_uuid)
);

CREATE TABLE tx_webconsultingaichat_message (
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    conversation int(11) unsigned DEFAULT 0 NOT NULL,
    sequence int(11) unsigned DEFAULT 0 NOT NULL,
    role varchar(16) DEFAULT '' NOT NULL,
    content mediumtext,
    tool_calls json,
    tool_call_id varchar(64) DEFAULT '' NOT NULL,
    attachments json,
    run_uuid varchar(64) DEFAULT '' NOT NULL,
    prompt_tokens int(11) unsigned DEFAULT 0 NOT NULL,
    completion_tokens int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY conversation_sequence (conversation, sequence),
    KEY run_uuid (run_uuid)
);
