<?php
/**
 * Notification Dispatcher – Sends pending notifications
 * Run every minute: * * * * * notification/notification_dispatcher.php
 */
require_once 'config/database.php';
require_once 'vendor/autoload.php';
require_once 'config/email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pdo = getDatabaseConnection();
updateJobHeartbeat($pdo, 'NOTIF_DISPATCHER', 'OK', 'Starting dispatch');

try {
    $stmt = $pdo->prepare("
        SELECT n.*, u.email
        FROM notifications n
        LEFT JOIN users u ON u.id = n.recipient_user_id
        WHERE n.status = 'PENDING'
        ORDER BY n.created_at ASC
        LIMIT 100
    ");
    $stmt->execute();

    $sent_count = 0;
    $failed_count = 0;
    $mailConfig = include 'config/email.php';

    while ($notification = $stmt->fetch(PDO::FETCH_ASSOC)) {
        try {
            echo "Processing ID {$notification['id']} | {$notification['channel']} | " . ($notification['email'] ?? 'NO EMAIL') . "\n";

            $success = false;

            switch ($notification['channel']) {
                case 'IN_APP':
                    $success = true;
                    break;

                case 'EMAIL':
                    if (empty($notification['email'])) {
                        throw new Exception('Missing recipient email');
                    }
                    $success = sendEmail($notification, $mailConfig);
                    break;

                case 'WHATSAPP':
                    $success = true; // TODO: later
                    break;
            }

            if ($success) {
                $pdo->prepare("UPDATE notifications SET status='SENT', sent_at=NOW() WHERE id=?")
                    ->execute([$notification['id']]);
                $sent_count++;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            echo "FAILED ID {$notification['id']}: $error\n";

            $pdo->prepare("UPDATE notifications SET status='FAILED', error_message=? WHERE id=?")
                ->execute([$error, $notification['id']]);
            $failed_count++;
        }
    }

    $msg = "Sent: $sent_count, Failed: $failed_count";
    echo $msg . PHP_EOL;
    updateJobHeartbeat($pdo, 'NOTIF_DISPATCHER', 'OK', $msg);
} catch (Throwable $e) {
    $msg = "FATAL: " . $e->getMessage();
    echo $msg . PHP_EOL;
    updateJobHeartbeat($pdo, 'NOTIF_DISPATCHER', 'ERROR', $msg);
}

/* ------------------------------------------------------------------ */
function sendEmail(array $n, array $cfg): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = 0; // Set to 2 only for debugging
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $cfg['port'];

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($n['email']);

        $mail->Subject = $n['message_title'];
        $mail->Body    = $n['message_body'];

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        throw new Exception("SMTP Error: " . $mail->ErrorInfo);
    }
}

/* ------------------------------------------------------------------ */
function updateJobHeartbeat($pdo, $job_code, $status, $details) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO system_jobs (job_code, last_run_at, status, details, updated_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            last_run_at = VALUES(last_run_at),
            status = VALUES(status),
            details = VALUES(details),
            updated_at = VALUES(updated_at)
    ")->execute([$job_code, $now, $status, $details, $now]);
}