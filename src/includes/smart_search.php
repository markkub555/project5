<?php

declare(strict_types=1);

function parseSmartSearch(string $rawSearch, ?string $defaultStageColumn = null): array
{
    $search = trim($rawSearch);
    if ($search === '') {
        return [
            'raw' => '',
            'text' => '',
            'exam_year' => null,
            'status' => null,
            'stage_column' => $defaultStageColumn,
        ];
    }

    $working = mb_strtolower($search);
    $examYear = null;
    $status = null;
    $stageColumn = $defaultStageColumn;

    if (preg_match('/(^|\s)(\d{1,4})(?=\s|$)/u', $working, $yearMatch) === 1) {
        $examYear = $yearMatch[2];
        $working = preg_replace('/(^|\s)' . preg_quote($yearMatch[2], '/') . '(?=\s|$)/u', ' ', $working, 1) ?? $working;
    }

    $statusPatterns = [
        'F' => ['ไม่ผ่าน', 'ไม่ อนุมัติ', 'ไม่อนุมัติ', 'ตก'],
        'P' => ['ผ่าน', 'อนุมัติ'],
        'W' => ['รอดำเนินการ', 'รอ ดำเนินการ', 'รอยืนยัน', 'รอ'],
    ];

    foreach ($statusPatterns as $mappedStatus => $patterns) {
        foreach ($patterns as $pattern) {
            if (mb_strpos($working, $pattern) !== false) {
                $status = $mappedStatus;
                $working = str_replace($pattern, ' ', $working);
                break 2;
            }
        }
    }

    $stagePatterns = [
        'submit_doc' => ['ยื่นเอกสาร', 'เอกสาร'],
        'lab_check' => ['ตรวจ lab', 'lab'],
        'swim_test' => ['ว่ายน้ำ', 'ว่าย'],
        'run_test' => ['วิ่ง'],
        'station3_test' => ['๓ สถานี', '3 สถานี', '๓สถานี', '3สถานี'],
        'hospital_check' => ['ตรวจร่างกาย', 'รพ.ตร.', 'รพตร', 'โรงพยาบาล'],
        'fingerprint_check' => ['ลายนิ้วมือ', 'ศพฐ.'],
        'background_check' => ['ประวัติทางคดี', 'ประวัติ', 'คดี', 'background'],
        'interview' => ['สัมภาษณ์'],
        'militarydoc' => ['เอกสารทางทหาร', 'เอกสารทหาร', 'ทหาร'],
    ];

    foreach ($stagePatterns as $column => $patterns) {
        foreach ($patterns as $pattern) {
            if (mb_strpos($working, $pattern) !== false) {
                $stageColumn = $column;
                $working = str_replace($pattern, ' ', $working);
                break 2;
            }
        }
    }

    $text = trim(preg_replace('/\s+/u', ' ', $working) ?? $working);

    return [
        'raw' => $search,
        'text' => $text,
        'exam_year' => $examYear,
        'status' => $status,
        'stage_column' => $stageColumn,
    ];
}

function appendApplicantTextSearch(
    array &$whereParts,
    array &$queryParams,
    string $searchText,
    string $alias = 'applicantname'
): void {
    if ($searchText === '') {
        return;
    }

    $qualified = $alias !== '' ? $alias . '.' : '';
    $searchNormalized = preg_replace('/\s+/u', ' ', $searchText) ?? $searchText;
    $searchCompact = preg_replace('/\s+/u', '', $searchText) ?? $searchText;

    $whereParts[] = "(
        {$qualified}idcode LIKE :search_idcode
        OR {$qualified}prefix LIKE :search_prefix
        OR {$qualified}firstname LIKE :search_firstname
        OR {$qualified}lastname LIKE :search_lastname
        OR CONCAT_WS(' ', {$qualified}prefix, {$qualified}firstname, {$qualified}lastname) LIKE :search_fullname
        OR REPLACE(CONCAT_WS('', {$qualified}prefix, {$qualified}firstname, {$qualified}lastname), ' ', '') LIKE :search_compact
    )";

    $queryParams[':search_idcode'] = '%' . $searchNormalized . '%';
    $queryParams[':search_prefix'] = '%' . $searchNormalized . '%';
    $queryParams[':search_firstname'] = '%' . $searchNormalized . '%';
    $queryParams[':search_lastname'] = '%' . $searchNormalized . '%';
    $queryParams[':search_fullname'] = '%' . $searchNormalized . '%';
    $queryParams[':search_compact'] = '%' . $searchCompact . '%';
}

function applyApplicantSmartSearch(
    array &$whereParts,
    array &$queryParams,
    string $rawSearch,
    string $currentExamYear,
    ?string $defaultStageColumn = null,
    ?string $overallStatusExpr = null,
    string $alias = 'applicantname'
): array {
    $parsed = parseSmartSearch($rawSearch, $defaultStageColumn);
    $effectiveExamYear = $parsed['exam_year'] !== null ? $parsed['exam_year'] : $currentExamYear;
    $queryParams[':exam_year'] = $effectiveExamYear;

    $qualified = $alias !== '' ? $alias . '.' : '';
    if ($parsed['stage_column'] !== null && $parsed['status'] !== null) {
        $whereParts[] = "{$qualified}{$parsed['stage_column']} = :smart_search_status";
        $queryParams[':smart_search_status'] = $parsed['status'];
    } elseif ($parsed['status'] !== null) {
        if ($overallStatusExpr !== null && $overallStatusExpr !== '') {
            $whereParts[] = "($overallStatusExpr) = :smart_search_status";
            $queryParams[':smart_search_status'] = $parsed['status'];
        } elseif ($defaultStageColumn !== null && $defaultStageColumn !== '') {
            $whereParts[] = "{$qualified}{$defaultStageColumn} = :smart_search_status";
            $queryParams[':smart_search_status'] = $parsed['status'];
        }
    }

    appendApplicantTextSearch($whereParts, $queryParams, $parsed['text'], $alias);

    return [
        'effective_exam_year' => $effectiveExamYear,
        'parsed' => $parsed,
    ];
}

function buildOverallStatusExpr(array $fields, string $alias = ''): string
{
    $qualified = static fn(string $field): string => $alias !== '' ? $alias . '.' . $field : $field;
    $allPassParts = [];
    $hasFailParts = [];
    foreach ($fields as $field) {
        $allPassParts[] = $qualified($field) . " = 'P'";
        $hasFailParts[] = $qualified($field) . " = 'F'";
    }

    return "CASE
        WHEN " . implode(' OR ', $hasFailParts) . " THEN 'F'
        WHEN " . implode(' AND ', $allPassParts) . " THEN 'P'
        ELSE 'W'
    END";
}
