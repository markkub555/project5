<?php

declare(strict_types=1);

function applicantNoteColumnMap(): array
{
    return [
        'submit_doc_note' => 'submit_doc',
        'lab_check_note' => 'lab_check',
        'swim_test_note' => 'swim_test',
        'run_test_note' => 'run_test',
        'station3_test_note' => 'station3_test',
        'hospital_check_note' => 'hospital_check',
        'fingerprint_check_note' => 'fingerprint_check',
        'background_check_note' => 'background_check',
        'interview_note' => 'interview',
        'militarydoc_note' => 'militarydoc',
    ];
}

function noteColumnToStageKey(string $noteColumn): string
{
    $map = applicantNoteColumnMap();
    return $map[$noteColumn] ?? preg_replace('/_note$/', '', $noteColumn);
}

function ensureApplicantNotesSchema(PDO $conn): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [
        'has_table' => false,
        'migrated' => false,
    ];

    try {
        $dbNameStmt = $conn->query('SELECT DATABASE()');
        $dbName = (string) $dbNameStmt->fetchColumn();
        if ($dbName === '') {
            return $cache;
        }

        $tableExistsStmt = $conn->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table'
        );
        $tableExistsStmt->execute([':db' => $dbName, ':table' => 'applicant_notes']);
        $tableExists = (int) $tableExistsStmt->fetchColumn() > 0;

        if (!$tableExists) {
            $conn->exec(
                'CREATE TABLE applicant_notes (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    exam_year VARCHAR(50) NOT NULL,
                    applicant_id VARCHAR(50) NOT NULL,
                    stage_key VARCHAR(50) NOT NULL,
                    note TEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_applicant_notes (exam_year, applicant_id, stage_key),
                    KEY idx_applicant_notes_lookup (exam_year, stage_key, applicant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
            $tableExists = true;
        }

        $cache['has_table'] = $tableExists;
        if (!$tableExists) {
            return $cache;
        }

        $conn->exec('ALTER TABLE applicant_notes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

        $columnExists = static function (string $column) use ($conn, $dbName): bool {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute([':db' => $dbName, ':table' => 'applicantname', ':column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        };

        $noteMap = applicantNoteColumnMap();
        $hasLegacyColumns = false;
        foreach ($noteMap as $legacyColumn => $stageKey) {
            if (!$columnExists($legacyColumn)) {
                continue;
            }

            $hasLegacyColumns = true;
            $quotedColumn = '`' . $legacyColumn . '`';
            $insertSql = "
                INSERT INTO applicant_notes (exam_year, applicant_id, stage_key, note)
                SELECT exam_year, CAST(id AS CHAR), :stage_key, TRIM($quotedColumn)
                FROM applicantname
                WHERE $quotedColumn IS NOT NULL
                  AND TRIM($quotedColumn) <> ''
                  AND id <> 'id'
                ON DUPLICATE KEY UPDATE
                    note = VALUES(note),
                    updated_at = CURRENT_TIMESTAMP
            ";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->execute([':stage_key' => $stageKey]);
        }

        if ($hasLegacyColumns) {
            foreach (array_keys($noteMap) as $legacyColumn) {
                if ($columnExists($legacyColumn)) {
                    $conn->exec('ALTER TABLE applicantname DROP COLUMN `' . $legacyColumn . '`');
                }
            }
        }

        $cache['migrated'] = true;
    } catch (Throwable $e) {
        return $cache;
    }

    return $cache;
}
