<?php
/**
 * Rule Runner - Executes SCHEDULED_SQL rules ONLY when their cron expression is due
 * 
 * Run every minute (or every 5/10 min) via crontab:
 * 
 * * /1 * * * * php /path/to/notification/rule_runner.php >> /var/log/rule_runner.log 2>&1
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/NotificationEngine.php';
require_once __DIR__ . '/notification_utils.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cron\CronExpression;

date_default_timezone_set('Africa/Tunis'); // Tunisia time

$pdo = getDatabaseConnection();
$engine = new NotificationEngine($pdo);

// Heartbeat
updateJobHeartbeat($pdo, 'RULE_RUNNER', 'OK', 'Starting scheduled rule check');

$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Tunis'));
$stateDir = __DIR__ . '/.last_runs';
if (!is_dir($stateDir))
    mkdir($stateDir, 0755, true);

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
        $lastRunFile = "$stateDir/rule_{$ruleId}.txt";

        logNotificationActivity("Checking rule: {$code} | Cron: {$rule['schedule_expression']}");

        try {
            $cron = CronExpression::factory($rule['schedule_expression']);
            $lastDue = $cron->getPreviousRunDate($now, 0, true); // DateTimeImmutable
            $lastDueTs = $lastDue->getTimestamp();

            $lastExecutedTs = file_exists($lastRunFile)
                ? (int) trim(file_get_contents($lastRunFile))
                : 0;

            // Optional: respect cooldown even if cron says run
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

            while ($row = $detection_stmt->fetch(PDO::FETCH_ASSOC)) {
                $entity_id = $row['entity_id'] ?? null;
                $recipient_user_id = $row['recipient_user_id'] ?? null;
                $context_json = $row['context_json'] ?? '{}';
                $custom_title = $row['custom_title'] ?? null;
                $custom_body = $row['custom_body'] ?? null;

                if (!$entity_id)
                    continue;

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

            // Mark as executed
            file_put_contents($lastRunFile, $lastDueTs);
            $rules_triggered++;

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