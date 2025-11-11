CREATE TABLE notification_rules (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    code                   VARCHAR(100) UNIQUE NOT NULL,      -- e.g. 'NO_SESSION_PLANNED'
    name                   VARCHAR(255) NOT NULL,
    description            TEXT,
    severity               ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'WARNING',

    -- How this rule is triggered
    condition_type         ENUM('SCHEDULED_SQL','EVENT_BASED') NOT NULL,
    detection_sql          MEDIUMTEXT NULL,                   -- for SCHEDULED_SQL rules
    schedule_expression    VARCHAR(100) NULL,                 -- e.g. '*/10 * * * *' (optional metadata)

    event_code             VARCHAR(100) NULL,                 -- for EVENT_BASED rules
    event_filter_json      JSON NULL,                         -- optional payload filters

    -- What the rule is about
    entity_type            VARCHAR(50) NOT NULL,              -- 'STUDENT','GROUP','SESSION','PAYMENT', etc.

    -- How to interpret SQL/event output
    entity_id_field        VARCHAR(100) NOT NULL DEFAULT 'entity_id',
    assignee_user_field    VARCHAR(100) NULL,                 -- usually 'recipient_user_id'

    -- Who should get notifications
    target_role            ENUM('ASSISTANT','TEACHER','ADMIN','CEO','MIX') NOT NULL,
    channels_json          JSON NOT NULL,                     -- e.g. '["IN_APP","EMAIL"]'

    -- Templates
    title_template         VARCHAR(255) NOT NULL,
    body_template          MEDIUMTEXT NOT NULL,

    cooldown_minutes       INT NOT NULL DEFAULT 60,
    is_active              TINYINT(1) NOT NULL DEFAULT 1,

    created_at             DATETIME NOT NULL,
    updated_at             DATETIME NOT NULL
);

CREATE TABLE notification_issues (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    rule_id                 INT NOT NULL,
    entity_type             VARCHAR(50) NOT NULL,
    entity_id               INT NOT NULL,
    title                   VARCHAR(255) NOT NULL,
    context_json            JSON NOT NULL,
    severity                ENUM('INFO','WARNING','CRITICAL') NOT NULL,
    status                  ENUM('OPEN','IN_PROGRESS','RESOLVED','IGNORED') NOT NULL DEFAULT 'OPEN',
    assigned_to_user_id     INT NULL,
    first_detected_at       DATETIME NOT NULL,
    last_detected_at        DATETIME NOT NULL,
    resolved_at             DATETIME NULL,
    resolution_notes        TEXT NULL,
    snoozed_until           DATETIME NULL,
    escalation_level        INT NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL,
    updated_at              DATETIME NOT NULL,

    UNIQUE KEY uniq_issue (rule_id, entity_type, entity_id),
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id)
);

CREATE TABLE notifications (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    issue_id                INT NULL,
    rule_id                 INT NOT NULL,
    recipient_user_id       INT NOT NULL,
    channel                 ENUM('IN_APP','EMAIL','WHATSAPP') NOT NULL,
    message_title           VARCHAR(255) NOT NULL,
    message_body            TEXT NOT NULL,
    status                  ENUM('PENDING','SENT','DELIVERED','READ','FAILED') NOT NULL DEFAULT 'PENDING',
    error_message           TEXT NULL,
    sent_at                 DATETIME NULL,
    delivered_at            DATETIME NULL,
    read_at                 DATETIME NULL,
    created_at              DATETIME NOT NULL,
    updated_at              DATETIME NOT NULL,

    FOREIGN KEY (issue_id) REFERENCES notification_issues(id),
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id)
);

CREATE TABLE system_jobs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    job_code        VARCHAR(100) UNIQUE NOT NULL,  -- e.g. 'RULE_RUNNER','NOTIF_DISPATCHER'
    last_run_at     DATETIME NOT NULL,
    status          ENUM('OK','WARNING','ERROR') NOT NULL DEFAULT 'OK',
    details         TEXT NULL,
    updated_at      DATETIME NOT NULL
);
