<?php
require_once 'config/db.php';

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
    VALUES (?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

foreach ($rows as $data) {
    $data = array_map('trim', $data);
    if (!isset($data[0]) || $data[0] === '' || !is_numeric($data[0])) {
        continue;
    }
    $stmt->execute([
        $exam_year,
        $data[0], // id
        $data[1] ?? '', // idcode
        $data[2] ?? '', // prefix
        $data[3] ?? '', // firstname
        $data[4] ?? '', // lastname
        $data[5] ?? '', // score
    ]);
}

echo 'นำเข้าข้อมูลเรียบร้อย';
