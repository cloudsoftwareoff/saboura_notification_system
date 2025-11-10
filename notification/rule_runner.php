<?php
/**
 * Rule Runner - Cron job to execute scheduled SQL rules
 * 
 * Run this every 10 minutes via crontab:
 * * /10 * * * * php /path/to/notification/rule_runner.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationEngine.php';
require_once __DIR__ . '/notification_utils.php';

// Create DB connection
$pdo = getDatabaseConnection();

// Initialize engine
$engine = new NotificationEngine($pdo);

// Update job heartbeat
updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', 'Starting rule execution');

try {
    // Get all active scheduled SQL rules
    $stmt = $pdo->prepare("
        SELECT * FROM notification_rules 
        WHERE condition_type = 'SCHEDULED_SQL' AND is_active = 1
    ");
    $stmt->execute();
    
    $rules_processed = 0;
    $issues_raised = 0;
    
    while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        logNotificationActivity("Processing rule: {$rule['code']}", 'INFO');
        
        try {
            // Execute the detection SQL
            $detection_stmt = $pdo->query($rule['detection_sql']);
            
            while ($row = $detection_stmt->fetch(PDO::FETCH_ASSOC)) {
                // Expected columns: entity_id, recipient_user_id, context_json
                // Optional: custom_title, custom_body
                
                $entity_id = $row['entity_id'];
                $recipient_user_id = $row['recipient_user_id'] ?? null;
                $context_json = $row['context_json'];
                $custom_title = $row['custom_title'] ?? null;
                $custom_body = $row['custom_body'] ?? null;
                
                // Raise the issue
                $engine->raiseIssue(
                    $rule,
                    $entity_id,
                    $recipient_user_id,
                    $context_json,
                    $custom_title,
                    $custom_body
                );
                
                $issues_raised++;
            }
            
            $rules_processed++;
            
        } catch (Exception $e) {
            $error_msg = "Error in rule {$rule['code']}: {$e->getMessage()}";
            logNotificationActivity($error_msg, 'ERROR');
            updateJobHeartbeat($pdo, 'RULE_RUNNER', 'WARNING', $error_msg);
        }
    }
    
    $message = "Processed {$rules_processed} rules, raised {$issues_raised} issues";
    logNotificationActivity($message, 'INFO');
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', $message);
    
} catch (Exception $e) {
    $error_msg = "Fatal error: {$e->getMessage()}";
    logNotificationActivity($error_msg, 'ERROR');
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'ERROR', $error_msg);
    exit(1);
}