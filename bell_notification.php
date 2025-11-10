<?php
/**
 * Bell Notification Widget - Include this in your portal header
 * 
 * Usage:
 * <?php include 'bell_notification.php'; ?>
 * 
 * Or call the function:
 * <?php renderNotificationBell(); ?>
 */

if (!function_exists('renderNotificationBell')) {
    function renderNotificationBell($userId = null) {
        if (!$userId) {
            $userId = getCurrentUserId();
        }
        
        // Get unread count
        global $pdo;
        if (!isset($pdo)) {
            require_once 'config/database.php';
            $pdo = getDatabaseConnection();
        }
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE recipient_user_id = ? AND status != 'READ'
        ");
        $stmt->execute([$userId]);
        $unreadCount = $stmt->fetch()['count'];
        
        // Get recent notifications
        $stmt = $pdo->prepare("
            SELECT 
                n.id,
                n.message_title,
                n.created_at,
                n.status,
                nr.severity
            FROM notifications n
            JOIN notification_rules nr ON nr.id = n.rule_id
            WHERE n.recipient_user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $recentNotifications = $stmt->fetchAll();
        
        ?>
        
        <!-- Bell Notification Styles -->
        <style>
            .notification-bell {
                position: relative;
                display: inline-block;
                cursor: pointer;
                font-size: 1.5rem;
                color: #fff;
                padding: 0.5rem;
            }
            
            .notification-bell:hover {
                color: #ffc107;
            }
            
            .notification-badge {
                position: absolute;
                top: 0;
                right: 0;
                background-color: #dc3545;
                color: white;
                border-radius: 50%;
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
                font-weight: bold;
                min-width: 20px;
                text-align: center;
            }
            
            .notification-dropdown {
                display: none;
                position: absolute;
                top: 100%;
                right: 0;
                background: white;
                border: 1px solid #dee2e6;
                border-radius: 0.5rem;
                box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.15);
                width: 350px;
                max-height: 500px;
                overflow-y: auto;
                z-index: 1050;
            }
            
            .notification-dropdown.show {
                display: block;
            }
            
            .notification-dropdown-header {
                padding: 1rem;
                border-bottom: 1px solid #dee2e6;
                font-weight: bold;
                background-color: #f8f9fa;
            }
            
            .notification-dropdown-footer {
                padding: 0.75rem;
                border-top: 1px solid #dee2e6;
                text-align: center;
                background-color: #f8f9fa;
            }
            
            .notification-item-small {
                padding: 0.75rem 1rem;
                border-bottom: 1px solid #f0f0f0;
                cursor: pointer;
                transition: background-color 0.2s;
            }
            
            .notification-item-small:hover {
                background-color: #f8f9fa;
            }
            
            .notification-item-small.unread {
                background-color: #e7f3ff;
            }
            
            .notification-item-small .title {
                font-weight: 500;
                font-size: 0.9rem;
                margin-bottom: 0.25rem;
                color: #212529;
            }
            
            .notification-item-small .time {
                font-size: 0.75rem;
                color: #6c757d;
            }
            
            .notification-item-small .severity-dot {
                display: inline-block;
                width: 8px;
                height: 8px;
                border-radius: 50%;
                margin-right: 0.5rem;
            }
            
            .severity-INFO .severity-dot { background-color: #0dcaf0; }
            .severity-WARNING .severity-dot { background-color: #ffc107; }
            .severity-CRITICAL .severity-dot { background-color: #dc3545; }
            
            .empty-notifications {
                padding: 2rem 1rem;
                text-align: center;
                color: #6c757d;
            }
        </style>
        
        <!-- Bell Notification HTML -->
        <div class="notification-bell-container" style="position: relative;">
            <div class="notification-bell" id="notificationBell" onclick="toggleNotificationDropdown()">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="notification-badge" id="notificationBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                <?php endif; ?>
            </div>
            
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-dropdown-header">
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge bg-danger float-end"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="notification-list">
                    <?php if (empty($recentNotifications)): ?>
                        <div class="empty-notifications">
                            <i class="fas fa-inbox fa-2x mb-2"></i>
                            <p>No notifications yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentNotifications as $notif): ?>
                            <div class="notification-item-small severity-<?= $notif['severity'] ?> <?= $notif['status'] !== 'READ' ? 'unread' : '' ?>"
                                 onclick="viewNotification(<?= $notif['id'] ?>)">
                                <div class="title">
                                    <span class="severity-dot"></span>
                                    <?= htmlspecialchars($notif['message_title']) ?>
                                </div>
                                <div class="time">
                                    <i class="fas fa-clock"></i> <?= timeAgo($notif['created_at']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="notification-dropdown-footer">
                    <a href="my_notifications.php" class="btn btn-sm btn-primary">
                        View All Notifications
                    </a>
                    <?php if ($unreadCount > 0): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="markAllRead(); event.stopPropagation();">
                            Mark All Read
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Bell Notification Scripts -->
        <script>
            // Toggle dropdown
            function toggleNotificationDropdown() {
                const dropdown = document.getElementById('notificationDropdown');
                dropdown.classList.toggle('show');
            }
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const bell = document.getElementById('notificationBell');
                const dropdown = document.getElementById('notificationDropdown');
                
                if (!bell?.contains(event.target) && !dropdown?.contains(event.target)) {
                    dropdown?.classList.remove('show');
                }
            });
            
            // View notification
            function viewNotification(notifId) {
                // Mark as read
                fetch('api_endpoints.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=mark_as_read&notification_id=${notifId}`
                }).then(() => {
                    // Redirect to full notifications page
                    window.location.href = 'my_notifications.php';
                });
            }
            
            // Mark all as read
            function markAllRead() {
                if (!confirm('Mark all notifications as read?')) return;
                
                fetch('api_endpoints.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=mark_all_read'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
            
            // Auto-refresh unread count every 30 seconds
            setInterval(function() {
                fetch('api_endpoints.php?action=get_unread_count')
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const badge = document.getElementById('notificationBadge');
                            if (data.count > 0) {
                                if (badge) {
                                    badge.textContent = data.count > 99 ? '99+' : data.count;
                                } else {
                                    // Create badge if it doesn't exist
                                    const newBadge = document.createElement('span');
                                    newBadge.className = 'notification-badge';
                                    newBadge.id = 'notificationBadge';
                                    newBadge.textContent = data.count > 99 ? '99+' : data.count;
                                    document.getElementById('notificationBell').appendChild(newBadge);
                                }
                            } else {
                                badge?.remove();
                            }
                        }
                    });
            }, 30000); // 30 seconds
        </script>
        
        <?php
    }
    
    // Helper function for time ago
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return floor($diff / 86400) . 'd ago';
        
        return date('M j', $time);
    }
}

// Auto-render if this file is included directly
if (basename($_SERVER['PHP_SELF']) !== 'bell_notification.php') {
    renderNotificationBell();
}
?>