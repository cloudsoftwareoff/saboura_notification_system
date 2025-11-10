<?php
/**
 * Notification System Utilities
 * Shared functions used across notification system components
 */

/**
 * Update job heartbeat in system_jobs table
 * 
 * @param PDO $pdo Database connection
 * @param string $job_code Unique job identifier
 * @param string $status Job status (OK, WARNING, ERROR)
 * @param string $details Job execution details
 */
function updateJobHeartbeat($pdo, $job_code, $status, $details) {
    $now = date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("
        INSERT INTO system_jobs (job_code, last_run_at, status, details, updated_at) 
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            last_run_at = VALUES(last_run_at),
            status = VALUES(status),
            details = VALUES(details),
            updated_at = VALUES(updated_at)
    ");
    
    $stmt->execute([$job_code, $now, $status, $details, $now]);
}

/**
 * Log notification system activity
 * 
 * @param string $message Log message
 * @param string $level Log level (INFO, WARNING, ERROR)
 */
function logNotificationActivity($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Write to standard output
    echo $log_message;
    
    // Optionally write to file
    $log_file = __DIR__ . '/logs/notification_system.log';
    if (is_dir(__DIR__ . '/logs')) {
        file_put_contents($log_file, $log_message, FILE_APPEND);
    }
}

/**
 * Validate email address
 * 
 * @param string $email Email to validate
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Format time ago (e.g., "2 hours ago")
 * 
 * @param string $datetime DateTime string
 * @return string
 */
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('Y-m-d H:i', $timestamp);
    }
}