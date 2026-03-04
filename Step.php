<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/user_profile.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

$userProfile = getCurrentUserProfile($conn);

$years = $conn->query("SELECT DISTINCT exam_year FROM applicantname ORDER BY exam_year DESC")->fetchAll(PDO::FETCH_ASSOC);
if (!$years) {
    die('ยังไม่มีข้อมูลผู้สอบในระบบ');
}

if (isset($_GET['exam_year']) && $_GET['exam_year'] !== '') {
    $_SESSION['exam_year'] = $_GET['exam_year'];
}

if (!isset($_SESSION['exam_year'])) {
    $_SESSION['exam_year'] = (string) $years[0]['exam_year'];
}

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$examYear = (string) $_SESSION['exam_year'];
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$fields = [
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

$buildStatusCondition = static function (array $targetFields, string $type): string {
    $allPass = implode(" = 'P' AND ", $targetFields) . " = 'P'";
    $hasFail = implode(" = 'F' OR ", $targetFields) . " = 'F'";

    if ($type === 'P') {
        return "($allPass)";
    }

    if ($type === 'F') {
        return "($hasFail)";
    }

    if ($type === 'W') {
        return "(NOT ($hasFail) AND NOT ($allPass))";
    }

    return '1=1';
};

$summarySql = "
    SELECT
        SUM(CASE WHEN " . $buildStatusCondition($fields, 'P') . " THEN 1 ELSE 0 END) AS total_pass,
        SUM(CASE WHEN " . $buildStatusCondition($fields, 'F') . " THEN 1 ELSE 0 END) AS total_fail,
        SUM(CASE WHEN " . $buildStatusCondition($fields, 'W') . " THEN 1 ELSE 0 END) AS total_wait
    FROM applicantname
    WHERE exam_year = :exam_year
";
$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->execute([':exam_year' => $examYear]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$statusCount = [
    'P' => (int) ($summary['total_pass'] ?? 0),
    'F' => (int) ($summary['total_fail'] ?? 0),
    'W' => (int) ($summary['total_wait'] ?? 0),
];

$limit = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$whereParts = ['exam_year = :exam_year'];
$params = [':exam_year' => $examYear];

if (in_array($statusFilter, ['P', 'F', 'W'], true)) {
    $whereParts[] = $buildStatusCondition($fields, $statusFilter);
}

if ($search !== '') {
    $whereParts[] = '(idcode LIKE :search OR firstname LIKE :search OR lastname LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $whereParts);

$countStmt = $conn->prepare("SELECT COUNT(*) FROM applicantname WHERE $whereSql");
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$dataSql = "
    SELECT id, idcode, prefix, firstname, lastname,
           submit_doc, lab_check, swim_test, run_test, station3_test,
           hospital_check, fingerprint_check, background_check, interview, militarydoc
    FROM applicantname
    WHERE $whereSql
    ORDER BY CAST(id AS UNSIGNED) ASC
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

$baseQuery = ['exam_year' => $examYear];
if (in_array($statusFilter, ['P', 'F', 'W'], true)) {
    $baseQuery['status'] = $statusFilter;
}
if ($search !== '') {
    $baseQuery['search'] = $search;
}

$range = 5;
$startPage = max(1, $page - (int) floor($range / 2));
$endPage = min($totalPages, $startPage + $range - 1);
if ($endPage - $startPage + 1 < $range) {
    $startPage = max(1, $endPage - $range + 1);
}

$statusText = ['W' => 'รอดำเนินการ', 'P' => 'ผ่าน', 'F' => 'ไม่ผ่าน'];
$columns = [
    'submit_doc' => 'ยื่นเอกสาร',
    'lab_check' => 'LAB',
    'swim_test' => 'ว่ายน้ำ',
    'run_test' => 'วิ่ง',
    'station3_test' => '3 สถานี',
    'hospital_check' => 'รพ.ตร.',
    'fingerprint_check' => 'ลายนิ้วมือ',
    'background_check' => 'ประวัติทางคดี',
    'interview' => 'สัมภาษณ์',
    'militarydoc' => 'เอกสารทหาร',
];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สรุปผลรายขั้นตอน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <style>
        .step-status-W {
            background: #e5e7eb;
        }

        .step-status-P {
            background: #dcfce7;
        }

        .step-status-F {
            background: #fee2e2;
        }

        .summary-table-wrap {
            margin-top: 12px;
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel);
            flex: 1;
            min-height: 0;
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
            <div class="header-meta">
                <strong>สรุปผลรายขั้นตอน</strong>
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
            <a class="menu-btn active" href="Step.php">สรุปผลรายขั้นตอน</a>
            <a class="menu-btn" href="selected.php">ผู้ได้รับการคัดเลือก</a>
            <a class="menu-btn" href="final.php">สรุปข้อมูลการสอบ นสต.</a>
        </aside>

        <main class="content">
            <div class="toolbar">
                <form method="GET" class="search-box">
                    <input type="hidden" name="exam_year" value="<?= $h($examYear) ?>">
                    <?php if (in_array($statusFilter, ['P', 'F', 'W'], true)): ?>
                        <input type="hidden" name="status" value="<?= $h($statusFilter) ?>">
                    <?php endif; ?>
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= $h($search) ?>" placeholder="ค้นหาเลขสอบ / ชื่อ / นามสกุล">
                    <button type="submit" class="btn btn-sm btn-danger">ค้นหา</button>
                </form>
            </div>

            <div class="status-bar">
                <a class="badge text-bg-dark" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search])) ?>">แสดงทั้งหมด</a>
                <a class="badge text-bg-secondary" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'W'])) ?>">รอดำเนินการ <?= $statusCount['W'] ?></a>
                <a class="badge text-bg-success" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'P'])) ?>">ผ่าน <?= $statusCount['P'] ?></a>
                <a class="badge text-bg-danger" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'F'])) ?>">ไม่ผ่าน <?= $statusCount['F'] ?></a>
            </div>

            <div class="summary-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>เลขสอบ</th>
                            <th>ชื่อ-สกุล</th>
                            <?php foreach ($columns as $label): ?>
                                <th><?= $h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="13" class="empty-row">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <tr>
                                <td><?= $offset + $index + 1 ?></td>
                                <td><?= $h((string) $row['idcode']) ?></td>
                                <td><?= $h(trim(($row['prefix'] ?? '') . ($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''))) ?></td>
                                <?php foreach (array_keys($columns) as $field): ?>
                                    <?php $status = in_array(($row[$field] ?? 'W'), ['W', 'P', 'F'], true) ? $row[$field] : 'W'; ?>
                                    <td class="step-status-<?= $h($status) ?>"><?= $h($statusText[$status]) ?></td>
                                <?php endforeach; ?>
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
