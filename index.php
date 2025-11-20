<?php
/**
 *  Admin Alerts Center 
 *  Features: Password protection, Rule monitoring, Export, Audit logging
 */

require_once 'config/database.php';
require_once 'AlertsController.php';
require_once 'audit_log.php';

session_start();

// ============ PASSWORD PROTECTION ============
define('ADMIN_PASSWORD', getenv('ADMIN_ALERTS_PASSWORD') ?: 'cloudsoftware2025');

if (!isset($_SESSION['admin_alerts_authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals(ADMIN_PASSWORD, $_POST['password'])) {
            $_SESSION['admin_alerts_authenticated'] = true;
            $_SESSION['admin_alerts_login_time'] = time();
            $_SESSION['admin_alerts_ip'] = $_SERVER['REMOTE_ADDR'];
            header('Location: index.php');
            exit;
        } else {
            $auth_error = 'Invalid password';
            sleep(2); // Prevent brute force
        }
    }
    
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Alerts - Login</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
            .login-card { max-width: 400px; margin: 0 auto; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="login-card">
                <div class="card shadow-lg">
                    <div class="card-body p-5">
                        <h3 class="text-center mb-4"><i class="fas fa-shield-alt"></i> Admin Alerts</h3>
                        <?php if (isset($auth_error)): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($auth_error) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Session validation
if ($_SESSION['admin_alerts_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Auto-logout after 2 hours
if ((time() - $_SESSION['admin_alerts_login_time']) > 7200) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Logout handler
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// ============ CSRF PROTECTION ============
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validateCsrfToken() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die(json_encode(['success' => false, 'error' => 'CSRF validation failed']));
    }
}

// ============ INITIALIZATION ============
$pdo = getDatabaseConnection();
$currentUserId = getCurrentUserId();
$alertsController = new AlertsController($pdo, $currentUserId);

// ============ EXPORT HANDLER ============
if (isset($_GET['export'])) {
    $format = $_GET['export']; // 'csv' or 'json'
    $filters = [
        'status' => $_GET['status'] ?? '',
        'severity' => $_GET['severity'] ?? '',
        'rule_id' => $_GET['rule_id'] ?? '',
        'search' => $_GET['search'] ?? '',
        'quick' => $_GET['quick'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? ''
    ];
    
    $issues = $alertsController->getIssues($filters, 10000);
    
    auditLog($pdo, $currentUserId, 'EXPORT_ALERTS', 'notification_issues', null, [
        'format' => $format,
        'count' => count($issues),
        'filters' => $filters
    ]);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="alerts_export_' . date('Y-m-d_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Title', 'Status', 'Severity', 'Rule', 'Entity Type', 'Entity ID', 'First Detected', 'Last Detected', 'Age (hours)', 'Resolution Notes']);
        
        foreach ($issues as $issue) {
            fputcsv($output, [
                $issue['id'],
                $issue['title'],
                $issue['status'],
                $issue['severity'],
                $issue['rule_name'],
                $issue['entity_type'],
                $issue['entity_id'],
                $issue['first_detected_at'],
                $issue['last_detected_at'],
                $issue['age_hours'],
                $issue['resolution_notes']
            ]);
        }
        fclose($output);
        exit;
        
    } elseif ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="alerts_export_' . date('Y-m-d_His') . '.json"');
        echo json_encode($issues, JSON_PRETTY_PRINT);
        exit;
    }
}

// ============ AJAX ACTIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    validateCsrfToken();
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'];
        $result = null;
        
        switch ($action) {
            case 'update_status':
                $issueId = (int)$_POST['issue_id'];
                $status = $_POST['status'];
                $notes = $_POST['notes'] ?? null;
                
                $result = $alertsController->updateStatus($issueId, $status, $notes);
                auditLog($pdo, $currentUserId, 'UPDATE_ALERT_STATUS', 'notification_issues', $issueId, [
                    'status' => $status,
                    'notes' => $notes
                ]);
                break;
                
            case 'bulk_update':
                $issueIds = json_decode($_POST['issue_ids'], true);
                $status = $_POST['status'];
                $notes = $_POST['notes'] ?? null;
                
                $result = $alertsController->bulkUpdateStatus($issueIds, $status, $notes);
                auditLog($pdo, $currentUserId, 'BULK_UPDATE_ALERTS', 'notification_issues', null, [
                    'count' => count($issueIds),
                    'status' => $status
                ]);
                break;
                
            case 'add_note':
                $issueId = (int)$_POST['issue_id'];
                $note = $_POST['note'];
                
                $result = $alertsController->addNote($issueId, $note);
                auditLog($pdo, $currentUserId, 'ADD_ALERT_NOTE', 'notification_issues', $issueId, ['note' => $note]);
                break;
                
            case 'snooze':
                $issueId = (int)$_POST['issue_id'];
                $hours = (int)$_POST['hours'];
                
                $result = $alertsController->snoozeIssue($issueId, $hours);
                auditLog($pdo, $currentUserId, 'SNOOZE_ALERT', 'notification_issues', $issueId, ['hours' => $hours]);
                break;
                
            case 'assign':
                $issueId = (int)$_POST['issue_id'];
                $userId = (int)$_POST['user_id'];
                
                $result = $alertsController->assignIssue($issueId, $userId);
                auditLog($pdo, $currentUserId, 'ASSIGN_ALERT', 'notification_issues', $issueId, ['user_id' => $userId]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
        
        echo json_encode($result);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============ BUILD FILTERS ============
$filters = [
    'status' => $_GET['status'] ?? '',
    'severity' => $_GET['severity'] ?? '',
    'rule_id' => $_GET['rule_id'] ?? '',
    'search' => $_GET['search'] ?? '',
    'quick' => $_GET['quick'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// ============ GET DATA ============
$issues = $alertsController->getIssues($filters, 100);
$stats = $alertsController->getStatistics($issues);
$rules = $alertsController->getAllRules();

// ============ RULE MONITORING DATA ============
$ruleMonitoring = $pdo->query("
    SELECT 
        nr.id,
        nr.name,
        nr.code,
        nr.is_active,
        nr.last_executed_at,
        nr.schedule_expression,
        COUNT(DISTINCT ni.id) as total_issues,
        SUM(CASE WHEN ni.status = 'OPEN' THEN 1 ELSE 0 END) as open_issues,
        SUM(CASE WHEN ni.severity = 'CRITICAL' THEN 1 ELSE 0 END) as critical_issues,
        TIMESTAMPDIFF(MINUTE, nr.last_executed_at, NOW()) as minutes_since_last_run
    FROM notification_rules nr
    LEFT JOIN notification_issues ni ON ni.rule_id = nr.id
    WHERE nr.condition_type = 'SCHEDULED_SQL'
    GROUP BY nr.id
    ORDER BY nr.is_active DESC, minutes_since_last_run DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Alerts Center - Saboura</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/admin_alerts.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-bell"></i> Admin Alerts Center
            </span>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="autoRefreshToggle" checked>
                    <label class="form-check-label text-white" for="autoRefreshToggle">
                        Auto-refresh (30s)
                    </label>
                </div>
                <span class="text-white-50 small">Last updated: <span id="lastUpdated">now</span></span>
                <a href="?logout=1" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="auto-refresh-indicator" id="refreshIndicator">
        <i class="fas fa-sync-alt fa-spin"></i> Refreshing...
    </div>

    <div class="container-fluid mt-4">
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#alertsTab">
                    <i class="fas fa-exclamation-triangle"></i> Alerts
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#rulesTab">
                    <i class="fas fa-cogs"></i> Rule Monitoring
                </a>
            </li>
        </ul>

        <div class="tab-content">
            <!-- ALERTS TAB -->
            <div class="tab-pane fade show active" id="alertsTab">
                <!-- Quick Filters -->
                <div class="mb-3">
                    <button class="btn btn-outline-primary quick-filter-btn <?= $filters['quick'] === 'unresolved' ? 'active' : '' ?>" 
                            onclick="applyQuickFilter('unresolved')">
                        <i class="fas fa-exclamation-circle"></i> Unresolved
                    </button>
                    <button class="btn btn-outline-danger quick-filter-btn <?= $filters['quick'] === 'critical' ? 'active' : '' ?>" 
                            onclick="applyQuickFilter('critical')">
                        <i class="fas fa-fire"></i> Critical Only
                    </button>
                    <button class="btn btn-outline-info quick-filter-btn <?= $filters['quick'] === 'today' ? 'active' : '' ?>" 
                            onclick="applyQuickFilter('today')">
                        <i class="fas fa-calendar-day"></i> Today
                    </button>
                    <button class="btn btn-outline-secondary quick-filter-btn <?= $filters['quick'] === 'my_issues' ? 'active' : '' ?>" 
                            onclick="applyQuickFilter('my_issues')">
                        <i class="fas fa-user"></i> My Issues
                    </button>
                    <button class="btn btn-outline-success quick-filter-btn" onclick="applyDateFilter('last_7_days')">
                        <i class="fas fa-calendar-week"></i> Last 7 Days
                    </button>
                    <button class="btn btn-outline-success quick-filter-btn" onclick="applyDateFilter('last_30_days')">
                        <i class="fas fa-calendar-alt"></i> Last 30 Days
                    </button>
                    <button class="btn btn-outline-dark quick-filter-btn" onclick="clearAllFilters()">
                        <i class="fas fa-times"></i> Clear All
                    </button>
                    
                    <!-- Export buttons -->
                    <div class="btn-group float-end">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?export=csv&<?= http_build_query($filters) ?>">
                                <i class="fas fa-file-csv"></i> Export as CSV
                            </a></li>
                            <li><a class="dropdown-item" href="?export=json&<?= http_build_query($filters) ?>">
                                <i class="fas fa-file-code"></i> Export as JSON
                            </a></li>
                        </ul>
                    </div>
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
                                           value="<?= htmlspecialchars($filters['search']) ?>">
                                    <button class="btn btn-outline-secondary" type="submit">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Date Range</label>
                                <div class="date-filter-group">
                                    <div class="row g-2 align-items-center">
                                        <div class="col">
                                            <input type="date" name="date_from" class="form-control form-control-sm" 
                                                   value="<?= htmlspecialchars($filters['date_from']) ?>"
                                                   placeholder="From">
                                        </div>
                                        <div class="col-auto date-separator">
                                            <i class="fas fa-arrow-right"></i>
                                        </div>
                                        <div class="col">
                                            <input type="date" name="date_to" class="form-control form-control-sm" 
                                                   value="<?= htmlspecialchars($filters['date_to']) ?>"
                                                   placeholder="To">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All</option>
                                    <option value="OPEN" <?= $filters['status'] === 'OPEN' ? 'selected' : '' ?>>Open</option>
                                    <option value="IN_PROGRESS" <?= $filters['status'] === 'IN_PROGRESS' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="RESOLVED" <?= $filters['status'] === 'RESOLVED' ? 'selected' : '' ?>>Resolved</option>
                                    <option value="IGNORED" <?= $filters['status'] === 'IGNORED' ? 'selected' : '' ?>>Ignored</option>
                                </select>
                            </div>
                            
                            <div class="col-md-1">
                                <label class="form-label">Severity</label>
                                <select name="severity" class="form-select">
                                    <option value="">All</option>
                                    <option value="INFO" <?= $filters['severity'] === 'INFO' ? 'selected' : '' ?>>Info</option>
                                    <option value="WARNING" <?= $filters['severity'] === 'WARNING' ? 'selected' : '' ?>>Warning</option>
                                    <option value="CRITICAL" <?= $filters['severity'] === 'CRITICAL' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            
                            <div class="col-md-1">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
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
                                <h2><?= $stats['open'] ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title">In Progress</h5>
                                <h2><?= $stats['in_progress'] ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h5 class="card-title">Critical</h5>
                                <h2><?= $stats['critical'] ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Resolved Today</h5>
                                <h2><?= $stats['resolved_today'] ?></h2>
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

            <!-- RULE MONITORING TAB -->
            <div class="tab-pane fade" id="rulesTab">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-heartbeat"></i> Rule Health Monitor</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Rule Name</th>
                                        <th>Code</th>
                                        <th>Schedule</th>
                                        <th>Last Run</th>
                                        <th>Time Since</th>
                                        <th>Total Issues</th>
                                        <th>Open</th>
                                        <th>Critical</th>
                                        <th>Health</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ruleMonitoring as $rule): 
                                        $minutesSince = $rule['minutes_since_last_run'];
                                        $isStale = $minutesSince > 60;
                                        $healthStatus = 'success';
                                        
                                        if (!$rule['is_active']) {
                                            $healthStatus = 'secondary';
                                        } elseif ($isStale && $rule['is_active']) {
                                            $healthStatus = 'danger';
                                        } elseif ($rule['critical_issues'] > 0) {
                                            $healthStatus = 'warning';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?= $rule['is_active'] ? 'success' : 'secondary' ?>">
                                                    <?= $rule['is_active'] ? 'Active' : 'Inactive' ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($rule['name']) ?></td>
                                            <td><code><?= htmlspecialchars($rule['code']) ?></code></td>
                                            <td><small><?= htmlspecialchars($rule['schedule_expression'] ?? 'N/A') ?></small></td>
                                            <td>
                                                <?php if ($rule['last_executed_at']): ?>
                                                    <small><?= date('Y-m-d H:i', strtotime($rule['last_executed_at'])) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($minutesSince !== null): ?>
                                                    <span class="badge bg-<?= $isStale ? 'danger' : 'success' ?>">
                                                        <?php if ($minutesSince > 1440): ?>
                                                            <?= round($minutesSince / 1440, 1) ?>d
                                                        <?php elseif ($minutesSince > 60): ?>
                                                            <?= round($minutesSince / 60, 1) ?>h
                                                        <?php else: ?>
                                                            <?= $minutesSince ?>m
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge bg-info"><?= $rule['total_issues'] ?></span></td>
                                            <td><span class="badge bg-primary"><?= $rule['open_issues'] ?></span></td>
                                            <td>
                                                <?php if ($rule['critical_issues'] > 0): ?>
                                                    <span class="badge bg-danger"><?= $rule['critical_issues'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $healthStatus ?>">
                                                    <?php if ($healthStatus === 'success'): ?>
                                                        <i class="fas fa-check-circle"></i> Healthy
                                                    <?php elseif ($healthStatus === 'warning'): ?>
                                                        <i class="fas fa-exclamation-triangle"></i> Warning
                                                    <?php elseif ($healthStatus === 'danger'): ?>
                                                        <i class="fas fa-times-circle"></i> Stale
                                                    <?php else: ?>
                                                        <i class="fas fa-pause-circle"></i> Inactive
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Rule Statistics -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Active Rules</h5>
                                <h2><?= count(array_filter($ruleMonitoring, function($r) { return $r['is_active']; })) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h5 class="card-title">Stale Rules</h5>
                                <h2><?= count(array_filter($ruleMonitoring, function($r) { return $r['is_active'] && $r['minutes_since_last_run'] > 60; })) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title">Rules with Critical</h5>
                               <h2><?= count(array_filter($ruleMonitoring, function($r) { return $r['critical_issues'] > 0; })) ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h5 class="card-title">Total Rules</h5>
                                <h2><?= count($ruleMonitoring) ?></h2>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
        const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
        
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
            
            fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=bulk_update&issue_ids=${JSON.stringify([...selectedIssues])}&status=${status}&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
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
            window.location.href = 'index.php';
        }

        function applyDateFilter(preset) {
            const url = new URL(window.location.href);
            const today = new Date();
            let dateFrom, dateTo;
            
            switch(preset) {
                case 'last_7_days':
                    dateFrom = new Date(today);
                    dateFrom.setDate(today.getDate() - 7);
                    dateTo = today;
                    break;
                case 'last_30_days':
                    dateFrom = new Date(today);
                    dateFrom.setDate(today.getDate() - 30);
                    dateTo = today;
                    break;
            }
            
            url.searchParams.set('date_from', dateFrom.toISOString().split('T')[0]);
            url.searchParams.set('date_to', dateTo.toISOString().split('T')[0]);
            
            window.location.href = url.toString();
        }
        
        // Single issue actions
        function updateStatus(issueId, status) {
            if (!confirm('Update issue status to ' + status + '?')) return;
            
            fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=update_status&issue_id=${issueId}&status=${status}&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
            });
        }
        
        function resolveIssue(issueId) {
            currentIssueId = issueId;
            new bootstrap.Modal(document.getElementById('resolveModal')).show();
        }
        
        function confirmResolve() {
            const notes = document.getElementById('resolutionNotes').value;
            
            fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=update_status&issue_id=${currentIssueId}&status=RESOLVED&notes=${encodeURIComponent(notes)}&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
            });
        }
        
        function snoozeIssue(issueId) {
            currentIssueId = issueId;
            new bootstrap.Modal(document.getElementById('snoozeModal')).show();
        }
        
        function confirmSnooze() {
            const hours = document.getElementById('snoozeHours').value;
            
            fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=snooze&issue_id=${currentIssueId}&hours=${hours}&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
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
            
            fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=add_note&issue_id=${issueId}&note=${encodeURIComponent(noteText)}&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Error: ' + err.message);
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