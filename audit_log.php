<?php
// audit_log.php
function auditLog($pdo, $userId, $action, $entityType, $entityId = null, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId ? (int)$userId : null,
            substr($action, 0, 100),
            substr($entityType, 0, 50),
            $entityId ? substr((string)$entityId, 0, 255) : null,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? 'CLI',
            $_SERVER['HTTP_USER_AGENT'] ?? 'CLI'
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}