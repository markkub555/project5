<?php

declare(strict_types=1);

function ensureAuditLogIndexes(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $existingIndexes = [];
        $stmt = $conn->query('SHOW INDEX FROM audit_logs');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $indexRow) {
            $indexName = (string) ($indexRow['Key_name'] ?? '');
            if ($indexName !== '') {
                $existingIndexes[$indexName] = true;
            }
        }

        $requiredIndexes = [
            'idx_audit_logs_action_id' => 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_action_id (action, id)',
            'idx_audit_logs_username_id' => 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_username_id (username, id)',
            'idx_audit_logs_ip_created_at' => 'ALTER TABLE audit_logs ADD INDEX idx_audit_logs_ip_created_at (ip_address, created_at)',
        ];

        foreach ($requiredIndexes as $indexName => $sql) {
            if (!isset($existingIndexes[$indexName])) {
                $conn->exec($sql);
            }
        }
    } catch (Throwable $e) {
        // Keep app working even if index creation fails.
    }
}

function cleanupOldAuditLogs(PDO $conn, int $retentionDays = 180): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $retentionDays = max(30, $retentionDays);
    $runtimeDir = function_exists('runtimeStorageDir')
        ? runtimeStorageDir()
        : dirname(__DIR__, 2) . '/storage/runtime';
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0775, true);
    }
    $cleanupMarker = rtrim($runtimeDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'project5_audit_cleanup_' . date('Ymd') . '.lock';

    if (is_file($cleanupMarker)) {
        return;
    }

    try {
        $conn->exec(
            'DELETE FROM audit_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $retentionDays . ' DAY)'
        );
        @file_put_contents($cleanupMarker, (string) time(), LOCK_EX);
    } catch (Throwable $e) {
        // Keep app working even if cleanup fails.
    }
}

function ensureAuditLogSchema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NULL,
                username VARCHAR(255) NULL,
                role VARCHAR(50) NULL,
                action VARCHAR(100) NOT NULL,
                target_type VARCHAR(100) NULL,
                target_id VARCHAR(255) NULL,
                details TEXT NULL,
                ip_address VARCHAR(64) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_audit_logs_created_at (created_at),
                KEY idx_audit_logs_action (action),
                KEY idx_audit_logs_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        ensureAuditLogIndexes($conn);
        cleanupOldAuditLogs($conn, 180);
    } catch (Throwable $e) {
        // Keep app working even if audit log creation fails.
    }
}

function auditLog(
    PDO $conn,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    ?array $details = null,
    ?int $userId = null,
    ?string $username = null,
    ?string $role = null
): void {
    try {
        ensureAuditLogSchema($conn);

        $stmt = $conn->prepare(
            'INSERT INTO audit_logs
                (user_id, username, role, action, target_type, target_id, details, ip_address)
             VALUES
                (:user_id, :username, :role, :action, :target_type, :target_id, :details, :ip_address)'
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':username' => $username,
            ':role' => $role,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':details' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip_address' => function_exists('clientIpAddress') ? clientIpAddress() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log: ' . $e->getMessage());
    }
}
