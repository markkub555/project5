<?php

declare(strict_types=1);

function ensureApplicantSchema(PDO $conn): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [
        'has_id_num' => false,
        'has_score_num' => false,
        'has_allname_calc' => false,
    ];

    try {
        $dbNameStmt = $conn->query('SELECT DATABASE()');
        $dbName = (string) $dbNameStmt->fetchColumn();
        if ($dbName === '') {
            return $cache;
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
        if (!$hasColumn('score_num')) {
            $conn->exec("ALTER TABLE applicantname ADD COLUMN score_num DECIMAL(10,2) GENERATED ALWAYS AS (CAST(NULLIF(score, '') AS DECIMAL(10,2))) STORED");
        }
        if (!$hasColumn('allname_calc')) {
            $conn->exec(
                "ALTER TABLE applicantname
                 ADD COLUMN allname_calc VARCHAR(1) GENERATED ALWAYS AS (
                    CASE
                        WHEN submit_doc = 'F'
                          OR lab_check = 'F'
                          OR swim_test = 'F'
                          OR run_test = 'F'
                          OR station3_test = 'F'
                          OR hospital_check = 'F'
                          OR fingerprint_check = 'F'
                          OR background_check = 'F'
                          OR interview = 'F'
                          OR militarydoc = 'F' THEN 'F'
                        WHEN submit_doc = 'P'
                          AND lab_check = 'P'
                          AND swim_test = 'P'
                          AND run_test = 'P'
                          AND station3_test = 'P'
                          AND hospital_check = 'P'
                          AND fingerprint_check = 'P'
                          AND background_check = 'P'
                          AND interview = 'P'
                          AND militarydoc = 'P' THEN 'P'
                        ELSE 'W'
                    END
                 ) STORED"
            );
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
        if (!$hasIndex('idx_applicant_exam_allname_calc')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_allname_calc ON applicantname (exam_year, allname_calc)');
        }
        if (!$hasIndex('idx_applicant_exam_score')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_score ON applicantname (exam_year, score)');
        }
        if (!$hasIndex('idx_applicant_exam_score_num')) {
            $conn->exec('CREATE INDEX idx_applicant_exam_score_num ON applicantname (exam_year, score_num, id_num)');
        }

        $cache['has_id_num'] = $hasColumn('id_num');
        $cache['has_score_num'] = $hasColumn('score_num');
        $cache['has_allname_calc'] = $hasColumn('allname_calc');
    } catch (Throwable $e) {
        // Keep the app working even when schema changes cannot be applied.
    }

    return $cache;
}

function applicantOrderExpr(array $schema, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (!empty($schema['has_id_num'])) {
        return $prefix . 'id_num';
    }

    return 'CAST(' . $prefix . 'id AS UNSIGNED)';
}

function applicantIdTextExpr(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return 'CAST(' . $prefix . 'id AS CHAR CHARACTER SET utf8mb4)';
}

function applicantScoreExpr(array $schema, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (!empty($schema['has_score_num'])) {
        return $prefix . 'score_num';
    }

    return "CAST(NULLIF(" . $prefix . "score, '') AS DECIMAL(10,2))";
}

function applicantAllnameExpr(array $schema, string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    if (!empty($schema['has_allname_calc'])) {
        return $prefix . 'allname_calc';
    }

    return "CASE
        WHEN " . $prefix . "submit_doc = 'F'
          OR " . $prefix . "lab_check = 'F'
          OR " . $prefix . "swim_test = 'F'
          OR " . $prefix . "run_test = 'F'
          OR " . $prefix . "station3_test = 'F'
          OR " . $prefix . "hospital_check = 'F'
          OR " . $prefix . "fingerprint_check = 'F'
          OR " . $prefix . "background_check = 'F'
          OR " . $prefix . "interview = 'F'
          OR " . $prefix . "militarydoc = 'F' THEN 'F'
        WHEN " . $prefix . "submit_doc = 'P'
          AND " . $prefix . "lab_check = 'P'
          AND " . $prefix . "swim_test = 'P'
          AND " . $prefix . "run_test = 'P'
          AND " . $prefix . "station3_test = 'P'
          AND " . $prefix . "hospital_check = 'P'
          AND " . $prefix . "fingerprint_check = 'P'
          AND " . $prefix . "background_check = 'P'
          AND " . $prefix . "interview = 'P'
          AND " . $prefix . "militarydoc = 'P' THEN 'P'
        ELSE 'W'
    END";
}
