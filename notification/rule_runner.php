<?php
/**
 * Rule Runner - Cron job to execute scheduled SQL rules
 * 
 * Run this every 10 minutes via crontab:
 * * /10 * * * * notification/rule_runner.php
 */

require_once 'config/database.php'; // Your DB connection
require_once 'NotificationEngine.php';

// Create DB connection
$pdo = getDatabaseConnection(); // Your connection function

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
        echo "Processing rule: {$rule['code']}\n";
        
        try {
            // Execute the detection SQL
            $detection_stmt = $pdo->query($rule['detection_sql']);
            
            while ($row = $detection_stmt->fetch(PDO::FETCH_ASSOC)) {
                // Expected columns: entity_id, recipient_user_id, context_json
                // Optional: custom_title, custom_body
                
                $entity_id = $row['entity_id'];
                $recipient_user_id = $row['recipient_user_id'];
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
            echo "Error in rule {$rule['code']}: {$e->getMessage()}\n";
            updateJobHeartbeat($pdo, 'RULE_RUNNER', 'ERROR', "Error in rule {$rule['code']}: {$e->getMessage()}");
        }
    }
    
    $message = "Processed {$rules_processed} rules, raised {$issues_raised} issues";
    echo $message . "\n";
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', $message);
    
} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'ERROR', $e->getMessage());
}

/**
 * Update job heartbeat in system_jobs table
 */
function updateJobHeartbeat($pdo, $job_code, $status, $details) {
    $now = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("
        INSERT INTO system_jobs (job_code, last_run_at, status, details) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            last_run_at = VALUES(last_run_at),
            status = VALUES(status),
            details = VALUES(details)
    ");
    $stmt->execute([$job_code, $now, $status, $details]);
}


?>