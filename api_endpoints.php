<?php
/**
 * API Endpoints - Simple REST API for notifications
 * Usage: /api_endpoints.php?action=get_unread_count
 */

require_once 'config/database.php';

header('Content-Type: application/json');

$pdo = getDatabaseConnection();
$currentUserId = getCurrentUserId();

// Get action from GET or POST
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        
        // ============================================
        // GET UNREAD COUNT
        // ============================================
        case 'get_unread_count':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM notifications 
                WHERE recipient_user_id = ? 
                  AND status != 'READ'
            ");
            $stmt->execute([$currentUserId]);
            $result = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'count' => (int)$result['count']
            ]);
            break;
        
        // ============================================
        // GET RECENT NOTIFICATIONS (last 10)
        // ============================================
        case 'get_recent_notifications':
            $limit = (int)($_GET['limit'] ?? 10);
            
            $stmt = $pdo->prepare("
                SELECT 
                    n.id,
                    n.message_title,
                    n.message_body,
                    n.status,
                    n.created_at,
                    nr.severity,
                    ni.entity_type,
                    ni.entity_id
                FROM notifications n
                JOIN notification_rules nr ON nr.id = n.rule_id
                LEFT JOIN notification_issues ni ON ni.id = n.issue_id
                WHERE n.recipient_user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$currentUserId, $limit]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications
            ]);
            break;
        
        // ============================================
        // MARK NOTIFICATION AS READ
        // ============================================
        case 'mark_as_read':
            $notifId = (int)$_POST['notification_id'];
            
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET status = 'READ', read_at = NOW(), updated_at = NOW()
                WHERE id = ? AND recipient_user_id = ?
            ");
            $stmt->execute([$notifId, $currentUserId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
            break;
        
        // ============================================
        // MARK ALL AS READ
        // ============================================
        case 'mark_all_read':
            $stmt = $pdo->prepare("
                UPDATE notifications 
                SET status = 'READ', read_at = NOW(), updated_at = NOW()
                WHERE recipient_user_id = ? AND status != 'READ'
            ");
            $stmt->execute([$currentUserId]);
            $affected = $stmt->rowCount();
            
            echo json_encode([
                'success' => true,
                'message' => "$affected notifications marked as read"
            ]);
            break;
        
        // ============================================
        // UPDATE ISSUE STATUS (Admin only)
        // ============================================
        case 'update_issue_status':
            // TODO: Add admin check here
            $issueId = (int)$_POST['issue_id'];
            $status = $_POST['status'];
            $notes = $_POST['notes'] ?? null;
            
            // Validate status
            $validStatuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'IGNORED'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception('Invalid status');
            }
            
            $stmt = $pdo->prepare("
                UPDATE notification_issues 
                SET status = ?, 
                    resolution_notes = COALESCE(?, resolution_notes),
                    resolved_at = CASE WHEN ? = 'RESOLVED' THEN NOW() ELSE resolved_at END,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $notes, $status, $issueId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Issue status updated'
            ]);
            break;
        
        // ============================================
        // ASSIGN ISSUE (Admin only)
        // ============================================
        case 'assign_issue':
            // TODO: Add admin check here
            $issueId = (int)$_POST['issue_id'];
            $userId = (int)$_POST['user_id'];
            
            $stmt = $pdo->prepare("
                UPDATE notification_issues 
                SET assigned_to_user_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$userId, $issueId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Issue assigned successfully'
            ]);
            break;
        
        // ============================================
        // SNOOZE ISSUE
        // ============================================
        case 'snooze_issue':
            $issueId = (int)$_POST['issue_id'];
            $hours = (int)$_POST['hours'];
            
            // Validate hours (1-72 hours max)
            if ($hours < 1 || $hours > 72) {
                throw new Exception('Invalid snooze duration');
            }
            
            $stmt = $pdo->prepare("
                UPDATE notification_issues 
                SET snoozed_until = DATE_ADD(NOW(), INTERVAL ? HOUR), 
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$hours, $issueId]);
            
            echo json_encode([
                'success' => true,
                'message' => "Issue snoozed for $hours hours"
            ]);
            break;
        
        // ============================================
        // GET ISSUE DETAILS
        // ============================================
        case 'get_issue_details':
            $issueId = (int)$_GET['issue_id'];
            
            $stmt = $pdo->prepare("
                SELECT 
                    ni.*,
                    nr.name as rule_name,
                    nr.code as rule_code,
                    nr.description as rule_description
                FROM notification_issues ni
                JOIN notification_rules nr ON nr.id = ni.rule_id
                WHERE ni.id = ?
            ");
            $stmt->execute([$issueId]);
            $issue = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$issue) {
                throw new Exception('Issue not found');
            }
            
            // Decode context_json
            $issue['context'] = json_decode($issue['context_json'], true);
            
            echo json_encode([
                'success' => true,
                'issue' => $issue
            ]);
            break;
        
        // ============================================
        // GET NOTIFICATIONS FOR ISSUE
        // ============================================
        case 'get_issue_notifications':
            $issueId = (int)$_GET['issue_id'];
            
            $stmt = $pdo->prepare("
                SELECT 
                    n.*,
                    u.email,
                    u.full_name
                FROM notifications n
                LEFT JOIN users u ON u.id = n.recipient_user_id
                WHERE n.issue_id = ?
                ORDER BY n.created_at DESC
            ");
            $stmt->execute([$issueId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications
            ]);
            break;
        
        // ============================================
        // GET SYSTEM JOBS STATUS
        // ============================================
        case 'get_jobs_status':
            $stmt = $pdo->query("
                SELECT * FROM system_jobs 
                ORDER BY last_run_at DESC
            ");
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'jobs' => $jobs
            ]);
            break;
        
        // ============================================
        // GET STATISTICS
        // ============================================
        case 'get_statistics':
            // Total issues by status
            $statusStats = $pdo->query("
                SELECT status, COUNT(*) as count 
                FROM notification_issues 
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Issues by severity
            $severityStats = $pdo->query("
                SELECT severity, COUNT(*) as count 
                FROM notification_issues 
                WHERE status != 'RESOLVED'
                GROUP BY severity
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Notifications sent today
            $notifToday = $pdo->query("
                SELECT COUNT(*) as count 
                FROM notifications 
                WHERE DATE(created_at) = CURDATE()
            ")->fetch()['count'];
            
            // Most active rules
            $activeRules = $pdo->query("
                SELECT 
                    nr.name,
                    COUNT(ni.id) as issue_count
                FROM notification_rules nr
                LEFT JOIN notification_issues ni ON ni.rule_id = nr.id
                WHERE nr.is_active = 1
                GROUP BY nr.id, nr.name
                ORDER BY issue_count DESC
                LIMIT 5
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'statistics' => [
                    'issues_by_status' => $statusStats,
                    'issues_by_severity' => $severityStats,
                    'notifications_today' => $notifToday,
                    'most_active_rules' => $activeRules
                ]
            ]);
            break;
        
        // ============================================
        // HEALTH CHECK
        // ============================================
        case 'health_check':
            // Check if jobs ran recently
            $jobsStmt = $pdo->query("
                SELECT 
                    job_code,
                    last_run_at,
                    status,
                    TIMESTAMPDIFF(MINUTE, last_run_at, NOW()) as minutes_ago
                FROM system_jobs
            ");
            $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $healthy = true;
            $warnings = [];
            
            foreach ($jobs as $job) {
                if ($job['status'] === 'ERROR') {
                    $healthy = false;
                    $warnings[] = "{$job['job_code']} has errors";
                }
                
                if ($job['minutes_ago'] > 30) {
                    $warnings[] = "{$job['job_code']} hasn't run in {$job['minutes_ago']} minutes";
                }
            }
            
            echo json_encode([
                'success' => true,
                'healthy' => $healthy,
                'warnings' => $warnings,
                'jobs' => $jobs
            ]);
            break;
        
        // ============================================
        // DEFAULT - Invalid action
        // ============================================
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'available_actions' => [
                    'get_unread_count',
                    'get_recent_notifications',
                    'mark_as_read',
                    'mark_all_read',
                    'update_issue_status',
                    'assign_issue',
                    'snooze_issue',
                    'get_issue_details',
                    'get_issue_notifications',
                    'get_jobs_status',
                    'get_statistics',
                    'health_check'
                ]
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>