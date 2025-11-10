<?php
/**
 * Admin Alerts Center - View and manage all notification issues
 * Route: /admin/alerts or admin_alerts.php
 */

require_once 'config/database.php';

$pdo = getDatabaseConnection();
$currentUserId = getCurrentUserId();

// Handle status update via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_status') {
        $issueId = (int)$_POST['issue_id'];
        $newStatus = $_POST['status'];
        $notes = $_POST['notes'] ?? null;
        
        $stmt = $pdo->prepare("
            UPDATE notification_issues 
            SET status = ?, 
                resolution_notes = COALESCE(?, resolution_notes),
                resolved_at = CASE WHEN ? = 'RESOLVED' THEN NOW() ELSE resolved_at END,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $notes, $newStatus, $issueId]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] === 'assign') {
        $issueId = (int)$_POST['issue_id'];
        $userId = (int)$_POST['user_id'];
        
        $stmt = $pdo->prepare("
            UPDATE notification_issues 
            SET assigned_to_user_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $issueId]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] === 'snooze') {
        $issueId = (int)$_POST['issue_id'];
        $hours = (int)$_POST['hours'];
        
        $stmt = $pdo->prepare("
            UPDATE notification_issues 
            SET snoozed_until = DATE_ADD(NOW(), INTERVAL ? HOUR), updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hours, $issueId]);
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// Filters
$statusFilter = $_GET['status'] ?? '';
$severityFilter = $_GET['severity'] ?? '';
$ruleFilter = $_GET['rule_id'] ?? '';

// Build query
$sql = "
    SELECT 
        ni.*,
        nr.name as rule_name,
        nr.code as rule_code,
        TIMESTAMPDIFF(HOUR, ni.first_detected_at, NOW()) as age_hours
    FROM notification_issues ni
    JOIN notification_rules nr ON nr.id = ni.rule_id
    WHERE 1=1
";

$params = [];

if ($statusFilter) {
    $sql .= " AND ni.status = ?";
    $params[] = $statusFilter;
}

if ($severityFilter) {
    $sql .= " AND ni.severity = ?";
    $params[] = $severityFilter;
}

if ($ruleFilter) {
    $sql .= " AND ni.rule_id = ?";
    $params[] = $ruleFilter;
}

$sql .= " ORDER BY ni.last_detected_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$issues = $stmt->fetchAll();

// Get all rules for filter dropdown
$rulesStmt = $pdo->query("SELECT id, name FROM notification_rules ORDER BY name");
$rules = $rulesStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Alerts Center - Saboura</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .severity-INFO { border-left: 4px solid #0dcaf0; }
        .severity-WARNING { border-left: 4px solid #ffc107; }
        .severity-CRITICAL { border-left: 4px solid #dc3545; }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .status-OPEN { background-color: #e7f3ff; color: #0066cc; }
        .status-IN_PROGRESS { background-color: #fff3cd; color: #856404; }
        .status-RESOLVED { background-color: #d4edda; color: #155724; }
        .status-IGNORED { background-color: #e2e3e5; color: #383d41; }
        
        .issue-card {
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .issue-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        
        .action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-bell"></i> Admin Alerts Center
            </span>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="OPEN" <?= $statusFilter === 'OPEN' ? 'selected' : '' ?>>Open</option>
                            <option value="IN_PROGRESS" <?= $statusFilter === 'IN_PROGRESS' ? 'selected' : '' ?>>In Progress</option>
                            <option value="RESOLVED" <?= $statusFilter === 'RESOLVED' ? 'selected' : '' ?>>Resolved</option>
                            <option value="IGNORED" <?= $statusFilter === 'IGNORED' ? 'selected' : '' ?>>Ignored</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Severity</label>
                        <select name="severity" class="form-select" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="INFO" <?= $severityFilter === 'INFO' ? 'selected' : '' ?>>Info</option>
                            <option value="WARNING" <?= $severityFilter === 'WARNING' ? 'selected' : '' ?>>Warning</option>
                            <option value="CRITICAL" <?= $severityFilter === 'CRITICAL' ? 'selected' : '' ?>>Critical</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Rule</label>
                        <select name="rule_id" class="form-select" onchange="this.form.submit()">
                            <option value="">All Rules</option>
                            <?php foreach ($rules as $rule): ?>
                                <option value="<?= $rule['id'] ?>" <?= $ruleFilter == $rule['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rule['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="admin_alerts.php" class="btn btn-secondary w-100">Clear Filters</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title">Open Issues</h5>
                        <h2><?= count(array_filter($issues, fn($i) => $i['status'] === 'OPEN')) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h5 class="card-title">In Progress</h5>
                        <h2><?= count(array_filter($issues, fn($i) => $i['status'] === 'IN_PROGRESS')) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-danger">
                    <div class="card-body">
                        <h5 class="card-title">Critical</h5>
                        <h2><?= count(array_filter($issues, fn($i) => $i['severity'] === 'CRITICAL')) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Resolved Today</h5>
                        <h2><?= count(array_filter($issues, fn($i) => $i['status'] === 'RESOLVED' && date('Y-m-d', strtotime($i['resolved_at'])) === date('Y-m-d'))) ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Issues List -->
        <div class="row">
            <?php if (empty($issues)): ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No issues found matching your filters.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($issues as $issue): ?>
                    <div class="col-12">
                        <div class="card issue-card severity-<?= $issue['severity'] ?>">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="card-title">
                                            <span class="badge bg-<?= $issue['severity'] === 'CRITICAL' ? 'danger' : ($issue['severity'] === 'WARNING' ? 'warning text-dark' : 'info') ?>">
                                                <?= $issue['severity'] ?>
                                            </span>
                                            <?= htmlspecialchars($issue['title']) ?>
                                        </h5>
                                        
                                        <p class="text-muted mb-2">
                                            <strong>Rule:</strong> <?= htmlspecialchars($issue['rule_name']) ?> 
                                            | <strong>Entity:</strong> <?= $issue['entity_type'] ?> #<?= $issue['entity_id'] ?>
                                            | <strong>Age:</strong> <?= $issue['age_hours'] ?> hours
                                        </p>
                                        
                                        <p class="mb-2">
                                            <strong>First detected:</strong> <?= date('Y-m-d H:i', strtotime($issue['first_detected_at'])) ?>
                                            | <strong>Last detected:</strong> <?= date('Y-m-d H:i', strtotime($issue['last_detected_at'])) ?>
                                        </p>
                                        
                                        <?php if ($issue['resolution_notes']): ?>
                                            <div class="alert alert-secondary mb-2">
                                                <strong>Notes:</strong> <?= nl2br(htmlspecialchars($issue['resolution_notes'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($issue['snoozed_until'] && $issue['snoozed_until'] > date('Y-m-d H:i:s')): ?>
                                            <div class="alert alert-warning mb-2">
                                                <i class="fas fa-clock"></i> Snoozed until <?= date('Y-m-d H:i', strtotime($issue['snoozed_until'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-4 text-end">
                                        <span class="status-badge status-<?= $issue['status'] ?> mb-2 d-inline-block">
                                            <?= str_replace('_', ' ', $issue['status']) ?>
                                        </span>
                                        
                                        <div class="btn-group-vertical w-100 mt-2" role="group">
                                            <?php if ($issue['status'] === 'OPEN'): ?>
                                                <button class="btn btn-sm btn-warning action-btn" 
                                                        onclick="updateStatus(<?= $issue['id'] ?>, 'IN_PROGRESS')">
                                                    <i class="fas fa-play"></i> Start Working
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($issue['status'] !== 'RESOLVED'): ?>
                                                <button class="btn btn-sm btn-success action-btn" 
                                                        onclick="resolveIssue(<?= $issue['id'] ?>)">
                                                    <i class="fas fa-check"></i> Mark Resolved
                                                </button>
                                                
                                                <button class="btn btn-sm btn-secondary action-btn" 
                                                        onclick="snoozeIssue(<?= $issue['id'] ?>)">
                                                    <i class="fas fa-clock"></i> Snooze
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-sm btn-dark action-btn" 
                                                    onclick="updateStatus(<?= $issue['id'] ?>, 'IGNORED')">
                                                <i class="fas fa-eye-slash"></i> Ignore
                                            </button>
                                            
                                            <button class="btn btn-sm btn-info action-btn" 
                                                    onclick="viewDetails(<?= $issue['id'] ?>)">
                                                <i class="fas fa-info-circle"></i> Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resolve Modal -->
    <div class="modal fade" id="resolveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Resolve Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea id="resolutionNotes" class="form-control" rows="4" 
                              placeholder="Add resolution notes (optional)..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="confirmResolve()">Mark Resolved</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Snooze Modal -->
    <div class="modal fade" id="snoozeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Snooze Issue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Snooze for:</label>
                    <select id="snoozeHours" class="form-select">
                        <option value="1">1 hour</option>
                        <option value="4">4 hours</option>
                        <option value="24">24 hours</option>
                        <option value="48">48 hours</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="confirmSnooze()">Snooze</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Issue Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="detailsContent" style="max-height: 400px; overflow: auto;"></pre>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentIssueId = null;
        
        function updateStatus(issueId, status) {
            if (!confirm('Update issue status to ' + status + '?')) return;
            
            fetch('admin_alerts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=update_status&issue_id=${issueId}&status=${status}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function resolveIssue(issueId) {
            currentIssueId = issueId;
            new bootstrap.Modal(document.getElementById('resolveModal')).show();
        }
        
        function confirmResolve() {
            const notes = document.getElementById('resolutionNotes').value;
            
            fetch('admin_alerts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=update_status&issue_id=${currentIssueId}&status=RESOLVED&notes=${encodeURIComponent(notes)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function snoozeIssue(issueId) {
            currentIssueId = issueId;
            new bootstrap.Modal(document.getElementById('snoozeModal')).show();
        }
        
        function confirmSnooze() {
            const hours = document.getElementById('snoozeHours').value;
            
            fetch('admin_alerts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=snooze&issue_id=${currentIssueId}&hours=${hours}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function viewDetails(issueId) {
            const issue = <?= json_encode($issues) ?>.find(i => i.id == issueId);
            document.getElementById('detailsContent').textContent = JSON.stringify(JSON.parse(issue.context_json), null, 2);
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }
    </script>
</body>
</html>