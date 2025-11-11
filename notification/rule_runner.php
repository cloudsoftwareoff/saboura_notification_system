<?php
/**
 * Rule Runner - Executes SCHEDULED_SQL rules using DATABASE last_run tracking
 * Run every minute via crontab
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationEngine.php';
require_once __DIR__ . '/notification_utils.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../audit_log.php';
use Cron\CronExpression;

date_default_timezone_set('Africa/Tunis'); 

$pdo = getDatabaseConnection();
$engine = new NotificationEngine($pdo);

// BLOCK BROWSER ACCESS
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_X_CRON_AUTH'])) {
    http_response_code(403);
    die('Forbidden. Cron only.');
}

updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', 'Starting scheduled rule check');

$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Tunis'));
$nowTs = $now->getTimestamp();

try {
    $stmt = $pdo->prepare("
        SELECT * FROM notification_rules 
        WHERE condition_type = 'SCHEDULED_SQL' 
          AND is_active = 1 
          AND schedule_expression IS NOT NULL 
          AND schedule_expression != ''
    ");
    $stmt->execute();

    $rules_processed = 0;
    $issues_raised = 0;
    $rules_triggered = 0;

    while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ruleId = $rule['id'];
        $code = $rule['code'];

        logNotificationActivity("Checking rule: {$code} | Cron: {$rule['schedule_expression']}");

        try {
            $cron = CronExpression::factory($rule['schedule_expression']);
            $lastDue = $cron->getPreviousRunDate($now, 0, true);
            $lastDueTs = $lastDue->getTimestamp();

            // Get DB state
            $lastExecutedTs = $rule['last_due_ts'] ? (int)$rule['last_due_ts'] : 0;

            // Cooldown check
            $cooldownSec = ($rule['cooldown_minutes'] ?? 60) * 60;
            $tooSoon = (time() - $lastExecutedTs) < $cooldownSec;

            if ($lastDueTs <= $lastExecutedTs || $tooSoon) {
                logNotificationActivity("Rule {$code}: not due or in cooldown", 'INFO');
                continue;
            }

            logNotificationActivity("Rule {$code}: DUE → executing detection SQL", 'INFO');

            // === RUN DETECTION SQL ===
            $detection_stmt = $pdo->prepare($rule['detection_sql']);
            $detection_stmt->execute();

            $rowCount = 0;
            while ($row = $detection_stmt->fetch(PDO::FETCH_ASSOC)) {
                $rowCount++;
                $entity_id = $row['entity_id'] ?? null;
                $recipient_user_id = $row['recipient_user_id'] ?? null;
                $context_json = $row['context_json'] ?? '{}';
                $custom_title = $row['custom_title'] ?? null;
                $custom_body = $row['custom_body'] ?? null;

                if (!$entity_id) continue;

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

            // === UPDATE DB: Mark as executed ===
            $updateStmt = $pdo->prepare("
                UPDATE notification_rules 
                SET last_executed_at = NOW(),
                    last_due_ts = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$lastDueTs, $ruleId]);

            $rules_triggered++;
            logNotificationActivity("Rule {$code}: EXECUTED → {$rowCount} rows processed", 'INFO');

        } catch (Exception $e) {
            $msg = "Error in rule {$code}: " . $e->getMessage();
            logNotificationActivity($msg, 'ERROR');
            updateJobHeartbeat($pdo, 'RULE_RUNNER', 'WARNING', $msg);
        }
        $rules_processed++;
    }

    $summary = "Checked: {$rules_processed} | Triggered: {$rules_triggered} | Issues: {$issues_raised}";
    logNotificationActivity($summary, 'INFO');
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', $summary);

} catch (Throwable $e) {
    $msg = "FATAL: " . $e->getMessage();
    logNotificationActivity($msg, 'ERROR');
    updateJobHeartbeat($pdo, 'RULE_RUNNER', 'ERROR', $msg);
    exit(1);
}