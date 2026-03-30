<?php

declare(strict_types=1);

function ensureUserResetSchema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbNameStmt = $conn->query('SELECT DATABASE()');
        $dbName = (string) $dbNameStmt->fetchColumn();
        if ($dbName === '') {
            return;
        }

        $hasColumn = static function (string $column) use ($conn, $dbName): bool {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([
                ':db' => $dbName,
                ':table' => 'users',
                ':column' => $column,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        };

        $hasIndex = static function (string $index) use ($conn, $dbName): bool {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :idx'
            );
            $stmt->execute([
                ':db' => $dbName,
                ':table' => 'users',
                ':idx' => $index,
            ]);
            return (int) $stmt->fetchColumn() > 0;
        };

        if (!$hasColumn('email')) {
            $conn->exec('ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER username');
        }
        if (!$hasColumn('token')) {
            $conn->exec('ALTER TABLE users ADD COLUMN token VARCHAR(255) NULL AFTER email');
        }
        if (!$hasColumn('expire')) {
            $conn->exec('ALTER TABLE users ADD COLUMN expire DATETIME NULL AFTER token');
        }
        if (!$hasColumn('code')) {
            $conn->exec('ALTER TABLE users ADD COLUMN code VARCHAR(255) NULL AFTER expire');
        }
        if (!$hasColumn('userstatus')) {
            $conn->exec("ALTER TABLE users ADD COLUMN userstatus VARCHAR(1) NULL AFTER code");
        }

        if (!$hasIndex('idx_users_email')) {
            $conn->exec('CREATE INDEX idx_users_email ON users (email)');
        }
        if (!$hasIndex('idx_users_token')) {
            $conn->exec('CREATE INDEX idx_users_token ON users (token)');
        }
        if (!$hasIndex('idx_users_userstatus')) {
            $conn->exec('CREATE INDEX idx_users_userstatus ON users (userstatus)');
        }

        $conn->exec("UPDATE users SET userstatus = 'P' WHERE userstatus IS NULL OR TRIM(userstatus) = ''");
    } catch (Throwable $e) {
        return;
    }
}
