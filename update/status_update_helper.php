<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/applicant_notes.php';

function handleStatusUpdate(
    PDO $conn,
    string $statusColumn,
    string $noteColumn,
    array $requiredNoteStatuses = ['F'],
    ?array $editableNoteStatuses = null
): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['user_login'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($csrfToken === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        return;
    }

    header('Content-Type: application/json; charset=UTF-8');

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $statusColumn) || !preg_match('/^[a-zA-Z0-9_]+$/', $noteColumn)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Invalid config']);
        return;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload) || $payload === []) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid payload']);
        return;
    }

    $allowedStatus = ['W', 'P', 'F'];
    $required = array_values(array_filter(array_map('strval', $requiredNoteStatuses)));
    $editable = $editableNoteStatuses === null
        ? $required
        : array_values(array_filter(array_map('strval', $editableNoteStatuses)));
    ensureApplicantNotesSchema($conn);
    $stageKey = noteColumnToStageKey($noteColumn);
    $stageOrder = [
        'submit_doc',
        'lab_check',
        'swim_test',
        'run_test',
        'station3_test',
        'hospital_check',
        'fingerprint_check',
        'background_check',
        'interview',
        'militarydoc',
    ];
    $currentStageIndex = array_search($statusColumn, $stageOrder, true);
    if ($currentStageIndex === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Invalid stage']);
        return;
    }

    $normalize = static function ($value, int $maxLength): string {
        $text = trim((string) $value);
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return $text;
    };

    try {
        $candidates = [];
        foreach ($payload as $row) {
            $id = (int) ($row['id'] ?? 0);
            $status = strtoupper(trim((string) ($row['status'] ?? '')));
            if ($id <= 0 || !in_array($status, $allowedStatus, true)) {
                continue;
            }

            $candidates[] = [
                'id' => $id,
                'status' => $status,
                'note' => $row['note'] ?? '',
            ];
        }

        if ($candidates === []) {
            echo json_encode([
                'success' => true,
                'updated' => 0,
            ]);
            return;
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "UPDATE applicantname
             SET $statusColumn = :status
             WHERE id = :id"
        );
        $noteUpsertStmt = $conn->prepare(
            'INSERT INTO applicant_notes (exam_year, applicant_id, stage_key, note)
             VALUES (:exam_year, :applicant_id, :stage_key, :note)
             ON DUPLICATE KEY UPDATE
                note = VALUES(note),
                updated_at = CURRENT_TIMESTAMP'
        );
        $noteDeleteStmt = $conn->prepare(
            'DELETE FROM applicant_notes
             WHERE exam_year = :exam_year
               AND applicant_id = :applicant_id
               AND stage_key = :stage_key'
        );
        $candidateIds = array_values(array_unique(array_column($candidates, 'id')));
        $guardPlaceholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $guardSql = 'SELECT id, exam_year, ' . implode(', ', $stageOrder) . " FROM applicantname WHERE id IN ($guardPlaceholders)";
        $stageGuardStmt = $conn->prepare($guardSql);
        foreach ($candidateIds as $idx => $candidateId) {
            $stageGuardStmt->bindValue($idx + 1, $candidateId, PDO::PARAM_INT);
        }
        $stageGuardStmt->execute();
        $guardRows = $stageGuardStmt->fetchAll(PDO::FETCH_ASSOC);
        $guardById = [];
        foreach ($guardRows as $guardRow) {
            $guardById[(int) ($guardRow['id'] ?? 0)] = $guardRow;
        }

        $updated = 0;

        foreach ($candidates as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            $guardRow = $guardById[$id] ?? [];
            if ($guardRow === []) {
                continue;
            }

            $eligibleForCurrentStage = false;

            $firstFailIndex = null;
            foreach ($stageOrder as $idx => $stage) {
                if (($guardRow[$stage] ?? '') === 'F') {
                    $firstFailIndex = $idx;
                    break;
                }
            }

            if ($firstFailIndex !== null) {
                $eligibleForCurrentStage = ($firstFailIndex === $currentStageIndex);
            } else {
                // ยังไม่มี F เลย ให้แก้ได้ทุกด่าน
                $eligibleForCurrentStage = true;
            }

            if (!$eligibleForCurrentStage) {
                continue;
            }

            $note = '';
            if (in_array($status, $editable, true)) {
                $note = $normalize($row['note'], 255);
                if (in_array($status, $required, true) && $note === '') {
                    continue;
                }
            }

            $stmt->execute([
                ':status' => $status,
                ':id' => $id,
            ]);

            $examYear = trim((string) ($guardRow['exam_year'] ?? ''));
            if ($examYear !== '') {
                if ($note !== '') {
                    $noteUpsertStmt->execute([
                        ':exam_year' => $examYear,
                        ':applicant_id' => (string) $id,
                        ':stage_key' => $stageKey,
                        ':note' => $note,
                    ]);
                } else {
                    $noteDeleteStmt->execute([
                        ':exam_year' => $examYear,
                        ':applicant_id' => (string) $id,
                        ':stage_key' => $stageKey,
                    ]);
                }
            }

            $updated += $stmt->rowCount();
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'updated' => $updated,
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
        ]);
    }
}
