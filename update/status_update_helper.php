<?php

declare(strict_types=1);

function handleStatusUpdate(
    PDO $conn,
    string $statusColumn,
    string $noteColumn,
    array $requiredNoteStatuses = ['F'],
    ?array $editableNoteStatuses = null
): void
{
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
             SET $statusColumn = :status,
                 $noteColumn = :note
             WHERE id = :id"
        );
        $candidateIds = array_values(array_unique(array_column($candidates, 'id')));
        $guardPlaceholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $guardSql = 'SELECT id, ' . implode(', ', $stageOrder) . " FROM applicantname WHERE id IN ($guardPlaceholders)";
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
                ':note' => $note,
                ':id' => $id,
            ]);

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
