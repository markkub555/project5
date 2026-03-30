<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/ensure_applicant_schema.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

$applicantSchema = ensureApplicantSchema($conn);

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
$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

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

$limit = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$whereParts = ['exam_year = :exam_year'];
$params = [':exam_year' => $examYear];

$failCondition = implode(' OR ', [
    "submit_doc = 'F'",
    "lab_check = 'F'",
    "swim_test = 'F'",
    "run_test = 'F'",
    "station3_test = 'F'",
    "hospital_check = 'F'",
    "fingerprint_check = 'F'",
    "background_check = 'F'",
    "interview = 'F'",
    "militarydoc = 'F'",
]);

$whereParts[] = "NOT ($failCondition)";

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
    SELECT id, idcode, prefix, firstname, lastname, score
    FROM applicantname
    WHERE $whereSql
    ORDER BY (" . applicantScoreExpr($applicantSchema) . " IS NULL) ASC, " . applicantScoreExpr($applicantSchema) . " DESC, " . applicantOrderExpr($applicantSchema) . " ASC
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
if ($search !== '') {
    $baseQuery['search'] = $search;
}
if ($selectedCount > 0) {
    $baseQuery['selected_count'] = $selectedCount;
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
            <div class="toolbar">
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
                </form>
            </div>

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
                                <td><?= $row['score'] === null || $row['score'] === '' ? '-' : $h((string) $row['score']) ?></td>
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
