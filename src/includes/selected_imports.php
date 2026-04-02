<?php

declare(strict_types=1);

function ensureSelectedImportsSchema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS selected_imports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                exam_year VARCHAR(50) NOT NULL,
                row_no INT NOT NULL,
                idcode VARCHAR(50) NOT NULL,
                prefix VARCHAR(100) DEFAULT NULL,
                firstname VARCHAR(255) DEFAULT NULL,
                lastname VARCHAR(255) DEFAULT NULL,
                score DECIMAL(10,2) DEFAULT NULL,
                remark VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_selected_imports_exam_idcode (exam_year, idcode),
                KEY idx_selected_imports_exam_row (exam_year, row_no),
                KEY idx_selected_imports_exam_score (exam_year, score, row_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    } catch (Throwable $e) {
        // Keep the app working even if migration fails.
    }
}

