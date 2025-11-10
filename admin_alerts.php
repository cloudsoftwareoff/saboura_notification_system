<?php
/**
 *  Admin Alerts Center 
 * Route:  admin_alerts.php
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
    
    if ($_POST['action'] === 'bulk_update') {
        $issueIds = json_decode($_POST['issue_ids'], true);
        $newStatus = $_POST['status'];
        $notes = $_POST['notes'] ?? null;
        
        $placeholders = str_repeat('?,', count($issueIds) - 1) . '?';
        $stmt = $pdo->prepare("
            UPDATE notification_issues 
            SET status = ?, 
                resolution_notes = COALESCE(?, resolution_notes),
                resolved_at = CASE WHEN ? = 'RESOLVED' THEN NOW() ELSE resolved_at END,
                updated_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $params = array_merge([$newStatus, $notes, $newStatus], $issueIds);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
        exit;
    }
    
    if ($_POST['action'] === 'add_note') {
        $issueId = (int)$_POST['issue_id'];
        $note = $_POST['note'];
        
        $stmt = $pdo->prepare("
            UPDATE notification_issues 
            SET resolution_notes = CONCAT(COALESCE(resolution_notes, ''), '\n[', NOW(), '] ', ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$note, $issueId]);
        
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
$searchQuery = $_GET['search'] ?? '';
$quickFilter = $_GET['quick'] ?? '';

// Build query
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
if ($quickFilter === 'my_issues') {
    $sql .= " AND ni.assigned_to_user_id = ?";
    $params[] = $currentUserId;
}
if ($quickFilter === 'critical') {
    $sql .= " AND ni.severity = 'CRITICAL'";
}
if ($quickFilter === 'unresolved') {
    $sql .= " AND ni.status IN ('OPEN', 'IN_PROGRESS')";
}
if ($quickFilter === 'today') {
    $sql .= " AND DATE(ni.first_detected_at) = CURDATE()";
}

// Regular filters
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

// Search
if ($searchQuery) {
    $sql .= " AND (ni.title LIKE ? OR ni.entity_id LIKE ? OR nr.name LIKE ?)";
    $searchTerm = "%$searchQuery%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY 
    CASE WHEN ni.snoozed_until > NOW() THEN 1 ELSE 0 END,
    FIELD(ni.severity, 'CRITICAL', 'WARNING', 'INFO'),
    FIELD(ni.status, 'OPEN', 'IN_PROGRESS', 'RESOLVED', 'IGNORED'),
    ni.last_detected_at DESC 
    LIMIT 100";

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
        
        .age-badge {
            font-size: 0.75rem;
            padding: 0.15rem 0.4rem;
        }
        .age-new { background-color: #d1ecf1; color: #0c5460; }
        .age-aging { background-color: #fff3cd; color: #856404; }
        .age-stale { background-color: #f8d7da; color: #721c24; }
        
        .issue-card {
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        .issue-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
        }
        .issue-card.selected {
            border: 2px solid #0d6efd;
            background-color: #f8f9fa;
        }
        .issue-card.snoozed {
            opacity: 0.6;
        }
        
        .action-btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .quick-filter-btn {
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
        }
        
        .bulk-actions-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 1rem 2rem;
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 2rem rgba(0,0,0,0.3);
            display: none;
            z-index: 1000;
        }
        
        .bulk-actions-bar.show {
            display: block;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from { transform: translateX(-50%) translateY(100px); opacity: 0; }
            to { transform: translateX(-50%) translateY(0); opacity: 1; }
        }
        
        .auto-refresh-indicator {
            position: fixed;
            top: 70px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .auto-refresh-indicator.show {
            opacity: 1;
        }
        
        .note-input-inline {
            display: none;
            margin-top: 0.5rem;
        }
        
        .note-input-inline.show {
            display: block;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-bell"></i> Admin Alerts Center
            </span>
            <div class="d-flex align-items-center">
                <div class="form-check form-switch me-3">
                    <input class="form-check-input" type="checkbox" id="autoRefreshToggle" checked>
                    <label class="form-check-label text-white" for="autoRefreshToggle">
                        Auto-refresh (30s)
                    </label>
                </div>
                <span class="text-white-50 small">Last updated: <span id="lastUpdated">now</span></span>
            </div>
        </div>
    </nav>

    <div class="auto-refresh-indicator" id="refreshIndicator">
        <i class="fas fa-sync-alt fa-spin"></i> Refreshing...
    </div>

    <div class="container-fluid mt-4">
        <!-- Quick Filters -->
        <div class="mb-3">
            <button class="btn btn-outline-primary quick-filter-btn <?= $quickFilter === 'unresolved' ? 'active' : '' ?>" 
                    onclick="applyQuickFilter('unresolved')">
                <i class="fas fa-exclamation-circle"></i> Unresolved
            </button>
            <button class="btn btn-outline-danger quick-filter-btn <?= $quickFilter === 'critical' ? 'active' : '' ?>" 
                    onclick="applyQuickFilter('critical')">
                <i class="fas fa-fire"></i> Critical Only
            </button>
            <button class="btn btn-outline-info quick-filter-btn <?= $quickFilter === 'today' ? 'active' : '' ?>" 
                    onclick="applyQuickFilter('today')">
                <i class="fas fa-calendar-day"></i> Today
            </button>
            <button class="btn btn-outline-secondary quick-filter-btn <?= $quickFilter === 'my_issues' ? 'active' : '' ?>" 
                    onclick="applyQuickFilter('my_issues')">
                <i class="fas fa-user"></i> My Issues
            </button>
            <button class="btn btn-outline-dark quick-filter-btn" onclick="clearAllFilters()">
                <i class="fas fa-times"></i> Clear All
            </button>
        </div>

        <!-- Search and Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3" id="filterForm">
                    <div class="col-md-4">
                        <label class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search title, entity ID, or rule..." 
                                   value="<?= htmlspecialchars($searchQuery) ?>">
                            <button class="btn btn-outline-secondary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All</option>
                            <option value="OPEN" <?= $statusFilter === 'OPEN' ? 'selected' : '' ?>>Open</option>
                            <option value="IN_PROGRESS" <?= $statusFilter === 'IN_PROGRESS' ? 'selected' : '' ?>>In Progress</option>
                            <option value="RESOLVED" <?= $statusFilter === 'RESOLVED' ? 'selected' : '' ?>>Resolved</option>
                            <option value="IGNORED" <?= $statusFilter === 'IGNORED' ? 'selected' : '' ?>>Ignored</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Severity</label>
                        <select name="severity" class="form-select">
                            <option value="">All</option>
                            <option value="INFO" <?= $severityFilter === 'INFO' ? 'selected' : '' ?>>Info</option>
                            <option value="WARNING" <?= $severityFilter === 'WARNING' ? 'selected' : '' ?>>Warning</option>
                            <option value="CRITICAL" <?= $severityFilter === 'CRITICAL' ? 'selected' : '' ?>>Critical</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Rule</label>
                        <select name="rule_id" class="form-select">
                            <option value="">All Rules</option>
                            <?php foreach ($rules as $rule): ?>
                                <option value="<?= $rule['id'] ?>" <?= $ruleFilter == $rule['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rule['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
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

        <!-- Bulk Selection Controls -->
        <div class="mb-3 d-flex justify-content-between align-items-center">
            <div>
                <button class="btn btn-sm btn-outline-secondary" onclick="selectAll()">
                    <i class="fas fa-check-double"></i> Select All
                </button>
                <button class="btn btn-sm btn-outline-secondary" onclick="deselectAll()">
                    <i class="fas fa-times"></i> Deselect All
                </button>
                <span class="ms-2 text-muted" id="selectionCount">0 selected</span>
            </div>
            <div>
                <span class="text-muted">Showing <?= count($issues) ?> issues</span>
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
                <?php foreach ($issues as $issue): 
                    $ageClass = 'age-new';
                    $ageLabel = 'New';
                    if ($issue['age_hours'] >= 24) {
                        $ageClass = 'age-stale';
                        $ageLabel = 'Stale (' . $issue['age_hours'] . 'h)';
                    } elseif ($issue['age_hours'] >= 6) {
                        $ageClass = 'age-aging';
                        $ageLabel = 'Aging (' . $issue['age_hours'] . 'h)';
                    } elseif ($issue['age_hours'] >= 1) {
                        $ageLabel = $issue['age_hours'] . 'h old';
                    } else {
                        $ageLabel = $issue['age_minutes'] . 'm old';
                    }
                    
                    $isSnoozed = $issue['snoozed_until'] && $issue['snoozed_until'] > date('Y-m-d H:i:s');
                ?>
                    <div class="col-12">
                        <div class="card issue-card severity-<?= $issue['severity'] ?> <?= $isSnoozed ? 'snoozed' : '' ?>" 
                             data-issue-id="<?= $issue['id'] ?>">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex align-items-start mb-2">
                                            <input type="checkbox" class="form-check-input me-2 mt-1 issue-checkbox" 
                                                   value="<?= $issue['id'] ?>" onchange="updateSelection()">
                                            
                                            <div>
                                                <h5 class="card-title mb-2">
                                                    <span class="badge bg-<?= $issue['severity'] === 'CRITICAL' ? 'danger' : ($issue['severity'] === 'WARNING' ? 'warning text-dark' : 'info') ?>">
                                                        <?= $issue['severity'] ?>
                                                    </span>
                                                    <span class="badge age-badge <?= $ageClass ?>">
                                                        <i class="fas fa-clock"></i> <?= $ageLabel ?>
                                                    </span>
                                                    <?= htmlspecialchars($issue['title']) ?>
                                                </h5>
                                                
                                                <p class="text-muted mb-2">
                                                    <strong>Rule:</strong> <?= htmlspecialchars($issue['rule_name']) ?> 
                                                    | <strong>Entity:</strong> <?= $issue['entity_type'] ?> #<?= $issue['entity_id'] ?>
                                                    | <strong>First:</strong> <?= date('Y-m-d H:i', strtotime($issue['first_detected_at'])) ?>
                                                </p>
                                                
                                                <?php if ($issue['resolution_notes']): ?>
                                                    <div class="alert alert-secondary mb-2">
                                                        <strong>Notes:</strong> <?= nl2br(htmlspecialchars($issue['resolution_notes'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($isSnoozed): ?>
                                                    <div class="alert alert-warning mb-2">
                                                        <i class="fas fa-clock"></i> Snoozed until <?= date('Y-m-d H:i', strtotime($issue['snoozed_until'])) ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <!-- Inline note input -->
                                                <div class="note-input-inline" id="noteInput<?= $issue['id'] ?>">
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" class="form-control" 
                                                               placeholder="Add a quick note..." 
                                                               id="noteText<?= $issue['id'] ?>">
                                                        <button class="btn btn-primary" onclick="saveNote(<?= $issue['id'] ?>)">
                                                            <i class="fas fa-save"></i> Save
                                                        </button>
                                                        <button class="btn btn-secondary" onclick="cancelNote(<?= $issue['id'] ?>)">
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
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
                                            
                                            <button class="btn btn-sm btn-info action-btn" 
                                                    onclick="toggleNoteInput(<?= $issue['id'] ?>)">
                                                <i class="fas fa-sticky-note"></i> Add Note
                                            </button>
                                            
                                            <button class="btn btn-sm btn-dark action-btn" 
                                                    onclick="updateStatus(<?= $issue['id'] ?>, 'IGNORED')">
                                                <i class="fas fa-eye-slash"></i> Ignore
                                            </button>
                                            
                                            <button class="btn btn-sm btn-outline-primary action-btn" 
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

    <!-- Bulk Actions Bar -->
    <div class="bulk-actions-bar" id="bulkActionsBar">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold"><span id="bulkCount">0</span> selected</span>
            <button class="btn btn-success btn-sm" onclick="bulkAction('RESOLVED')">
                <i class="fas fa-check"></i> Resolve All
            </button>
            <button class="btn btn-warning btn-sm" onclick="bulkAction('IN_PROGRESS')">
                <i class="fas fa-play"></i> Set In Progress
            </button>
            <button class="btn btn-dark btn-sm" onclick="bulkAction('IGNORED')">
                <i class="fas fa-eye-slash"></i> Ignore All
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="deselectAll()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>

    <!-- Modals -->
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
        let autoRefreshInterval = null;
        const selectedIssues = new Set();
        
        // Auto-refresh functionality
        document.getElementById('autoRefreshToggle').addEventListener('change', function() {
            if (this.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        function startAutoRefresh() {
            autoRefreshInterval = setInterval(() => {
                refreshPage();
            }, 30000); // 30 seconds
        }
        
        function stopAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
        }
        
        function refreshPage() {
            const indicator = document.getElementById('refreshIndicator');
            indicator.classList.add('show');
            
            setTimeout(() => {
                location.reload();
            }, 500);
        }
        
        function updateLastUpdated() {
            document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString();
        }
        
        // Selection management
        function updateSelection() {
            selectedIssues.clear();
            document.querySelectorAll('.issue-checkbox:checked').forEach(cb => {
                selectedIssues.add(parseInt(cb.value));
            });
            
            const count = selectedIssues.size;
            document.getElementById('selectionCount').textContent = count + ' selected';
            document.getElementById('bulkCount').textContent = count;
            
            if (count > 0) {
                document.getElementById('bulkActionsBar').classList.add('show');
            } else {
                document.getElementById('bulkActionsBar').classList.remove('show');
            }
            
            // Update card styling
            document.querySelectorAll('.issue-card').forEach(card => {
                const checkbox = card.querySelector('.issue-checkbox');
                if (checkbox && checkbox.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
            });
        }
        
        function selectAll() {
            document.querySelectorAll('.issue-checkbox').forEach(cb => {
                cb.checked = true;
            });
            updateSelection();
        }
        
        function deselectAll() {
            document.querySelectorAll('.issue-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateSelection();
        }
        
        // Bulk actions
        function bulkAction(status) {
            if (selectedIssues.size === 0) return;
            
            const action = status === 'RESOLVED' ? 'resolve' : 
                          status === 'IN_PROGRESS' ? 'start working on' : 'ignore';
            
            if (!confirm(`${action} ${selectedIssues.size} selected issues?`)) return;
            
            fetch('admin_alerts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=bulk_update&issue_ids=${JSON.stringify([...selectedIssues])}&status=${status}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        // Quick filters
        function applyQuickFilter(filter) {
            const url = new URL(window.location.href);
            url.searchParams.delete('quick');
            url.searchParams.delete('status');
            url.searchParams.delete('severity');
            
            if (filter) {
                url.searchParams.set('quick', filter);
            }
            
            window.location.href = url.toString();
        }
        
        function clearAllFilters() {
            window.location.href = 'admin_alerts.php';
        }
        
        // Single issue actions
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
        
        // Inline notes
        function toggleNoteInput(issueId) {
            const noteInput = document.getElementById('noteInput' + issueId);
            noteInput.classList.toggle('show');
            if (noteInput.classList.contains('show')) {
                document.getElementById('noteText' + issueId).focus();
            }
        }
        
        function cancelNote(issueId) {
            document.getElementById('noteInput' + issueId).classList.remove('show');
            document.getElementById('noteText' + issueId).value = '';
        }
        
        function saveNote(issueId) {
            const noteText = document.getElementById('noteText' + issueId).value;
            if (!noteText.trim()) {
                alert('Please enter a note');
                return;
            }
            
            fetch('admin_alerts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=add_note&issue_id=${issueId}&note=${encodeURIComponent(noteText)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        // Initialize
        updateLastUpdated();
        startAutoRefresh();
        
        // Update timestamp every minute
        setInterval(updateLastUpdated, 60000);
    </script>
</body>
</html>