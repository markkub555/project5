<?php

declare(strict_types=1);

function ensureApplicantSchema(PDO $conn): void
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
            $stmt->execute([':db' => $dbName, ':table' => 'applicantname', ':column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
        };

        $hasIndex = static function (string $index) use ($conn, $dbName): bool {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :idx'
            );
            $stmt->execute([':db' => $dbName, ':table' => 'applicantname', ':idx' => $index]);
            return (int) $stmt->fetchColumn() > 0;
        };

        if (!$hasColumn('id_num')) {
            $conn->exec('ALTER TABLE applicantname ADD COLUMN id_num BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(id AS UNSIGNED)) STORED');
        }

        if (!$hasIndex('idx_applicant_exam_idnum')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_idnum ON applicantname (exam_year, id_num)');
        }
        if (!$hasIndex('idx_applicant_exam_idcode')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_idcode ON applicantname (exam_year, idcode)');
        }
        if (!$hasIndex('idx_applicant_exam_firstname')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_firstname ON applicantname (exam_year, firstname)');
        }
        if (!$hasIndex('idx_applicant_exam_lastname')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_lastname ON applicantname (exam_year, lastname)');
        }
        if (!$hasIndex('idx_applicant_exam_allname')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_allname ON applicantname (exam_year, allname)');
        }
        if (!$hasIndex('idx_applicant_exam_score')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_score ON applicantname (exam_year, score)');
        }
    } catch (Throwable $e) {
        // If schema changes fail, keep the app running with existing schema.
        return;
    }
}
