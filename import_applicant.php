<?php
session_start();
require_once 'config/db.php';

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($csrfToken === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo 'การยืนยันไม่ถูกต้อง';
    exit;
}

$exam_year = trim((string) ($_POST['exam_year'] ?? ''));
$fileInfo = $_FILES['file'] ?? null;

if ($exam_year === '') {
    http_response_code(400);
    echo 'กรุณาระบุปีสอบ';
    exit;
}

if (!$fileInfo || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'ไม่พบไฟล์ที่อัปโหลด';
    exit;
}

$filePath = $fileInfo['tmp_name'] ?? '';
$fileName = $fileInfo['name'] ?? '';
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$readCsvRows = static function (string $path): array {
    $rows = [];
    if (($handle = fopen($path, 'r')) === false) {
        return $rows;
    }

    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $rows[] = $data;
    }

    fclose($handle);
    return $rows;
};

$readXlsxRows = static function (string $path): array {
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $shared = simplexml_load_string($sharedStringsXml);
        if ($shared && isset($shared->si)) {
            foreach ($shared->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                    continue;
                }
                $text = '';
                if (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        return [];
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet || !isset($sheet->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowValues = [];
        foreach ($row->c as $cell) {
            $cellRef = (string) $cell['r'];
            if ($cellRef === '') {
                continue;
            }

            if (!preg_match('/^([A-Z]+)(\d+)$/i', $cellRef, $matches)) {
                continue;
            }

            $colLetters = strtoupper($matches[1]);
            $colIndex = 0;
            for ($i = 0; $i < strlen($colLetters); $i++) {
                $colIndex = $colIndex * 26 + (ord($colLetters[$i]) - 64);
            }
            $colIndex -= 1;

            $cellType = (string) $cell['t'];
            $value = '';
            if ($cellType === 's') {
                $sharedIndex = (int) ($cell->v ?? 0);
                $value = $sharedStrings[$sharedIndex] ?? '';
            } elseif ($cellType === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
            } else {
                $value = (string) ($cell->v ?? '');
            }

            $rowValues[$colIndex] = $value;
        }

        if ($rowValues === []) {
            continue;
        }

        ksort($rowValues);
        $rows[] = array_values($rowValues);
    }

    return $rows;
};

$rows = [];
if ($extension === 'csv') {
    $rows = $readCsvRows($filePath);
} elseif ($extension === 'xlsx') {
    $rows = $readXlsxRows($filePath);
} else {
    http_response_code(400);
    echo 'รองรับเฉพาะไฟล์ CSV หรือ Excel (.xlsx)';
    exit;
}

if ($rows === []) {
    http_response_code(400);
    echo 'ไม่พบข้อมูลในไฟล์';
    exit;
}

array_shift($rows); // ข้าม header

$sql = "INSERT INTO applicantname 
    (exam_year, id, idcode, prefix, firstname, lastname, score)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        idcode = VALUES(idcode),
        prefix = VALUES(prefix),
        firstname = VALUES(firstname),
        lastname = VALUES(lastname),
        score = VALUES(score)";

$stmt = $conn->prepare($sql);

$inserted = 0;
$updated = 0;
$skipped = 0;

foreach ($rows as $data) {
    $data = array_map('trim', $data);
    if (!isset($data[0]) || $data[0] === '' || !is_numeric($data[0])) {
        $skipped++;
        continue;
    }
    if (!isset($data[2]) || $data[2] === '') {
        $skipped++;
        continue;
    }
    $rawScore = $data[5] ?? '';
    $rawScore = is_string($rawScore) ? trim($rawScore) : $rawScore;
    $score = ($rawScore === '' || !is_numeric($rawScore)) ? null : $rawScore;
    $stmt->execute([
        $exam_year,
        $data[0], // id
        $data[1] ?? '', // idcode
        $data[2] ?? '', // prefix
        $data[3] ?? '', // firstname
        $data[4] ?? '', // lastname
        $score, // score
    ]);

    $affected = $stmt->rowCount();
    if ($affected >= 2) {
        $updated++;
    } elseif ($affected === 1) {
        $inserted++;
    }
}

$_SESSION['import_result'] = "นำเข้าข้อมูลเรียบร้อย (เพิ่มใหม่ {$inserted} รายการ, อัปเดต {$updated} รายการ, ข้าม {$skipped} รายการ)";
header('Location: import_gptV1.php');
exit;
