<?php

require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/selected_imports.php';
require_once __DIR__ . '/vendor/simplexls/SimpleXLS.php';

use Shuchkin\SimpleXLS;

if (!isset($_SESSION['user_login']) && !isset($_SESSION['admin_login'])) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}

ensureSelectedImportsSchema($conn);

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($csrfToken === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo 'การยืนยันไม่ถูกต้อง';
    exit;
}

$examYear = trim((string) ($_POST['exam_year'] ?? ($_SESSION['exam_year'] ?? '')));
$fileInfo = $_FILES['selected_file'] ?? null;

if ($examYear === '') {
    $_SESSION['selected_import_error'] = 'ไม่พบปีที่ใช้งานสำหรับนำเข้าผู้ได้รับการคัดเลือก';
    header('Location: selected.php');
    exit;
}

if (!$fileInfo || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['selected_import_error'] = 'กรุณาเลือกไฟล์ก่อนนำเข้า';
    header('Location: selected.php');
    exit;
}

$filePath = (string) ($fileInfo['tmp_name'] ?? '');
$fileName = (string) ($fileInfo['name'] ?? '');
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

$readCsvRows = static function (string $path): array {
    $rows = [];
    if (($handle = fopen($path, 'r')) === false) {
        return $rows;
    }

    while (($data = fgetcsv($handle, 0, ',')) !== false) {
        $rows[] = array_map(static fn($value): string => trim((string) $value), $data);
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
                    $sharedStrings[] = trim((string) $si->t);
                    continue;
                }
                $text = '';
                if (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $sharedStrings[] = trim($text);
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
            if ($cellRef === '' || !preg_match('/^([A-Z]+)(\d+)$/i', $cellRef, $matches)) {
                continue;
            }

            $colLetters = strtoupper($matches[1]);
            $colIndex = 0;
            for ($i = 0, $len = strlen($colLetters); $i < $len; $i++) {
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

            $rowValues[$colIndex] = trim($value);
        }

        if ($rowValues === []) {
            continue;
        }

        ksort($rowValues);
        $rows[] = array_values($rowValues);
    }

    return $rows;
};

$readXlsRows = static function (string $path): array {
    if (!class_exists(SimpleXLS::class)) {
        return [];
    }

    $xls = SimpleXLS::parse($path);
    if (!$xls) {
        return [];
    }

    $rows = [];
    foreach ($xls->rows() as $row) {
        $rows[] = array_map(static fn($value): string => trim((string) $value), $row);
    }

    return $rows;
};

$rows = [];
if ($extension === 'xls') {
    $rows = $readXlsRows($filePath);
} elseif ($extension === 'xlsx') {
    $rows = $readXlsxRows($filePath);
} elseif ($extension === 'csv') {
    $rows = $readCsvRows($filePath);
} else {
    $_SESSION['selected_import_error'] = 'รองรับเฉพาะไฟล์ .xls, .xlsx หรือ .csv';
    header('Location: selected.php');
    exit;
}

if ($rows === []) {
    $_SESSION['selected_import_error'] = 'ไม่สามารถอ่านข้อมูลจากไฟล์ที่นำเข้าได้';
    header('Location: selected.php');
    exit;
}

$headerRowIndex = null;
foreach ($rows as $index => $row) {
    $rowText = implode('|', array_map(static fn($value): string => trim((string) $value), $row));
    if (mb_stripos($rowText, 'รหัสประจำตัวสอบ') !== false && mb_stripos($rowText, 'คะแนน') !== false) {
        $headerRowIndex = $index;
        break;
    }
}

if ($headerRowIndex === null) {
    $_SESSION['selected_import_error'] = 'ไม่พบหัวตารางที่รองรับในไฟล์นี้';
    header('Location: selected.php');
    exit;
}

$header = $rows[$headerRowIndex];
$findHeaderIndex = static function (array $headerRow, array $keywords): ?int {
    foreach ($headerRow as $index => $value) {
        $text = trim((string) $value);
        foreach ($keywords as $keyword) {
            if ($text !== '' && mb_stripos($text, $keyword) !== false) {
                return $index;
            }
        }
    }

    return null;
};

$idcodeIndex = $findHeaderIndex($header, ['รหัสประจำตัวสอบ', 'เลขสอบ']);
$prefixIndex = $findHeaderIndex($header, ['คำนำหน้า']);
$firstnameIndex = $findHeaderIndex($header, ['ชื่อ']);
$lastnameIndex = $findHeaderIndex($header, ['ชื่อสกุล', 'นามสกุล']);
$scoreIndex = $findHeaderIndex($header, ['คะแนนรวม', 'คะแนน']);
$remarkIndex = $findHeaderIndex($header, ['หมายเหตุ']);

if ($idcodeIndex === null || $firstnameIndex === null || $lastnameIndex === null || $scoreIndex === null) {
    $_SESSION['selected_import_error'] = 'โครงคอลัมน์ในไฟล์ไม่ครบสำหรับนำเข้าผู้ได้รับการคัดเลือก';
    header('Location: selected.php');
    exit;
}

$parsedRows = [];
$rowNo = 0;
for ($i = $headerRowIndex + 1, $count = count($rows); $i < $count; $i++) {
    $row = $rows[$i];
    $idcode = trim((string) ($row[$idcodeIndex] ?? ''));
    $firstname = trim((string) ($row[$firstnameIndex] ?? ''));
    $lastname = trim((string) ($row[$lastnameIndex] ?? ''));
    $prefix = $prefixIndex !== null ? trim((string) ($row[$prefixIndex] ?? '')) : '';
    $scoreRaw = trim((string) ($row[$scoreIndex] ?? ''));
    $remark = $remarkIndex !== null ? trim((string) ($row[$remarkIndex] ?? '')) : '';

    if ($idcode === '' || $firstname === '' || $lastname === '') {
        continue;
    }

    $scoreSanitized = str_replace(',', '', $scoreRaw);
    $score = is_numeric($scoreSanitized) ? (float) $scoreSanitized : null;

    $rowNo++;
    $parsedRows[] = [
        'row_no' => $rowNo,
        'idcode' => $idcode,
        'prefix' => $prefix,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'score' => $score,
        'remark' => $remark,
    ];
}

if ($parsedRows === []) {
    $_SESSION['selected_import_error'] = 'ไม่พบข้อมูลผู้ได้รับการคัดเลือกในไฟล์นี้';
    header('Location: selected.php');
    exit;
}

$conn->beginTransaction();
try {
    $deleteStmt = $conn->prepare('DELETE FROM selected_imports WHERE exam_year = :exam_year');
    $deleteStmt->execute([':exam_year' => $examYear]);

    $insertStmt = $conn->prepare(
        'INSERT INTO selected_imports
            (exam_year, row_no, idcode, prefix, firstname, lastname, score, remark)
         VALUES
            (:exam_year, :row_no, :idcode, :prefix, :firstname, :lastname, :score, :remark)'
    );

    foreach ($parsedRows as $parsedRow) {
        $insertStmt->execute([
            ':exam_year' => $examYear,
            ':row_no' => $parsedRow['row_no'],
            ':idcode' => $parsedRow['idcode'],
            ':prefix' => $parsedRow['prefix'],
            ':firstname' => $parsedRow['firstname'],
            ':lastname' => $parsedRow['lastname'],
            ':score' => $parsedRow['score'],
            ':remark' => $parsedRow['remark'],
        ]);
    }

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['selected_import_error'] = 'นำเข้าไฟล์ผู้ได้รับการคัดเลือกไม่สำเร็จ';
    header('Location: selected.php');
    exit;
}

$_SESSION['selected_import_result'] = 'นำเข้าผู้ได้รับการคัดเลือกเรียบร้อย ' . number_format(count($parsedRows)) . ' รายการ';
auditLog(
    $conn,
    'import_selected_applicants',
    'exam_year',
    $examYear,
    ['rows' => count($parsedRows), 'extension' => $extension, 'filename' => $fileName],
    isset($_SESSION['admin_login']) ? (int) $_SESSION['admin_login'] : (int) ($_SESSION['user_login'] ?? 0),
    null,
    isset($_SESSION['admin_login']) ? 'admin' : 'user'
);
header('Location: selected.php');
exit;
