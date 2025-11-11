<?php
/**
 * NotificationEngine - Core logic for raising issues and creating notifications
 */
class NotificationEngine
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Main entry point for raising an issue from a rule
     */
    public function raiseIssue($rule, $entity_id, $recipient_user_id, $context_json, $custom_title = null, $custom_body = null)
    {
        $now = date('Y-m-d H:i:s');
        $entity_type = $rule['entity_type'];

        // Parse context_json
        if (is_string($context_json)) {
            $context = json_decode($context_json, true) ?? [];
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON decode error for rule {$rule['code']}: " . json_last_error_msg());
                $context = [];
            }
        } else {
            $context = $context_json ?? [];
        }

        // Find existing issue
        $stmt = $this->pdo->prepare("
        SELECT * FROM notification_issues 
        WHERE rule_id = ? AND entity_type = ? AND entity_id = ?
    ");
        $stmt->execute([$rule['id'], $entity_type, $entity_id]);
        $issue = $stmt->fetch(PDO::FETCH_ASSOC);

        $title = $custom_title ?? $this->renderTemplate($rule['title_template'], $context);

        if ($issue) {
            // Update existing issue
            if ($issue['snoozed_until'] && $issue['snoozed_until'] > $now) {
                $this->pdo->prepare("
                UPDATE notification_issues 
                SET last_detected_at = ?, updated_at = ?, context_json = ?
                WHERE id = ?
            ")->execute([$now, $now, json_encode($context), $issue['id']]);
                return; // Don't send notification if snoozed
            }

            $this->pdo->prepare("
            UPDATE notification_issues 
            SET last_detected_at = ?, updated_at = ?, title = ?, context_json = ?
            WHERE id = ?
        ")->execute([$now, $now, $title, json_encode($context), $issue['id']]);

        } else {
            // Create new issue
            $stmt = $this->pdo->prepare("
            INSERT INTO notification_issues (
                rule_id, entity_type, entity_id, title, context_json,
                severity, status, assigned_to_user_id,
                first_detected_at, last_detected_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'OPEN', ?, ?, ?, ?, ?)
        ");
            $stmt->execute([
                $rule['id'],
                $entity_type,
                $entity_id,
                $title,
                json_encode($context),
                $rule['severity'],
                $recipient_user_id,
                $now,
                $now,
                $now,
                $now
            ]);
            $issue = $this->pdo->query("SELECT * FROM notification_issues WHERE id = LAST_INSERT_ID()")->fetch(PDO::FETCH_ASSOC);
        }


        $this->createNotifications($rule, $issue, $recipient_user_id, $context, $custom_title, $custom_body);
    }


    /**
     * Check if we should send notifications based on cooldown
     */
    private function shouldSendNotifications($rule, $issue, $recipient_user_id)
    {
        $cooldown_minutes = $rule['cooldown_minutes'];

        // Check if we sent a notification to this recipient recently
        $stmt = $this->pdo->prepare("
            SELECT MAX(created_at) as last_sent
            FROM notifications
            WHERE issue_id = ? 
              AND recipient_user_id = ?
              AND created_at > NOW() - INTERVAL ? MINUTE
        ");
        $stmt->execute([$issue['id'], $recipient_user_id, $cooldown_minutes]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no recent notification, we can send
        return empty($result['last_sent']);
    }

    /**
     * Create notifications for all configured channels
     */
    private function createNotifications($rule, $issue, $recipient_user_id, $context, $custom_title, $custom_body)
    {
        $channels = json_decode($rule['channels_json'], true);
        if (empty($channels))
            return;

        $recipients = $this->resolveRecipients($rule, $recipient_user_id);
        if (empty($recipients))
            return;

        $title = $custom_title ?? $this->renderTemplate($rule['title_template'], $context);
        $body = $custom_body ?? $this->renderTemplate($rule['body_template'], $context);
        $now = date('Y-m-d H:i:s');
        $cooldown = (int) $rule['cooldown_minutes'];

        foreach ($recipients as $user_id) {
            foreach ($channels as $channel) {
                // Check cooldown per user + channel
                $check = $this->pdo->prepare("
                SELECT 1 FROM notifications 
                WHERE issue_id = ? AND recipient_user_id = ? AND channel = ?
                  AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                LIMIT 1
            ");
                $check->execute([$issue['id'], $user_id, $channel, $cooldown]);
                if ($check->fetch())
                    continue; // Already sent recently

                // INSERT NOTIFICATION
                $insert = $this->pdo->prepare("
                INSERT INTO notifications (
                    issue_id, rule_id, recipient_user_id, channel,
                    message_title, message_body, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
            ");
                $insert->execute([
                    $issue['id'],
                    $rule['id'],
                    $user_id,
                    $channel,
                    $title,
                    $body,
                    $now,
                    $now
                ]);
            }
        }
    }


    /**
     * Resolve which users should receive notifications
     */
    private function resolveRecipients($rule, $recipient_user_id)
    {
        $recipients = [];

        switch ($rule['target_role']) {
            case 'ASSISTANT':
            case 'TEACHER':
                if ($recipient_user_id) {
                    $recipients[] = $recipient_user_id;
                }
                break;

            case 'ADMIN':
                // Get all admin user IDs (adjust this query to your users table structure)
                $stmt = $this->pdo->query("SELECT id FROM users WHERE role = 'ADMIN'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $recipients[] = $row['id'];
                }
                break;

            case 'CEO':
                // Get CEO user IDs
                $stmt = $this->pdo->query("SELECT id FROM users WHERE role = 'CEO'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $recipients[] = $row['id'];
                }
                break;

            case 'MIX':
                // Combine multiple roles
                if ($recipient_user_id) {
                    $recipients[] = $recipient_user_id;
                }
                $stmt = $this->pdo->query("SELECT id FROM users WHERE role IN ('ADMIN', 'CEO')");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $recipients[] = $row['id'];
                }
                break;
        }

        return array_unique($recipients);
    }

    /**
     * Simple template rendering - replaces {{key}} with values from context
     */
    private function renderTemplate($template, $context)
    {
        foreach ($context as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            // Handle null values
            $value = $value ?? '';
            $template = str_replace($placeholder, $value, $template);
        }
        return $template;
    }

    /**
     * Process event-based rules (future use)
     */
    public function processEvent($event_code, $payload)
    {
        // Get all active event-based rules for this event
        $stmt = $this->pdo->prepare("
            SELECT * FROM notification_rules
            WHERE condition_type = 'EVENT_BASED'
              AND event_code = ?
              AND is_active = 1
        ");
        $stmt->execute([$event_code]);

        while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Check event filter if exists
            if ($rule['event_filter_json']) {
                $filters = json_decode($rule['event_filter_json'], true);
                if (!$this->matchesEventFilter($payload, $filters)) {
                    continue;
                }
            }

            // Extract entity_id and assignee from payload
            $entity_id = $payload[$rule['entity_id_field']] ?? null;
            $assignee_user_id = $rule['assignee_user_field']
                ? ($payload[$rule['assignee_user_field']] ?? null)
                : null;

            if (!$entity_id) {
                continue;
            }

            $this->raiseIssue(
                $rule,
                $entity_id,
                $assignee_user_id,
                $payload,
                null,
                null
            );
        }
    }

    /**
     * Check if payload matches event filters
     */
    private function matchesEventFilter($payload, $filters)
    {
        foreach ($filters as $key => $expected_value) {
            if (!isset($payload[$key]) || $payload[$key] != $expected_value) {
                return false;
            }
        }
        return true;
    }
}


