<?php
/**
 * API for Admin Alerts Center
 * Handles CRUD operations for notification_issues
 */

header('Content-Type: application/json');
require_once 'config/database.php';

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Parse URI to get action and ID
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH), '/'));
$action = $uri_parts[count($uri_parts) - 1] ?? '';
$issue_id = is_numeric($action) ? $action : null;

try {
    switch ($method) {
        case 'GET':
            if ($issue_id) {
                // Get single issue
                getIssue($pdo, $issue_id);
            } else {
                // Get list with filters
                getIssues($pdo);
            }
            break;
            
        case 'PATCH':
            if (strpos($uri, '/status') !== false) {
                updateIssueStatus($pdo, $issue_id);
            } elseif (strpos($uri, '/assign') !== false) {
                assignIssue($pdo, $issue_id);
            } elseif (strpos($uri, '/snooze') !== false) {
                snoozeIssue($pdo, $issue_id);
            } else {
                updateIssue($pdo, $issue_id);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get list of issues with filters
 */
function getIssues($pdo) {
    $where = ['1=1'];
    $params = [];
    
    // Filters
    if (!empty($_GET['status'])) {
        $where[] = "i.status = ?";
        $params[] = $_GET['status'];
    }
    
    if (!empty($_GET['severity'])) {
        $where[] = "i.severity = ?";
        $params[] = $_GET['severity'];
    }
    
    if (!empty($_GET['rule_id'])) {
        $where[] = "i.rule_id = ?";
        $params[] = $_GET['rule_id'];
    }
    
    if (!empty($_GET['assigned_to'])) {
        $where[] = "i.assigned_to_user_id = ?";
        $params[] = $_GET['assigned_to'];
    }
    
    if (!empty($_GET['entity_type'])) {
        $where[] = "i.entity_type = ?";
        $params[] = $_GET['entity_type'];
    }
    
    // Date range
    if (!empty($_GET['from_date'])) {
        $where[] = "i.first_detected_at >= ?";
        $params[] = $_GET['from_date'];
    }
    
    if (!empty($_GET['to_date'])) {
        $where[] = "i.first_detected_at <= ?";
        $params[] = $_GET['to_date'];
    }
    
    // Pagination
    $page = $_GET['page'] ?? 1;
    $limit = $_GET['limit'] ?? 50;
    $offset = ($page - 1) * $limit;
    
    // Order
    $order = $_GET['order'] ?? 'last_detected_at';
    $dir = $_GET['dir'] ?? 'DESC';
    
    $where_sql = implode(' AND ', $where);
    
    // Get total count
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM notification_issues i 
        WHERE $where_sql
    ");
    $count_stmt->execute($params);
    $total = $count_stmt->fetch()['total'];
    
    // Get issues
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            r.name as rule_name,
            r.code as rule_code,
            u.email as assigned_to_email,
            u.full_name as assigned_to_name,
            TIMESTAMPDIFF(HOUR, i.first_detected_at, NOW()) as age_hours
        FROM notification_issues i
        JOIN notification_rules r ON r.id = i.rule_id
        LEFT JOIN users u ON u.id = i.assigned_to_user_id
        WHERE $where_sql
        ORDER BY i.$order $dir
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse context_json for each issue
    foreach ($issues as &$issue) {
        $issue['context'] = json_decode($issue['context_json'], true);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $issues,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Get single issue
 */
function getIssue($pdo, $issue_id) {
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            r.name as rule_name,
            r.code as rule_code,
            r.description as rule_description,
            u.email as assigned_to_email,
            u.full_name as assigned_to_name
        FROM notification_issues i
        JOIN notification_rules r ON r.id = i.rule_id
        LEFT JOIN users u ON u.id = i.assigned_to_user_id
        WHERE i.id = ?
    ");
    $stmt->execute([$issue_id]);
    $issue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$issue) {
        http_response_code(404);
        echo json_encode(['error' => 'Issue not found']);
        return;
    }
    
    $issue['context'] = json_decode($issue['context_json'], true);
    
    // Get related notifications
    $notif_stmt = $pdo->prepare("
        SELECT n.*, u.email as recipient_email, u.full_name as recipient_name
        FROM notifications n
        LEFT JOIN users u ON u.id = n.recipient_user_id
        WHERE n.issue_id = ?
        ORDER BY n.created_at DESC
    ");
    $notif_stmt->execute([$issue_id]);
    $issue['notifications'] = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $issue]);
}

/**
 * Update issue status
 */
function updateIssueStatus($pdo, $issue_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Status is required']);
        return;
    }
    
    $allowed_statuses = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'IGNORED'];
    if (!in_array($data['status'], $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status']);
        return;
    }
    
    $sql = "UPDATE notification_issues SET status = ?, updated_at = NOW()";
    $params = [$data['status']];
    
    // If resolving, set resolved_at
    if ($data['status'] === 'RESOLVED') {
        $sql .= ", resolved_at = NOW()";
        if (!empty($data['resolution_notes'])) {
            $sql .= ", resolution_notes = ?";
            $params[] = $data['resolution_notes'];
        }
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $issue_id;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully'
    ]);
}

/**
 * Assign issue to user
 */
function assignIssue($pdo, $issue_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $pdo->prepare("
        UPDATE notification_issues 
        SET assigned_to_user_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$data['user_id'] ?? null, $issue_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Issue assigned successfully'
    ]);
}

/**
 * Snooze issue
 */
function snoozeIssue($pdo, $issue_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['minutes'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Minutes is required']);
        return;
    }
    
    $stmt = $pdo->prepare("
        UPDATE notification_issues 
        SET snoozed_until = NOW() + INTERVAL ? MINUTE, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$data['minutes'], $issue_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Issue snoozed successfully'
    ]);
}

/**
 * Update issue (generic)
 */
function updateIssue($pdo, $issue_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $allowed_fields = ['resolution_notes', 'escalation_level'];
    $updates = [];
    $params = [];
    
    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }
    
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid fields to update']);
        return;
    }
    
    $updates[] = "updated_at = NOW()";
    $params[] = $issue_id;
    
    $sql = "UPDATE notification_issues SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'Issue updated successfully'
    ]);
}