<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/ensure_applicant_schema.php';
require_once __DIR__ . '/includes/applicant_notes.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['exam_year'])) {
    header('Location: import_gptV1.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$applicantSchema = ensureApplicantSchema($conn);
ensureApplicantNotesSchema($conn);

$userProfile = getCurrentUserProfile($conn);
$examYear = (string) $_SESSION['exam_year'];

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$columnDefinitions = [
    'id' => ['label' => 'ลำดับ', 'kind' => 'text'],
    'idcode' => ['label' => 'เลขประจำตัวสอบ', 'kind' => 'text'],
    'prefix' => ['label' => 'คำนำหน้า', 'kind' => 'text'],
    'firstname' => ['label' => 'ชื่อ', 'kind' => 'text'],
    'lastname' => ['label' => 'นามสกุล', 'kind' => 'text'],
    'score' => ['label' => 'คะแนน', 'kind' => 'text'],
    'allname' => ['label' => 'สถานะรวม', 'kind' => 'status'],
    'submit_doc' => ['label' => 'ยื่นเอกสาร', 'kind' => 'status'],
    'submit_doc_note' => ['label' => 'หมายเหตุยื่นเอกสาร', 'kind' => 'text'],
    'lab_check' => ['label' => 'ตรวจ LAB', 'kind' => 'status'],
    'lab_check_note' => ['label' => 'หมายเหตุตรวจ LAB', 'kind' => 'text'],
    'swim_test' => ['label' => 'ว่ายน้ำ', 'kind' => 'status'],
    'swim_test_note' => ['label' => 'หมายเหตุว่ายน้ำ', 'kind' => 'text'],
    'run_test' => ['label' => 'วิ่ง', 'kind' => 'status'],
    'run_test_note' => ['label' => 'หมายเหตุวิ่ง', 'kind' => 'text'],
    'station3_test' => ['label' => '๓ สถานี', 'kind' => 'status'],
    'station3_test_note' => ['label' => 'หมายเหตุ ๓ สถานี', 'kind' => 'text'],
    'hospital_check' => ['label' => 'ตรวจร่างกาย รพ.ตร.', 'kind' => 'status'],
    'hospital_check_note' => ['label' => 'หมายเหตุตรวจร่างกาย รพ.ตร.', 'kind' => 'text'],
    'fingerprint_check' => ['label' => 'ลายนิ้วมือ ศพฐ.', 'kind' => 'status'],
    'fingerprint_check_note' => ['label' => 'หมายเหตุลายนิ้วมือ', 'kind' => 'text'],
    'background_check' => ['label' => 'ประวัติทางคดี', 'kind' => 'status'],
    'background_check_note' => ['label' => 'หมายเหตุประวัติทางคดี', 'kind' => 'text'],
    'interview' => ['label' => 'สัมภาษณ์', 'kind' => 'status'],
    'interview_note' => ['label' => 'หมายเหตุสัมภาษณ์', 'kind' => 'text'],
    'militarydoc' => ['label' => 'เอกสารทางทหาร', 'kind' => 'status'],
    'militarydoc_note' => ['label' => 'หมายเหตุเอกสารทางทหาร', 'kind' => 'text'],
];

$defaultColumns = ['id', 'idcode', 'prefix', 'firstname', 'lastname', 'score', 'allname'];
$selectedColumns = $_REQUEST['columns'] ?? $defaultColumns;
if (!is_array($selectedColumns)) {
    $selectedColumns = $defaultColumns;
}
$selectedColumns = array_values(array_intersect(array_keys($columnDefinitions), $selectedColumns));
if ($selectedColumns === []) {
    $selectedColumns = $defaultColumns;
}

$statusMap = [
    'W' => 'รอดำเนินการ',
    'P' => 'ผ่าน',
    'F' => 'ไม่ผ่าน',
    '' => '-',
];

$noteColumnMap = applicantNoteColumnMap();
$selectParts = [];
$joinParts = [];
foreach ($columnDefinitions as $column => $definition) {
    if (isset($noteColumnMap[$column])) {
        $stageKey = $noteColumnMap[$column];
        $alias = $column . '_row';
        $joinParts[] = "LEFT JOIN applicant_notes $alias
            ON CONVERT($alias.exam_year USING utf8mb4) = CONVERT(applicantname.exam_year USING utf8mb4)
           AND CONVERT($alias.applicant_id USING utf8mb4) = CONVERT(" . applicantIdTextExpr('applicantname') . " USING utf8mb4)
           AND $alias.stage_key = " . $conn->quote($stageKey);
        $selectParts[] = "$alias.note AS `$column`";
    } else {
        $selectParts[] = 'applicantname.`' . $column . '` AS `' . $column . '`';
    }
}

$sqlColumns = implode(', ', $selectParts);
$sqlJoins = implode("\n", $joinParts);
$listStmt = $conn->prepare("SELECT $sqlColumns FROM applicantname\n$sqlJoins\nWHERE applicantname.id <> 'id' AND applicantname.exam_year = :exam_year ORDER BY " . applicantOrderExpr($applicantSchema, 'applicantname'));
$listStmt->execute([':exam_year' => $examYear]);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$totalRows = count($rows);

$formatValue = static function (string $column, array $definition, array $row) use ($statusMap): string {
    $value = isset($row[$column]) ? trim((string) $row[$column]) : '';

    if ($column === 'id') {
        return $value;
    }

    if ($definition['kind'] === 'status') {
        $value = strtoupper($value);
        return $statusMap[$value] ?? '-';
    }

    return $value !== '' ? $value : '-';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_excel') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo 'การยืนยันไม่ถูกต้อง';
        exit;
    }

    $filename = 'export_exam_year_' . preg_replace('/[^0-9A-Za-z_-]/', '_', $examYear) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    foreach ($selectedColumns as $column) {
        echo '<th>' . $h($columnDefinitions[$column]['label']) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($selectedColumns as $column) {
            echo '<td>' . $h($formatValue($column, $columnDefinitions[$column], $row)) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>นำข้อมูลออก</title>
                <link href="assets/vendor/bootstrap-5.3.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <style>
        .export-panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
            margin-bottom: 14px;
        }

        .export-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--brand);
            margin: 0 0 6px;
        }

        .export-subtitle {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 0.86rem;
        }

        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px 14px;
            margin-bottom: 14px;
        }

        .export-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.88rem;
        }

        .export-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .export-btn,
        .preview-btn {
            border: none;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
        }

        .export-btn {
            background: linear-gradient(120deg, #15803d, #166534);
            color: #fff;
        }

        .preview-btn {
            background: linear-gradient(120deg, #b91c1c, #7f1d1d);
            color: #fff;
        }

        .quick-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }

        .preview-note {
            font-size: 0.84rem;
            color: var(--muted);
            margin-bottom: 10px;
        }
    </style>
</head>

<body>
    <header class="top-header">
        <div class="brand-wrap">
            <a href="menu.php" class="logo-link" aria-label="กลับหน้าเมนูหลัก">
                <img src="upload/tcpr5-1024x990.png" class="logo" alt="ตราศูนย์ฝึกอบรมตำรวจภูธรภาค 5">
            </a>
            <div>
                <h1>ศูนย์ฝึกอบรมตำรวจภูธรภาค ๕</h1>
                <p>TRAINING CENTER OF PROVINCIAL POLICE REGION 5</p>
            </div>
        </div>
        <div class="header-right">
            <a class="header-home" href="menu.php" aria-label="กลับหน้าเมนูหลัก" style="color:#fff;">
                <i class="bi bi-house-door-fill" style="color:#fff;"></i>
            </a>
            <div class="header-meta">
                <strong>นำข้อมูลออก</strong>
                <span>ปีที่ใช้งาน: <?= $h($examYear) ?></span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h($userProfile['firstname']) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= $h($userProfile['fullname']) ?></p>
                    <?php if ($userProfile['username'] !== ''): ?>
                        <p class="profile-username">@<?= $h($userProfile['username']) ?></p>
                    <?php endif; ?>
                    <a class="logout-btn" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="menu-title">เมนู</div>
            <a class="menu-btn" href="All name.php">รายชื่อทั้งหมด</a>
            <a class="menu-btn" href="document.php">ยื่นเอกสาร</a>
            <a class="menu-btn" href="lab_check.php">ตรวจ LAB</a>
            <a class="menu-btn" href="swim.php">ว่ายน้ำ</a>
            <a class="menu-btn" href="run.php">วิ่ง</a>
            <a class="menu-btn" href="3station.php">๓ สถานี</a>
            <a class="menu-btn" href="hospital_check.php">ตรวจร่างกาย รพ.ตร.</a>
            <a class="menu-btn" href="fingerprint_check.php">ตรวจลายนิ้วมือ ศพฐ.</a>
            <a class="menu-btn" href="background_check.php">ตรวจประวัติทางคดี</a>
            <a class="menu-btn" href="interview.php">สัมภาษณ์</a>
            <a class="menu-btn" href="militarydoc.php">เอกสารทางทหาร</a>
            <a class="menu-btn" href="Step.php">สรุปผลรายขั้นตอน</a>
            <a class="menu-btn" href="selected.php">ผู้ได้รับการคัดเลือก</a>
            <a class="menu-btn" href="final.php">สรุปข้อมูลการสอบ นสต.</a>
            <a class="menu-btn active" href="export.php">นำข้อมูลออก</a>
        </aside>

        <main class="content">
            <section class="export-panel">
                <h2 class="export-title">เลือกข้อมูลที่ต้องการนำออก</h2>
                <p class="export-subtitle">ติ๊กคอลัมน์ที่ต้องการ แล้วกดพรีวิวหรือนำออกเป็น Excel ได้ทันที</p>

                <form method="post" id="exportForm">
                    <input type="hidden" name="action" value="export_excel">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

                    <div class="export-actions" style="margin-bottom:12px;">
                        <button type="button" class="quick-btn" id="selectAllColumns">เลือกทั้งหมด</button>
                        <button type="button" class="quick-btn" id="clearAllColumns">ล้างทั้งหมด</button>
                        <span class="preview-note">ข้อมูลทั้งหมด <?= number_format($totalRows) ?> รายการ</span>
                    </div>

                    <div class="export-grid">
                        <?php foreach ($columnDefinitions as $column => $definition): ?>
                            <label class="export-check">
                                <input type="checkbox" name="columns[]" value="<?= $h($column) ?>" <?= in_array($column, $selectedColumns, true) ? 'checked' : '' ?>>
                                <span><?= $h($definition['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="export-actions">
                        <button type="submit" class="export-btn">นำออกเป็น Excel</button>
                        <button type="button" class="preview-btn" id="previewButton">อัปเดตตาราง</button>
                    </div>
                </form>
            </section>

            <section class="export-panel">
                <h2 class="export-title">ตารางพรีวิว</h2>
                <p class="preview-note">แสดงข้อมูลตามคอลัมน์ที่เลือกก่อนดาวน์โหลด</p>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach ($selectedColumns as $column): ?>
                                    <th><?= $h($columnDefinitions[$column]['label']) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="<?= count($selectedColumns) ?>" class="empty-row">ไม่พบข้อมูลสำหรับปีที่เลือก</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <?php foreach ($selectedColumns as $column): ?>
                                        <td><?= $h($formatValue($column, $columnDefinitions[$column], $row)) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <form id="previewForm" method="get" style="display:none;"></form>

    <script>
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const exportForm = document.getElementById('exportForm');
        const previewForm = document.getElementById('previewForm');
        const previewButton = document.getElementById('previewButton');
        const checkboxes = Array.from(document.querySelectorAll('input[name="columns[]"]'));
        const selectAllColumns = document.getElementById('selectAllColumns');
        const clearAllColumns = document.getElementById('clearAllColumns');

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });

        selectAllColumns.addEventListener('click', function() {
            checkboxes.forEach((checkbox) => {
                checkbox.checked = true;
            });
        });

        clearAllColumns.addEventListener('click', function() {
            checkboxes.forEach((checkbox) => {
                checkbox.checked = false;
            });
        });

        previewButton.addEventListener('click', function() {
            previewForm.innerHTML = '';
            checkboxes.forEach((checkbox) => {
                if (checkbox.checked) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'columns[]';
                    input.value = checkbox.value;
                    previewForm.appendChild(input);
                }
            });
            previewForm.submit();
        });

        exportForm.addEventListener('submit', function(event) {
            if (!checkboxes.some((checkbox) => checkbox.checked)) {
                event.preventDefault();
                alert('กรุณาเลือกข้อมูลอย่างน้อย 1 รายการ');
            }
        });
    </script>
</body>

</html>
