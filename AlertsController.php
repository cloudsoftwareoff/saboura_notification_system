<?php
/**
 * Alerts Controller - CRUD operations for admin alerts
 * Handles all database operations for notification issues
 */

class AlertsController {
    private $pdo;
    private $currentUserId;
    
    public function __construct($pdo, $currentUserId) {
        $this->pdo = $pdo;
        $this->currentUserId = $currentUserId;
    }
    


    /**
     * Update single issue status
     */


    public function updateStatus($issueId, $newStatus, $notes = null) {
        $stmt = $this->pdo->prepare("
            UPDATE notification_issues 
            SET status = ?, 
                resolution_notes = COALESCE(?, resolution_notes),
                resolved_at = CASE WHEN ? = 'RESOLVED' THEN NOW() ELSE resolved_at END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $notes, $newStatus, $issueId]);
        
        return ['success' => true, 'rows_affected' => $stmt->rowCount()];
    }
    
    /**
     * Bulk update issue statuses
     */
public function bulkUpdateStatus($issueIds, $newStatus, $notes = null) {
    if (empty($issueIds)) {
        throw new Exception('No issues provided');
    }

    $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
    $sql = "
        UPDATE notification_issues 
        SET status = ?, 
            resolution_notes = COALESCE(?, resolution_notes),
            resolved_at = CASE WHEN ? = 'RESOLVED' THEN NOW() ELSE resolved_at END,
            updated_at = NOW()
        WHERE id IN ($placeholders)
    ";

    $stmt = $this->pdo->prepare($sql);
    $params = array_merge([$newStatus, $notes, $newStatus], $issueIds);
    $stmt->execute($params);

    return ['success' => true, 'count' => $stmt->rowCount()];
}
    
    /**
     * Add note to issue
     */
    public function addNote($issueId, $note) {
        $stmt = $this->pdo->prepare("
            UPDATE notification_issues 
            SET resolution_notes = CONCAT(
                COALESCE(resolution_notes, ''), 
                '\n[', NOW(), '] ', ?
            ),
            updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$note, $issueId]);
        
        return ['success' => true];
    }
    
    /**
     * Snooze issue
     */
    public function snoozeIssue($issueId, $hours) {
        if ($hours < 1 || $hours > 72) {
            throw new Exception('Invalid snooze duration (1-72 hours)');
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE notification_issues 
            SET snoozed_until = DATE_ADD(NOW(), INTERVAL ? HOUR), 
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hours, $issueId]);
        
        return ['success' => true];
    }
    
    /**
     * Assign issue to user
     */
    public function assignIssue($issueId, $userId) {
        $stmt = $this->pdo->prepare("
            UPDATE notification_issues 
            SET assigned_to_user_id = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $issueId]);
        
        return ['success' => true];
    }
    
    /**
     * Get filtered issues with pagination
     */
    public function getIssues($filters = [], $limit = 100) {
        $sql = "
            SELECT 
                ni.*,
                nr.name as rule_name,
                nr.code as rule_code,
                TIMESTAMPDIFF(HOUR, ni.first_detected_at, NOW()) as age_hours,
                TIMESTAMPDIFF(MINUTE, ni.first_detected_at, NOW()) as age_minutes
            FROM notification_issues ni
            JOIN notification_rules nr ON nr.id = ni.rule_id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Quick filters
        if (!empty($filters['quick'])) {
            switch ($filters['quick']) {
                case 'my_issues':
                    $sql .= " AND ni.assigned_to_user_id = ?";
                    $params[] = $this->currentUserId;
                    break;
                case 'critical':
                    $sql .= " AND ni.severity = 'CRITICAL'";
                    break;
                case 'unresolved':
                    $sql .= " AND ni.status IN ('OPEN', 'IN_PROGRESS')";
                    break;
                case 'today':
                    $sql .= " AND DATE(ni.first_detected_at) = CURDATE()";
                    break;
            }
        }
        
        // Status filter
        if (!empty($filters['status'])) {
            $sql .= " AND ni.status = ?";
            $params[] = $filters['status'];
        }
        
        // Severity filter
        if (!empty($filters['severity'])) {
            $sql .= " AND ni.severity = ?";
            $params[] = $filters['severity'];
        }
        
        // Rule filter
        if (!empty($filters['rule_id'])) {
            $sql .= " AND ni.rule_id = ?";
            $params[] = $filters['rule_id'];
        }
        
        // Search
        if (!empty($filters['search'])) {
            $sql .= " AND (ni.title LIKE ? OR ni.entity_id LIKE ? OR nr.name LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Date range filters
        if (!empty($filters['date_from'])) {
            $sql .= " AND ni.first_detected_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND ni.first_detected_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        // Ordering
        $sql .= " ORDER BY 
            CASE WHEN ni.snoozed_until > NOW() THEN 1 ELSE 0 END,
            FIELD(ni.severity, 'CRITICAL', 'WARNING', 'INFO'),
            FIELD(ni.status, 'OPEN', 'IN_PROGRESS', 'RESOLVED', 'IGNORED'),
            ni.last_detected_at DESC 
            LIMIT ?";
        
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get issue by ID
     */
    public function getIssueById($issueId) {
        $stmt = $this->pdo->prepare("
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
        if ($issue) {
            $issue['context'] = json_decode($issue['context_json'], true);
        }
        
        return $issue;
    }
    
    /**
     * Get all notification rules for dropdowns
     */
    public function getAllRules() {
        $stmt = $this->pdo->query("
            SELECT id, name, code 
            FROM notification_rules 
            ORDER BY name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get statistics for dashboard
     */
    public function getStatistics($issues) {
        return [
            'open' => count(array_filter($issues, fn($i) => $i['status'] === 'OPEN')),
            'in_progress' => count(array_filter($issues, fn($i) => $i['status'] === 'IN_PROGRESS')),
            'critical' => count(array_filter($issues, fn($i) => $i['severity'] === 'CRITICAL')),
            'resolved_today' => count(array_filter($issues, function($i) {
                return $i['status'] === 'RESOLVED' 
                    && $i['resolved_at']
                    && date('Y-m-d', strtotime($i['resolved_at'])) === date('Y-m-d');
            })),
            'total' => count($issues)
        ];
    }
    
    /**
     * Delete issue (soft delete or hard delete)
     */
    public function deleteIssue($issueId, $hardDelete = false) {
        if ($hardDelete) {
            $stmt = $this->pdo->prepare("DELETE FROM notification_issues WHERE id = ?");
        } else {
            $stmt = $this->pdo->prepare("
                UPDATE notification_issues 
                SET status = 'IGNORED', updated_at = NOW() 
                WHERE id = ?
            ");
        }
        $stmt->execute([$issueId]);
        
        return ['success' => true];
    }
}