<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/user_profile.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['exam_year'])) {
    header('Location: import_gptV1.php');
    exit;
}

$userProfile = getCurrentUserProfile($conn);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$examYear = (string) $_SESSION['exam_year'];
$limit = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$allowedStatus = ['W', 'P', 'F'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = '';
}

$search = trim((string) ($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

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

$passCondition = implode(' AND ', [
    "submit_doc = 'P'",
    "lab_check = 'P'",
    "swim_test = 'P'",
    "run_test = 'P'",
    "station3_test = 'P'",
    "hospital_check = 'P'",
    "fingerprint_check = 'P'",
    "background_check = 'P'",
    "interview = 'P'",
    "militarydoc = 'P'",
]);

$autoStatusExpr = "CASE
    WHEN ($failCondition) THEN 'F'
    WHEN ($passCondition) THEN 'P'
    ELSE 'W'
END";

$failReasonExpr = "CASE
    WHEN submit_doc = 'F' THEN CONCAT('ด่าน 1 ยื่นเอกสาร', IF(TRIM(IFNULL(submit_doc_note, '')) <> '', CONCAT(' : ', TRIM(submit_doc_note)), ''))
    WHEN lab_check = 'F' THEN CONCAT('ด่าน 2 ตรวจ LAB', IF(TRIM(IFNULL(lab_check_note, '')) <> '', CONCAT(' : ', TRIM(lab_check_note)), ''))
    WHEN swim_test = 'F' THEN CONCAT('ด่าน 3 ว่ายน้ำ', IF(TRIM(IFNULL(swim_test_note, '')) <> '', CONCAT(' : ', TRIM(swim_test_note)), ''))
    WHEN run_test = 'F' THEN CONCAT('ด่าน 4 วิ่ง', IF(TRIM(IFNULL(run_test_note, '')) <> '', CONCAT(' : ', TRIM(run_test_note)), ''))
    WHEN station3_test = 'F' THEN CONCAT('ด่าน 5 3 สถานี', IF(TRIM(IFNULL(station3_test_note, '')) <> '', CONCAT(' : ', TRIM(station3_test_note)), ''))
    WHEN hospital_check = 'F' THEN CONCAT('ด่าน 6 ตรวจร่างกาย รพ.ตร.', IF(TRIM(IFNULL(hospital_check_note, '')) <> '', CONCAT(' : ', TRIM(hospital_check_note)), ''))
    WHEN fingerprint_check = 'F' THEN CONCAT('ด่าน 7 ลายนิ้วมือ ศพฐ.', IF(TRIM(IFNULL(fingerprint_check_note, '')) <> '', CONCAT(' : ', TRIM(fingerprint_check_note)), ''))
    WHEN background_check = 'F' THEN CONCAT('ด่าน 8 ประวัติทางคดี', IF(TRIM(IFNULL(background_check_note, '')) <> '', CONCAT(' : ', TRIM(background_check_note)), ''))
    WHEN interview = 'F' THEN CONCAT('ด่าน 9 สัมภาษณ์', IF(TRIM(IFNULL(interview_note, '')) <> '', CONCAT(' : ', TRIM(interview_note)), ''))
    WHEN militarydoc = 'F' THEN CONCAT('ด่าน 10 เอกสารทางทหาร', IF(TRIM(IFNULL(militarydoc_note, '')) <> '', CONCAT(' : ', TRIM(militarydoc_note)), ''))
    ELSE ''
END";

// อัปเดตสถานะรวมอัตโนมัติตาม 9 ด่านทุกครั้งที่เปิดหน้า
$autoUpdateStmt = $conn->prepare("
    UPDATE applicantname
    SET allname = $autoStatusExpr
    WHERE id <> 'id' AND exam_year = :exam_year
");
$autoUpdateStmt->bindValue(':exam_year', $examYear);
$autoUpdateStmt->execute();

$whereParts = ["id <> 'id'", 'exam_year = :exam_year'];
$queryParams = [':exam_year' => $examYear];

if ($statusFilter !== '') {
    $whereParts[] = 'allname = :status';
    $queryParams[':status'] = $statusFilter;
}

if ($search !== '') {
    $whereParts[] = '(idcode LIKE :search OR firstname LIKE :search OR lastname LIKE :search)';
    $queryParams[':search'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $whereParts);

$countStmt = $conn->prepare("SELECT COUNT(*) FROM applicantname WHERE $whereSql");
foreach ($queryParams as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$listStmt = $conn->prepare("
    SELECT
        id,
        idcode,
        prefix,
        firstname,
        lastname,
        allname,
        $failReasonExpr AS fail_reason
    FROM applicantname
    WHERE $whereSql
    ORDER BY CAST(id AS UNSIGNED)
    LIMIT :limit OFFSET :offset
");

foreach ($queryParams as $key => $value) {
    $listStmt->bindValue($key, $value);
}
$listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$statusStmt = $conn->prepare("\n    SELECT\n        SUM(CASE WHEN allname = 'W' THEN 1 ELSE 0 END) AS waiting_count,\n        SUM(CASE WHEN allname = 'P' THEN 1 ELSE 0 END) AS pass_count,\n        SUM(CASE WHEN allname = 'F' THEN 1 ELSE 0 END) AS fail_count\n    FROM applicantname\n    WHERE id <> 'id' AND exam_year = :exam_year\n");
$statusStmt->bindValue(':exam_year', $examYear);
$statusStmt->execute();
$statusRow = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$statusCount = [
    'W' => (int) ($statusRow['waiting_count'] ?? 0),
    'P' => (int) ($statusRow['pass_count'] ?? 0),
    'F' => (int) ($statusRow['fail_count'] ?? 0),
];

$baseQuery = ['exam_year' => $examYear];
if ($statusFilter !== '') {
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
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายชื่อทั้งหมด</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
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
            <div class="header-meta">
                <strong>รายชื่อทั้งหมด</strong>
                <span>ปีที่ใช้งาน: <?= h($examYear) ?></span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= h($userProfile['firstname']) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= h($userProfile['fullname']) ?></p>
                    <?php if ($userProfile['username'] !== ''): ?>
                        <p class="profile-username">@<?= h($userProfile['username']) ?></p>
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
            <a class="menu-btn active" href="All name.php">รายชื่อทั้งหมด</a>
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
        </aside>

        <main class="content">
            <div class="toolbar">
                <form method="GET" class="search-box">
                    <input type="hidden" name="exam_year" value="<?= h($examYear) ?>">
                    <?php if ($statusFilter !== ''): ?>
                        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
                    <?php endif; ?>
                    <i class="bi bi-search"></i>
                    <input type="text" name="search" value="<?= h($search) ?>" placeholder="ค้นหาเลขสอบ / ชื่อ / นามสกุล">
                    <button type="submit" class="btn btn-sm btn-danger">ค้นหา</button>
                </form>

                <div class="toolbar-actions">
                    <label class="check-all">
                        <input type="checkbox" id="checkAll">
                        เลือกทั้งหมด
                    </label>
                    <button class="edit-btn" type="button" onclick="openPopup()">แก้ไขรายชื่อ</button>
                </div>
            </div>

            <div class="status-bar">
                <a class="badge text-bg-dark" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search])) ?>">แสดงทั้งหมด</a>
                <a class="badge text-bg-secondary" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'W'])) ?>">รอดำเนินการ <?= $statusCount['W'] ?></a>
                <a class="badge text-bg-success" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'P'])) ?>">ผ่าน <?= $statusCount['P'] ?></a>
                <a class="badge text-bg-danger" href="?<?= http_build_query(array_filter(['exam_year' => $examYear, 'search' => $search, 'status' => 'F'])) ?>">ไม่ผ่าน <?= $statusCount['F'] ?></a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>ลำดับ</th>
                            <th>เลขประจำตัวสอบ</th>
                            <th>คำนำหน้า</th>
                            <th>ชื่อ</th>
                            <th>นามสกุล</th>
                            <th>สถานะ</th>
                            <th>แก้ไข</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="8" class="empty-row">ไม่พบข้อมูลที่ตรงกับเงื่อนไข</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($rows as $index => $row): ?>
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        class="row-check"
                                        data-id="<?= h((string) $row['id']) ?>"
                                        data-order="<?= $offset + $index + 1 ?>"
                                        data-idcode="<?= h((string) $row['idcode']) ?>"
                                        data-prefix="<?= h((string) $row['prefix']) ?>"
                                        data-firstname="<?= h((string) $row['firstname']) ?>"
                                        data-lastname="<?= h((string) $row['lastname']) ?>">
                                </td>
                                <td><?= $offset + $index + 1 ?></td>
                                <td><?= h((string) $row['idcode']) ?></td>
                                <td><?= h((string) $row['prefix']) ?></td>
                                <td><?= h((string) $row['firstname']) ?></td>
                                <td><?= h((string) $row['lastname']) ?></td>
                                <td class="status-<?= h((string) $row['allname']) ?>">
                                    <?php
                                    if ($row['allname'] === 'P') {
                                        echo 'ผ่าน';
                                    } elseif ($row['allname'] === 'F') {
                                        echo 'ไม่ผ่าน';
                                        if (!empty($row['fail_reason'])) {
                                            echo '<br><small>' . h((string) $row['fail_reason']) . '</small>';
                                        }
                                    } else {
                                        echo 'รอดำเนินการ';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button
                                        class="edit-btn"
                                        type="button"
                                        onclick="openPopup(this)"
                                        data-id="<?= h((string) $row['id']) ?>"
                                        data-order="<?= $offset + $index + 1 ?>"
                                        data-idcode="<?= h((string) $row['idcode']) ?>"
                                        data-prefix="<?= h((string) $row['prefix']) ?>"
                                        data-firstname="<?= h((string) $row['firstname']) ?>"
                                        data-lastname="<?= h((string) $row['lastname']) ?>">
                                        แก้ไข
                                    </button>
                                </td>
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

    <div id="popup" class="popup-overlay" style="display:none;">
        <div class="popup-box">
            <form id="editForm">
                <h5 class="popup-title">แก้ไขรายชื่อ</h5>
                <table class="popup-table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>เลขสอบ</th>
                            <th>คำนำหน้า</th>
                            <th>ชื่อ</th>
                            <th>นามสกุล</th>
                        </tr>
                    </thead>
                    <tbody id="popupBody"></tbody>
                </table>

                <div class="popup-actions">
                    <button id="confirmBtn" type="submit" class="confirm-btn">ยืนยัน</button>
                    <button type="button" class="cancel-btn" onclick="closePopup()">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const popup = document.getElementById('popup');
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const checkAll = document.getElementById('checkAll');
        const popupBody = document.getElementById('popupBody');
        const editForm = document.getElementById('editForm');
        const confirmBtn = document.getElementById('confirmBtn');

        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach((checkbox) => {
                checkbox.checked = this.checked;
            });
        });

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });

        function addRow(data) {
            const row = document.createElement('tr');
            row.dataset.id = data.id;

            const orderCell = document.createElement('td');
            orderCell.textContent = data.order || String(popupBody.children.length + 1);
            row.appendChild(orderCell);

            const idCodeCell = document.createElement('td');
            idCodeCell.textContent = data.idcode || '';
            row.appendChild(idCodeCell);

            const prefixCell = document.createElement('td');
            const prefixInput = document.createElement('input');
            prefixInput.type = 'text';
            prefixInput.className = 'edit-prefix form-control';
            prefixInput.value = data.prefix || '';
            prefixInput.required = true;
            prefixCell.appendChild(prefixInput);
            row.appendChild(prefixCell);

            const firstNameCell = document.createElement('td');
            const firstNameInput = document.createElement('input');
            firstNameInput.type = 'text';
            firstNameInput.className = 'edit-firstname form-control';
            firstNameInput.value = data.firstname || '';
            firstNameInput.required = true;
            firstNameCell.appendChild(firstNameInput);
            row.appendChild(firstNameCell);

            const lastNameCell = document.createElement('td');
            const lastNameInput = document.createElement('input');
            lastNameInput.type = 'text';
            lastNameInput.className = 'edit-lastname form-control';
            lastNameInput.value = data.lastname || '';
            lastNameInput.required = true;
            lastNameCell.appendChild(lastNameInput);
            row.appendChild(lastNameCell);

            popupBody.appendChild(row);
        }

        function openPopup(button = null) {
            popupBody.innerHTML = '';

            const selectedRows = Array.from(document.querySelectorAll('.row-check:checked'));
            if (selectedRows.length > 0) {
                selectedRows.forEach((checkbox) => addRow(checkbox.dataset));
            } else if (button !== null) {
                addRow(button.dataset);
            } else {
                alert('กรุณาเลือกรายการที่ต้องการแก้ไข');
                return;
            }

            popup.style.display = 'flex';
        }

        function closePopup() {
            popup.style.display = 'none';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'ยืนยัน';
        }

        popup.addEventListener('click', function(event) {
            if (event.target === popup) {
                closePopup();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && popup.style.display === 'flex') {
                closePopup();
            }
        });

        editForm.addEventListener('submit', async function(event) {
            event.preventDefault();

            const rows = Array.from(document.querySelectorAll('#popupBody tr'));
            const payload = rows.map((row) => ({
                id: row.dataset.id,
                prefix: row.querySelector('.edit-prefix').value.trim(),
                firstname: row.querySelector('.edit-firstname').value.trim(),
                lastname: row.querySelector('.edit-lastname').value.trim()
            }));

            if (payload.length === 0) {
                alert('ไม่มีข้อมูลสำหรับบันทึก');
                return;
            }

            confirmBtn.disabled = true;
            confirmBtn.textContent = 'กำลังบันทึก...';

            try {
                const response = await fetch('./update/update_Allname.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || 'บันทึกไม่สำเร็จ');
                }

                alert('บันทึกข้อมูลเรียบร้อย');
                window.location.reload();
            } catch (error) {
                alert(error.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'ยืนยัน';
            }
        });
    </script>
</body>

</html>
