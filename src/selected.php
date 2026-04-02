<?php
require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/ensure_applicant_schema.php';
require_once __DIR__ . '/includes/selected_imports.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

$applicantSchema = ensureApplicantSchema($conn);
ensureSelectedImportsSchema($conn);

$userProfile = getCurrentUserProfile($conn);

$yearsStmt = $conn->query("
    SELECT exam_year
    FROM (
        SELECT DISTINCT exam_year FROM applicantname
        UNION
        SELECT DISTINCT exam_year FROM selected_imports
    ) AS exam_years
    WHERE exam_year IS NOT NULL AND TRIM(exam_year) <> ''
    ORDER BY exam_year DESC
");
$years = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['exam_year']) && $_GET['exam_year'] !== '') {
    $_SESSION['exam_year'] = $_GET['exam_year'];
}

if (!isset($_SESSION['exam_year']) && $years) {
    $_SESSION['exam_year'] = (string) $years[0]['exam_year'];
}

if (!isset($_SESSION['exam_year'])) {
    $_SESSION['exam_year'] = '';
}

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$examYear = (string) $_SESSION['exam_year'];
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$importResult = (string) ($_SESSION['selected_import_result'] ?? '');
$importError = (string) ($_SESSION['selected_import_error'] ?? '');
unset($_SESSION['selected_import_result'], $_SESSION['selected_import_error']);

$sessionKey = 'selected_count_' . $examYear;
$selectedCount = null;
if (isset($_GET['selected_count'])) {
    $selectedCount = (int) $_GET['selected_count'];
    if ($selectedCount < 0) {
        $selectedCount = 0;
    }
    $_SESSION[$sessionKey] = $selectedCount;
} elseif (isset($_SESSION[$sessionKey])) {
    $selectedCount = (int) $_SESSION[$sessionKey];
} else {
    $selectedCount = 0;
}

$importedRowsCount = 0;
$hasImportedData = false;
$excludedFailCount = 0;
$allnameExpr = applicantAllnameExpr($applicantSchema, 'applicantname');
$failedApplicantsSubquery = "
    SELECT applicantname.idcode
    FROM applicantname
    WHERE applicantname.exam_year = :fail_exam_year
      AND $allnameExpr = 'F'
    GROUP BY applicantname.idcode
";

if ($examYear !== '') {
    $importedCountStmt = $conn->prepare('SELECT COUNT(*) FROM selected_imports WHERE exam_year = :exam_year');
    $importedCountStmt->execute([':exam_year' => $examYear]);
    $importedRowsCount = (int) $importedCountStmt->fetchColumn();
    $hasImportedData = $importedRowsCount > 0;

    if ($hasImportedData) {
        $excludedFailStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM selected_imports si
            INNER JOIN (" . $failedApplicantsSubquery . ") failed ON failed.idcode = si.idcode
            WHERE si.exam_year = :exam_year
        ");
        $excludedFailStmt->execute([
            ':exam_year' => $examYear,
            ':fail_exam_year' => $examYear,
        ]);
        $excludedFailCount = (int) $excludedFailStmt->fetchColumn();

    }
}

$limit = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$whereParts = ['si.exam_year = :exam_year', 'failed.idcode IS NULL'];
$params = [
    ':exam_year' => $examYear,
    ':fail_exam_year' => $examYear,
];

if ($search !== '') {
    $searchNormalized = preg_replace('/\s+/u', ' ', $search) ?? $search;
    $searchCompact = preg_replace('/\s+/u', '', $search) ?? $search;
    $whereParts[] = "(
        si.idcode LIKE :search_idcode
        OR si.prefix LIKE :search_prefix
        OR si.firstname LIKE :search_firstname
        OR si.lastname LIKE :search_lastname
        OR CONCAT_WS(' ', si.prefix, si.firstname, si.lastname) LIKE :search_fullname
        OR REPLACE(CONCAT_WS('', si.prefix, si.firstname, si.lastname), ' ', '') LIKE :search_compact
    )";
    $params[':search_idcode'] = '%' . $searchNormalized . '%';
    $params[':search_prefix'] = '%' . $searchNormalized . '%';
    $params[':search_firstname'] = '%' . $searchNormalized . '%';
    $params[':search_lastname'] = '%' . $searchNormalized . '%';
    $params[':search_fullname'] = '%' . $searchNormalized . '%';
    $params[':search_compact'] = '%' . $searchCompact . '%';
}

$whereSql = implode(' AND ', $whereParts);

$totalRows = 0;
$rows = [];
$totalPages = 1;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

if ($examYear !== '') {
    $countStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM selected_imports si
        LEFT JOIN (" . $failedApplicantsSubquery . ") failed ON failed.idcode = si.idcode
        WHERE $whereSql
    ");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRows = (int) $countStmt->fetchColumn();
}
$totalPages = max(1, (int) ceil($totalRows / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

if ($examYear !== '') {
    $dataSql = "
        SELECT
            si.row_no,
            si.idcode,
            si.prefix,
            si.firstname,
            si.lastname,
            si.score,
            si.remark
        FROM selected_imports si
        LEFT JOIN (" . $failedApplicantsSubquery . ") failed ON failed.idcode = si.idcode
        WHERE $whereSql
        ORDER BY si.row_no ASC
        LIMIT :limit OFFSET :offset
    ";
    $dataStmt = $conn->prepare($dataSql);
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
}

$baseQuery = ['exam_year' => $examYear];
if ($search !== '') {
    $baseQuery['search'] = $search;
}
if ($selectedCount > 0) {
    $baseQuery['selected_count'] = $selectedCount;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'export_selected_excel') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo 'การยืนยันไม่ถูกต้อง';
        exit;
    }

    if ($examYear === '' || !$hasImportedData) {
        http_response_code(400);
        echo 'ยังไม่มีข้อมูลผู้ได้รับการคัดเลือกสำหรับนำออก';
        exit;
    }

    $exportSql = "
        SELECT
            si.row_no,
            si.idcode,
            si.prefix,
            si.firstname,
            si.lastname,
            si.score
        FROM selected_imports si
        LEFT JOIN (" . $failedApplicantsSubquery . ") failed ON failed.idcode = si.idcode
        WHERE $whereSql
        ORDER BY si.row_no ASC
    ";
    $exportStmt = $conn->prepare($exportSql);
    foreach ($params as $key => $value) {
        $exportStmt->bindValue($key, $value);
    }
    $exportStmt->execute();
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'selected_exam_year_' . preg_replace('/[^0-9A-Za-z_-]/', '_', $examYear) . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>ลำดับ</th>';
    echo '<th>เลขสอบ</th>';
    echo '<th>ชื่อ-สกุล</th>';
    echo '<th>คะแนน</th>';
    echo '<th>สถานะ</th>';
    echo '</tr></thead><tbody>';

    foreach ($exportRows as $index => $exportRow) {
        $rank = $index + 1;
        $fullName = trim((string) ($exportRow['prefix'] ?? '') . (string) ($exportRow['firstname'] ?? '') . ' ' . (string) ($exportRow['lastname'] ?? ''));
        $statusText = $selectedCount > 0 && $rank <= $selectedCount ? 'ผู้ได้รับการคัดเลือก' : 'สำรอง';

        echo '<tr>';
        echo '<td>' . $h((string) $rank) . '</td>';
        $scoreText = $exportRow['score'] === null || $exportRow['score'] === ''
            ? '-'
            : number_format((float) $exportRow['score'], 2, '.', '');

        echo '<td>' . $h((string) ($exportRow['idcode'] ?? '')) . '</td>';
        echo '<td>' . $h($fullName) . '</td>';
        echo '<td>' . $h($scoreText) . '</td>';
        echo '<td>' . $h($statusText) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    exit;
}

$range = 5;
$startPage = max(1, $page - (int) floor($range / 2));
$endPage = min($totalPages, $startPage + $range - 1);
if ($endPage - $startPage + 1 < $range) {
    $startPage = max(1, $endPage - $range + 1);
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ผู้ได้รับการคัดเลือก</title>
                <link href="assets/vendor/bootstrap-5.3.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
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
                <strong>ผู้ได้รับการคัดเลือก</strong>
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
            <a class="menu-btn active" href="selected.php">ผู้ได้รับการคัดเลือก</a>
            <a class="menu-btn" href="final.php">สรุปข้อมูลการสอบ นสต.</a>
            <a class="menu-btn" href="export.php">นำข้อมูลออก</a>
        </aside>

        <main class="content">
            <?php if ($importResult !== ''): ?>
                <div class="alert alert-success mb-3"><?= $h($importResult) ?></div>
            <?php endif; ?>
            <?php if ($importError !== ''): ?>
                <div class="alert alert-danger mb-3"><?= $h($importError) ?></div>
            <?php endif; ?>

            <?php if ($hasImportedData): ?>
                <div class="alert alert-info mb-3">
                    นำเข้าแล้ว <?= number_format($importedRowsCount) ?> รายการ /
                    ตัดออกเพราะไม่ผ่าน <?= number_format($excludedFailCount) ?> รายการ
                </div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="post" action="import_selected.php" enctype="multipart/form-data" class="search-box">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="exam_year" value="<?= $h($examYear) ?>">
                    <i class="bi bi-file-earmark-arrow-up-fill"></i>
                    <input type="file" name="selected_file" accept=".xls,.xlsx,.csv" required>
                    <button type="submit" class="btn btn-sm btn-danger">Importไฟล์คะแนน</button>
                </form>

                <?php if ($hasImportedData): ?>
                    <form method="GET" class="search-box">
                        <input type="hidden" name="exam_year" value="<?= $h($examYear) ?>">
                        <?php if ($selectedCount > 0): ?>
                            <input type="hidden" name="selected_count" value="<?= (int) $selectedCount ?>">
                        <?php endif; ?>
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" value="<?= $h($search) ?>" placeholder="ค้นหาเลขสอบ / ชื่อ / นามสกุล">
                        <button type="submit" class="btn btn-sm btn-danger">ค้นหา</button>
                    </form>
                    <form method="GET" class="search-box">
                        <input type="hidden" name="exam_year" value="<?= $h($examYear) ?>">
                        <?php if ($search !== ''): ?>
                            <input type="hidden" name="search" value="<?= $h($search) ?>">
                        <?php endif; ?>
                        <i class="bi bi-people-fill"></i>
                        <input type="number" min="0" name="selected_count" value="<?= $selectedCount > 0 ? (int) $selectedCount : '' ?>" placeholder="จำนวนผู้ได้รับการคัดเลือก">
                        <button type="submit" class="btn btn-sm btn-danger">ตั้งค่า</button>
                    </form>                    <form method="post" class="search-box">
                        <input type="hidden" name="action" value="export_selected_excel">
                        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                        <button type="submit" class="btn btn-sm btn-success">Export ข้อมูลในตาราง</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$hasImportedData): ?>
                <div class="table-wrap">
                    <table>
                        <tbody>
                            <tr>
                                <td class="empty-row">ยังไม่มีข้อมูลผู้ได้รับการคัดเลือก กรุณา Import ไฟล์ก่อน</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>เลขสอบ</th>
                                <th>ชื่อ-สกุล</th>
                                <th>คะแนน</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$rows): ?>
                                <tr>
                                    <td colspan="5" class="empty-row">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php $rank = $offset + $index + 1; ?>
                                <tr>
                                    <td><?= $rank ?></td>
                                    <td><?= $h((string) $row['idcode']) ?></td>
                                    <td class="name-cell" style="text-align:left;padding-left:14px;"><?= $h(trim(($row['prefix'] ?? '') . ($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''))) ?></td>
                                    <td><?= $row['score'] === null || $row['score'] === '' ? '-' : $h(number_format((float) $row['score'], 2, '.', '')) ?></td>
                                    <td><?= $selectedCount > 0 && $rank <= $selectedCount ? 'ผู้ได้รับการคัดเลือก' : 'สำรอง' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-wrap">
                    <span>ทั้งหมด <?= number_format($totalRows) ?> รายการ</span>
                    <div class="pagination-controls">
                        <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => max(1, $page - 1)])) ?>">
                            <button <?= $page <= 1 ? 'disabled' : '' ?>>◀</button>
                        </a>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $i])) ?>">
                                <button class="<?= $i === $page ? 'active-page' : '' ?>"><?= $i ?></button>
                            </a>
                        <?php endfor; ?>

                        <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $page + 1)])) ?>">
                            <button <?= $page >= $totalPages ? 'disabled' : '' ?>>▶</button>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });
    </script>
</body>

</html>
