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
        
        // Parse context_json if it's a string
        $context = is_string($context_json) ? json_decode($context_json, true) : $context_json;
        
        // Find existing issue
        $stmt = $this->pdo->prepare("
            SELECT * FROM notification_issues 
            WHERE rule_id = ? AND entity_type = ? AND entity_id = ?
        ");
        $stmt->execute([$rule['id'], $entity_type, $entity_id]);
        $issue = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($issue) {
            // Check if snoozed
            if ($issue['snoozed_until'] && $issue['snoozed_until'] > $now) {
                // Still snoozed, just update last_detected_at
                $this->pdo->prepare("
                    UPDATE notification_issues 
                    SET last_detected_at = ?, updated_at = ?
                    WHERE id = ?
                ")->execute([$now, $now, $issue['id']]);
                return;
            }
            
            // Update existing issue
            $this->pdo->prepare("
                UPDATE notification_issues 
                SET last_detected_at = ?, updated_at = ?
                WHERE id = ?
            ")->execute([$now, $now, $issue['id']]);
            
        } else {
            // Create new issue
            $title = $custom_title ?? $this->renderTemplate($rule['title_template'], $context);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notification_issues (
                    rule_id, entity_type, entity_id, title, context_json,
                    severity, status, assigned_to_user_id,
                    first_detected_at, last_detected_at,
                    created_at, updated_at
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
            
            $issue = [
                'id' => $this->pdo->lastInsertId(),
                'rule_id' => $rule['id'],
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'title' => $title,
                'context_json' => json_encode($context),
                'severity' => $rule['severity'],
                'status' => 'OPEN',
                'assigned_to_user_id' => $recipient_user_id
            ];
        }
        
        // Check if we should send notifications (cooldown check)
        if ($this->shouldSendNotifications($rule, $issue, $recipient_user_id)) {
            $this->createNotifications($rule, $issue, $recipient_user_id, $context, $custom_title, $custom_body);
        }
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
        $now = date('Y-m-d H:i:s');
        
        // Resolve recipients based on target_role
        $recipients = $this->resolveRecipients($rule, $recipient_user_id);
        
        // Render messages
        $title = $custom_title ?? $this->renderTemplate($rule['title_template'], $context);
        $body = $custom_body ?? $this->renderTemplate($rule['body_template'], $context);
        
        // Parse channels
        $channels = json_decode($rule['channels_json'], true);
        
        foreach ($recipients as $user_id) {
            foreach ($channels as $channel) {
                // Double-check cooldown per recipient and channel
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) as count
                    FROM notifications
                    WHERE issue_id = ? 
                      AND recipient_user_id = ?
                      AND channel = ?
                      AND created_at > NOW() - INTERVAL ? MINUTE
                ");
                $stmt->execute([$issue['id'], $user_id, $channel, $rule['cooldown_minutes']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    continue; // Skip if already sent recently
                }
                
                // Create notification
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (
                        issue_id, rule_id, recipient_user_id, channel,
                        message_title, message_body, status,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
                ");
                
                $stmt->execute([
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


