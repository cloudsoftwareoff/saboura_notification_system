<?php
/**
 * Quick Status Checker - Shows what's happening in the notification system
 */
require_once 'config/database.php';

$pdo = getDatabaseConnection();

echo "\n";
echo "==================================================\n";
echo "  SABOURA NOTIFICATION SYSTEM - STATUS CHECK\n";
echo "==================================================\n\n";

// 1. Check system jobs
echo "📋 SYSTEM JOBS STATUS:\n";
echo str_repeat("-", 50) . "\n";
$stmt = $pdo->query("SELECT * FROM system_jobs ORDER BY last_run_at DESC");
while ($job = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_icon = $job['status'] == 'OK' ? '✅' : '❌';
    echo sprintf(
        "%s %-20s | Last Run: %s | %s\n",
        $status_icon,
        $job['job_code'],
        $job['last_run_at'],
        $job['details'] ?? 'No details'
    );
}
echo "\n";

// 2. Check active rules
echo "📜 ACTIVE RULES:\n";
echo str_repeat("-", 50) . "\n";
$stmt = $pdo->query("
    SELECT code, name, condition_type, is_active 
    FROM notification_rules 
    ORDER BY id
");
$active_count = 0;
$inactive_count = 0;
while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_icon = $rule['is_active'] ? '✅' : '❌';
    echo sprintf(
        "%s %-30s (%s)\n",
        $status_icon,
        $rule['code'],
        $rule['condition_type']
    );
    if ($rule['is_active']) $active_count++;
    else $inactive_count++;
}
echo sprintf("\nTotal: %d active, %d inactive\n\n", $active_count, $inactive_count);

// 3. Check notifications by status
echo "📬 NOTIFICATIONS BY STATUS:\n";
echo str_repeat("-", 50) . "\n";
$stmt = $pdo->query("
    SELECT status, channel, COUNT(*) as count
    FROM notifications
    GROUP BY status, channel
    ORDER BY status, channel
");
$total = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $icon = match($row['status']) {
        'PENDING' => '⏳',
        'SENT' => '✅',
        'FAILED' => '❌',
        'READ' => '👀',
        default => '📌'
    };
    echo sprintf(
        "%s %-12s | %-10s | %d notifications\n",
        $icon,
        $row['status'],
        $row['channel'],
        $row['count']
    );
    $total += $row['count'];
}
echo sprintf("\nTotal notifications: %d\n\n", $total);

// 4. Check recent notifications
echo "🔔 RECENT NOTIFICATIONS (Last 10):\n";
echo str_repeat("-", 50) . "\n";
$stmt = $pdo->query("
    SELECT 
        n.id,
        n.channel,
        n.status,
        n.message_title,
        u.email,
        n.created_at,
        n.error_message
    FROM notifications n
    LEFT JOIN users u ON u.id = n.recipient_user_id
    ORDER BY n.created_at DESC
    LIMIT 10
");

while ($notif = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_icon = match($notif['status']) {
        'PENDING' => '⏳',
        'SENT' => '✅',
        'FAILED' => '❌',
        default => '📌'
    };
    
    echo sprintf(
        "%s [%d] %s | %s | %s\n",
        $status_icon,
        $notif['id'],
        $notif['channel'],
        substr($notif['message_title'], 0, 35),
        $notif['created_at']
    );
    
    if ($notif['channel'] == 'EMAIL') {
        echo sprintf("   └─ To: %s\n", $notif['email'] ?? 'NO EMAIL');
    }
    
    if ($notif['status'] == 'FAILED' && $notif['error_message']) {
        echo sprintf("   └─ Error: %s\n", substr($notif['error_message'], 0, 60));
    }
}
echo "\n";

// 5. Check for issues
echo "⚠️  POTENTIAL ISSUES:\n";
echo str_repeat("-", 50) . "\n";

// Check for pending notifications
$stmt = $pdo->query("SELECT COUNT(*) as count FROM notifications WHERE status = 'PENDING'");
$pending = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
if ($pending > 0) {
    echo "⚠️  $pending notifications are PENDING (should be sent soon)\n";
}

// Check for failed notifications
$stmt = $pdo->query("SELECT COUNT(*) as count FROM notifications WHERE status = 'FAILED'");
$failed = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
if ($failed > 0) {
    echo "❌ $failed notifications FAILED (check error messages)\n";
}

// Check for EMAIL notifications without email addresses
$stmt = $pdo->query("
    SELECT COUNT(*) as count
    FROM notifications n
    LEFT JOIN users u ON u.id = n.recipient_user_id
    WHERE n.channel = 'EMAIL' AND (u.email IS NULL OR u.email = '')
");
$no_email = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
if ($no_email > 0) {
    echo "⚠️  $no_email EMAIL notifications have no recipient email address\n";
}

// Check job heartbeat age
$stmt = $pdo->query("
    SELECT job_code, TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) as minutes_ago
    FROM system_jobs
    WHERE TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) > 5
");
$stale_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($stale_jobs) > 0) {
    foreach ($stale_jobs as $job) {
        echo sprintf(
            "⚠️  Job '%s' hasn't run in %d minutes\n",
            $job['job_code'],
            $job['minutes_ago']
        );
    }
}

if ($pending == 0 && $failed == 0 && $no_email == 0 && count($stale_jobs) == 0) {
    echo "✅ No issues detected!\n";
}

echo "\n";
echo "==================================================\n";
echo "  Check complete - " . date('Y-m-d H:i:s') . "\n";
echo "==================================================\n\n";