-- =====================================================
-- Saboura Notification Framework - Database Schema
-- =====================================================

-- 1. Notification Rules Configuration
CREATE TABLE notification_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) UNIQUE NOT NULL COMMENT 'Unique rule identifier',
    name VARCHAR(255) NOT NULL,
    description TEXT,
    severity ENUM('INFO','WARNING','CRITICAL') NOT NULL DEFAULT 'WARNING',
    
    -- Trigger configuration
    condition_type ENUM('SCHEDULED_SQL','EVENT_BASED') NOT NULL,
    detection_sql MEDIUMTEXT NULL COMMENT 'SQL query for scheduled rules',
    schedule_expression VARCHAR(100) NULL COMMENT 'Cron expression (optional metadata)',
    event_code VARCHAR(100) NULL COMMENT 'Event code for event-based rules',
    event_filter_json JSON NULL COMMENT 'Additional payload filters',
    
    -- Entity configuration
    entity_type VARCHAR(50) NOT NULL COMMENT 'STUDENT, GROUP, SESSION, PAYMENT, etc.',
    entity_id_field VARCHAR(100) NOT NULL DEFAULT 'entity_id',
    assignee_user_field VARCHAR(100) NULL COMMENT 'Field name for recipient_user_id',
    
    -- Notification targets
    target_role ENUM('ASSISTANT','TEACHER','ADMIN','CEO','MIX') NOT NULL,
    channels_json JSON NOT NULL COMMENT 'Array of channels: IN_APP, EMAIL, WHATSAPP',
    
    -- Message templates
    title_template VARCHAR(255) NOT NULL,
    body_template MEDIUMTEXT NOT NULL,
    
    -- Control settings
    cooldown_minutes INT NOT NULL DEFAULT 60,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_condition_type (condition_type),
    INDEX idx_event_code (event_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Notification Issues (Tracked Problems/Alerts)
CREATE TABLE notification_issues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    context_json JSON NOT NULL COMMENT 'Additional context data',
    severity ENUM('INFO','WARNING','CRITICAL') NOT NULL,
    status ENUM('OPEN','IN_PROGRESS','RESOLVED','IGNORED') NOT NULL DEFAULT 'OPEN',
    assigned_to_user_id INT NULL,
    
    -- Timing
    first_detected_at DATETIME NOT NULL,
    last_detected_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    resolution_notes TEXT NULL,
    snoozed_until DATETIME NULL,
    
    escalation_level INT NOT NULL DEFAULT 0,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uniq_issue (rule_id, entity_type, entity_id),
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE CASCADE,
    
    INDEX idx_status (status),
    INDEX idx_severity (severity),
    INDEX idx_assigned_to (assigned_to_user_id),
    INDEX idx_snoozed (snoozed_until),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Notifications (Messages Sent to Users)
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_id INT NULL,
    rule_id INT NOT NULL,
    recipient_user_id INT NOT NULL,
    channel ENUM('IN_APP','EMAIL','WHATSAPP') NOT NULL,
    
    message_title VARCHAR(255) NOT NULL,
    message_body TEXT NOT NULL,
    
    status ENUM('PENDING','SENT','DELIVERED','READ','FAILED') NOT NULL DEFAULT 'PENDING',
    error_message TEXT NULL,
    
    -- Timing
    sent_at DATETIME NULL,
    delivered_at DATETIME NULL,
    read_at DATETIME NULL,
    
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (issue_id) REFERENCES notification_issues(id) ON DELETE SET NULL,
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE CASCADE,
    
    INDEX idx_recipient_status (recipient_user_id, status),
    INDEX idx_channel_status (channel, status),
    INDEX idx_read_status (recipient_user_id, read_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. System Jobs (Background Job Heartbeats)
CREATE TABLE system_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_code VARCHAR(100) UNIQUE NOT NULL COMMENT 'RULE_RUNNER, NOTIF_DISPATCHER, etc.',
    last_run_at DATETIME NOT NULL,
    status ENUM('OK','WARNING','ERROR') NOT NULL DEFAULT 'OK',
    details TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_last_run (last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Notification Cooldown Tracking (Helper table)
CREATE TABLE notification_cooldowns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    issue_id INT NOT NULL,
    recipient_user_id INT NOT NULL,
    channel ENUM('IN_APP','EMAIL','WHATSAPP') NOT NULL,
    last_sent_at DATETIME NOT NULL,
    
    UNIQUE KEY uniq_cooldown (rule_id, issue_id, recipient_user_id, channel),
    FOREIGN KEY (rule_id) REFERENCES notification_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (issue_id) REFERENCES notification_issues(id) ON DELETE CASCADE,
    
    INDEX idx_last_sent (last_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(255) NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id)
);