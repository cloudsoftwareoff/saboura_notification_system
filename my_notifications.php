<?php
/**
 * My Notifications - User inbox for assistants/teachers
 * Route: /assistant/notifications or /teacher/notifications
 */

// require_once 'config/database.php';

// $pdo = getDatabaseConnection();
// $currentUserId = getCurrentUserId();

// // Handle mark as read via POST
// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
//     header('Content-Type: application/json');
    
//     if ($_POST['action'] === 'mark_read') {
//         $notifId = (int)$_POST['notification_id'];
        
//         $stmt = $pdo->prepare("
//             UPDATE notifications 
//             SET status = 'READ', read_at = NOW(), updated_at = NOW()
//             WHERE id = ? AND recipient_user_id = ?
//         ");
//         $stmt->execute([$notifId, $currentUserId]);
        
//         echo json_encode(['success' => true]);
//         exit;
//     }
    
//     if ($_POST['action'] === 'mark_all_read') {
//         $stmt = $pdo->prepare("
//             UPDATE notifications 
//             SET status = 'READ', read_at = NOW(), updated_at = NOW()
//             WHERE recipient_user_id = ? AND status != 'READ'
//         ");
//         $stmt->execute([$currentUserId]);
        
//         echo json_encode(['success' => true]);
//         exit;
//     }
// }

// // Filter
// $statusFilter = $_GET['filter'] ?? 'unread'; // unread, all

// // Build query
// $sql = "
//     SELECT 
//         n.*,
//         nr.name as rule_name,
//         nr.severity,
//         ni.entity_type,
//         ni.entity_id,
//         ni.status as issue_status
//     FROM notifications n
//     JOIN notification_rules nr ON nr.id = n.rule_id
//     LEFT JOIN notification_issues ni ON ni.id = n.issue_id
//     WHERE n.recipient_user_id = ?
// ";

// $params = [$currentUserId];

// if ($statusFilter === 'unread') {
//     $sql .= " AND n.status != 'READ'";
// }

// $sql .= " ORDER BY n.created_at DESC LIMIT 100";

// $stmt = $pdo->prepare($sql);
// $stmt->execute($params);
// $notifications = $stmt->fetchAll();

// // Count unread
// $unreadStmt = $pdo->prepare("
//     SELECT COUNT(*) as count 
//     FROM notifications 
//     WHERE recipient_user_id = ? AND status != 'READ'
// ");
// $unreadStmt->execute([$currentUserId]);
// $unreadCount = $unreadStmt->fetch()['count'];

// ?>
// <!DOCTYPE html>
// <html lang="en">
// <head>
//     <meta charset="UTF-8">
//     <meta name="viewport" content="width=device-width, initial-scale=1.0">
//     <title>My Notifications - Saboura</title>
//     <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
//     <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
//     <style>
//         .notification-item {
//             border-left: 4px solid #dee2e6;
//             transition: all 0.2s;
//         }
//         .notification-item:hover {
//             background-color: #f8f9fa;
//             cursor: pointer;
//         }
//         .notification-item.unread {
//             background-color: #e7f3ff;
//             border-left-color: #0d6efd;
//         }
//         .notification-item.unread .notification-title {
//             font-weight: bold;
//         }
        
//         .severity-badge {
//             font-size: 0.75rem;
//             padding: 0.25rem 0.5rem;
//         }
        
//         .notification-time {
//             font-size: 0.875rem;
//             color: #6c757d;
//         }
        
//         .notification-body {
//             display: none;
//             margin-top: 0.5rem;
//             padding-top: 0.5rem;
//             border-top: 1px solid #dee2e6;
//         }
        
//         .notification-item.expanded .notification-body {
//             display: block;
//         }
        
//         .issue-link {
//             font-size: 0.875rem;
//         }
//     </style>
// </head>
// <body>
//     <nav class="navbar navbar-dark bg-primary">
//         <div class="container-fluid">
//             <span class="navbar-brand mb-0 h1">
//                 <i class="fas fa-inbox"></i> My Notifications
//             </span>
//             <span class="badge bg-light text-dark">
//                 <?= $unreadCount ?> Unread
//             </span>
//         </div>
//     </nav>

//     <div class="container-fluid mt-4">
//         <!-- Action Bar -->
//         <div class="card mb-3">
//             <div class="card-body">
//                 <div class="row align-items-center">
//                     <div class="col-md-6">
//                         <div class="btn-group" role="group">
//                             <a href="?filter=unread" 
//                                class="btn btn-<?= $statusFilter === 'unread' ? 'primary' : 'outline-primary' ?>">
//                                 <i class="fas fa-envelope"></i> Unread (<?= $unreadCount ?>)
//                             </a>
//                             <a href="?filter=all" 
//                                class="btn btn-<?= $statusFilter === 'all' ? 'primary' : 'outline-primary' ?>">
//                                 <i class="fas fa-list"></i> All
//                             </a>
//                         </div>
//                     </div>
//                     <div class="col-md-6 text-end">
//                         <button class="btn btn-success" onclick="markAllRead()">
//                             <i class="fas fa-check-double"></i> Mark All as Read
//                         </button>
//                     </div>
//                 </div>
//             </div>
//         </div>

//         <!-- Notifications List -->
//         <?php if (empty($notifications)): ?>
//             <div class="alert alert-info">
//                 <i class="fas fa-info-circle"></i> 
//                 <?= $statusFilter === 'unread' ? 'No unread notifications' : 'No notifications yet' ?>
//             </div>
//         <?php else: ?>
//             <div class="list-group">
//                 <?php foreach ($notifications as $notif): ?>
//                     <div class="list-group-item notification-item <?= $notif['status'] !== 'READ' ? 'unread' : '' ?>" 
//                          id="notif-<?= $notif['id'] ?>"
//                          onclick="toggleNotification(<?= $notif['id'] ?>)">
                        
//                         <div class="d-flex w-100 justify-content-between align-items-start">
//                             <div class="flex-grow-1">
//                                 <!-- Title and badges -->
//                                 <h6 class="mb-1 notification-title">
//                                     <?php if ($notif['status'] !== 'READ'): ?>
//                                         <span class="badge bg-primary me-2">NEW</span>
//                                     <?php endif; ?>
                                    
//                                     <span class="badge severity-badge bg-<?= $notif['severity'] === 'CRITICAL' ? 'danger' : ($notif['severity'] === 'WARNING' ? 'warning text-dark' : 'info') ?>">
//                                         <?= $notif['severity'] ?>
//                                     </span>
                                    
//                                     <?= htmlspecialchars($notif['message_title']) ?>
//                                 </h6>
                                
//                                 <!-- Meta info -->
//                                 <p class="mb-1 text-muted small">
//                                     <i class="fas fa-tag"></i> <?= htmlspecialchars($notif['rule_name']) ?>
//                                     | <i class="fas fa-cube"></i> <?= $notif['entity_type'] ?> #<?= $notif['entity_id'] ?>
//                                     <?php if ($notif['issue_status']): ?>
//                                         | <span class="badge bg-secondary"><?= $notif['issue_status'] ?></span>
//                                     <?php endif; ?>
//                                 </p>
                                
//                                 <!-- Expandable body -->
//                                 <div class="notification-body">
//                                     <p class="mb-2"><?= nl2br(htmlspecialchars($notif['message_body'])) ?></p>
                                    
//                                     <?php if ($notif['issue_id']): ?>
//                                         <a href="admin_alerts.php?issue_id=<?= $notif['issue_id'] ?>" 
//                                            class="btn btn-sm btn-outline-primary issue-link"
//                                            onclick="event.stopPropagation()">
//                                             <i class="fas fa-external-link-alt"></i> View Full Issue
//                                         </a>
//                                     <?php endif; ?>
//                                 </div>
//                             </div>
                            
//                             <!-- Time and actions -->
//                             <div class="text-end ms-3">
//                                 <div class="notification-time mb-2">
//                                     <i class="fas fa-clock"></i>
//                                     <?= timeAgo($notif['created_at']) ?>
//                                 </div>
                                
//                                 <?php if ($notif['status'] !== 'READ'): ?>
//                                     <button class="btn btn-sm btn-success" 
//                                             onclick="markAsRead(<?= $notif['id'] ?>); event.stopPropagation();">
//                                         <i class="fas fa-check"></i> Mark Read
//                                     </button>
//                                 <?php else: ?>
//                                     <span class="badge bg-secondary">
//                                         <i class="fas fa-check-double"></i> Read
//                                     </span>
//                                 <?php endif; ?>
//                             </div>
//                         </div>
//                     </div>
//                 <?php endforeach; ?>
//             </div>
//         <?php endif; ?>
//     </div>

//     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
//     <script>
//         function toggleNotification(id) {
//             const elem = document.getElementById('notif-' + id);
//             elem.classList.toggle('expanded');
            
//             // Auto-mark as read when expanded
//             if (elem.classList.contains('expanded') && elem.classList.contains('unread')) {
//                 setTimeout(() => markAsRead(id), 500);
//             }
//         }
        
//         function markAsRead(notifId) {
//             fetch('my_notifications.php', {
//                 method: 'POST',
//                 headers: {'Content-Type': 'application/x-www-form-urlencoded'},
//                 body: `action=mark_read&notification_id=${notifId}`
//             })
//             .then(r => r.json())
//             .then(data => {
//                 if (data.success) {
//                     const elem = document.getElementById('notif-' + notifId);
//                     elem.classList.remove('unread');
//                     elem.querySelector('.badge.bg-primary')?.remove();
                    
//                     // Update unread count
//                     location.reload();
//                 }
//             })
//             .catch(err => console.error('Error:', err));
//         }
        
//         function markAllRead() {
//             if (!confirm('Mark all notifications as read?')) return;
            
//             fetch('my_notifications.php', {
//                 method: 'POST',
//                 headers: {'Content-Type': 'application/x-www-form-urlencoded'},
//                 body: 'action=mark_all_read'
//             })
//             .then(r => r.json())
//             .then(data => {
//                 if (data.success) {
//                     location.reload();
//                 }
//             })
//             .catch(err => console.error('Error:', err));
//         }
//     </script>
// </body>
// </html>

// <?php
// /**
//  * Helper function to format time ago
//  */
// function timeAgo($datetime) {
//     $time = strtotime($datetime);
//     $diff = time() - $time;
    
//     if ($diff < 60) return 'Just now';
//     if ($diff < 3600) return floor($diff / 60) . 'm ago';
//     if ($diff < 86400) return floor($diff / 3600) . 'h ago';
//     if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    
//     return date('M j, Y', $time);
// }
?>