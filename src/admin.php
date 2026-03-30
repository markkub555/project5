<?php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';

ensureUserResetSchema($conn);

if (!isset($_SESSION['admin_login'])) {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบ!';
    header('location: login.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$view = (string) ($_GET['view'] ?? 'users');
if (!in_array($view, ['users', 'pending', 'delete_year'], true)) {
    $view = 'users';
}

$adminId = (int) $_SESSION['admin_login'];
$stmt = $conn->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $adminId]);
$adminRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['firstname' => '', 'lastname' => '', 'username' => ''];

$formData = [
    'id' => 0,
    'position' => '',
    'idnumber' => '',
    'firstname' => '',
    'lastname' => '',
    'username' => '',
    'email' => '',
    'number' => '',
];
$statusFormData = [
    'id' => 0,
    'fullname' => '',
    'username' => '',
    'userstatus' => 'W',
];
$formError = '';
$formSuccess = '';
$isEditOpen = false;
$isStatusOpen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $formError = 'การยืนยันไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (($_POST['action'] ?? '') === 'update_user') {
        $formData = [
            'id' => (int) ($_POST['user_id'] ?? 0),
            'position' => trim((string) ($_POST['position'] ?? '')),
            'idnumber' => trim((string) ($_POST['idnumber'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'number' => trim((string) ($_POST['number'] ?? '')),
        ];
        $isEditOpen = true;

        if (
            $formData['id'] <= 0 ||
            $formData['position'] === '' ||
            $formData['idnumber'] === '' ||
            $formData['firstname'] === '' ||
            $formData['lastname'] === '' ||
            $formData['username'] === '' ||
            $formData['email'] === '' ||
            $formData['number'] === ''
        ) {
            $formError = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $formError = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $checkStmt = $conn->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $checkStmt->execute([
                ':username' => $formData['username'],
                ':id' => $formData['id'],
            ]);

            $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $emailCheckStmt->execute([
                ':email' => $formData['email'],
                ':id' => $formData['id'],
            ]);

            if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $formError = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
            } elseif ($emailCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                $formError = 'อีเมลนี้ถูกใช้งานแล้ว';
            } else {
                $updateStmt = $conn->prepare(
                    'UPDATE users
                     SET position = :position,
                         idnumber = :idnumber,
                         firstname = :firstname,
                         lastname = :lastname,
                         username = :username,
                         email = :email,
                         number = :number
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':position' => $formData['position'],
                    ':idnumber' => $formData['idnumber'],
                    ':firstname' => $formData['firstname'],
                    ':lastname' => $formData['lastname'],
                    ':username' => $formData['username'],
                    ':email' => $formData['email'],
                    ':number' => $formData['number'],
                    ':id' => $formData['id'],
                ]);

                $_SESSION['admin_success'] = 'แก้ไขข้อมูลผู้ใช้เรียบร้อย';
                header('Location: admin.php?view=users');
                exit;
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_user_status') {
        $statusFormData = [
            'id' => (int) ($_POST['status_user_id'] ?? 0),
            'fullname' => trim((string) ($_POST['status_fullname'] ?? '')),
            'username' => trim((string) ($_POST['status_username'] ?? '')),
            'userstatus' => strtoupper(trim((string) ($_POST['userstatus'] ?? 'W'))),
        ];
        $isStatusOpen = true;

        if ($statusFormData['id'] <= 0) {
            $formError = 'ไม่พบข้อมูลผู้ใช้ที่ต้องการอัปเดตสถานะ';
        } elseif (!in_array($statusFormData['userstatus'], ['W', 'P', 'F'], true)) {
            $formError = 'สถานะที่เลือกไม่ถูกต้อง';
        } else {
            $statusStmt = $conn->prepare('UPDATE users SET userstatus = :userstatus WHERE id = :id');
            $statusStmt->execute([
                ':userstatus' => $statusFormData['userstatus'],
                ':id' => $statusFormData['id'],
            ]);

            $_SESSION['admin_success'] = 'อัปเดตสถานะการเข้าใช้งานเรียบร้อย';
            header('Location: admin.php?view=pending');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'delete_exam_year') {
        $deleteExamYear = trim((string) ($_POST['exam_year'] ?? ''));

        if ($deleteExamYear === '') {
            $formError = 'ไม่พบปี นสต. ที่ต้องการลบ';
        } else {
            $countYearStmt = $conn->prepare('SELECT COUNT(*) FROM applicantname WHERE exam_year = :exam_year');
            $countYearStmt->execute([':exam_year' => $deleteExamYear]);
            $rowCount = (int) $countYearStmt->fetchColumn();

            if ($rowCount <= 0) {
                $formError = 'ไม่พบข้อมูลของปี นสต. ที่เลือก';
            } else {
                $deleteYearStmt = $conn->prepare('DELETE FROM applicantname WHERE exam_year = :exam_year');
                $deleteYearStmt->execute([':exam_year' => $deleteExamYear]);

                if (isset($_SESSION['exam_year']) && (string) $_SESSION['exam_year'] === $deleteExamYear) {
                    unset($_SESSION['exam_year']);
                }

                $_SESSION['admin_success'] = "ลบข้อมูล นสต.รุ่นที่ {$deleteExamYear} เรียบร้อย ({$rowCount} รายการ)";
                header('Location: admin.php?view=delete_year');
                exit;
            }
        }
    }
}

if (isset($_SESSION['admin_success'])) {
    $formSuccess = (string) $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}

$listStmt = $conn->query("SELECT id, position, idnumber, firstname, lastname, username, email, number, COALESCE(NULLIF(TRIM(userstatus), ''), 'P') AS userstatus FROM users ORDER BY id");
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);

$pendingStmt = $conn->query("SELECT id, position, idnumber, firstname, lastname, username, email, number, COALESCE(NULLIF(TRIM(userstatus), ''), 'P') AS userstatus FROM users WHERE COALESCE(NULLIF(TRIM(userstatus), ''), 'P') = 'W' ORDER BY id DESC");
$pendingUsers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$totalPendingUsers = count($pendingUsers);

$examYearStmt = $conn->query("
    SELECT exam_year, COUNT(*) AS total_rows
    FROM applicantname
    WHERE exam_year IS NOT NULL AND TRIM(exam_year) <> '' AND id <> 'id'
    GROUP BY exam_year
    ORDER BY exam_year DESC
");
$examYears = $examYearStmt->fetchAll(PDO::FETCH_ASSOC);
$totalExamYears = count($examYears);

if ($view === 'pending') {
    $pageTitle = 'ยืนยันสิทธิ์การเข้าใช้';
    $pageSubtitle = 'รอการอนุมัติ ' . number_format($totalPendingUsers) . ' รายการ';
} elseif ($view === 'delete_year') {
    $pageTitle = 'ลบข้อมูลปีนสต';
    $pageSubtitle = 'มีปีข้อมูลทั้งหมด ' . number_format($totalExamYears) . ' รุ่น';
} else {
    $pageTitle = 'ผู้เข้าใช้ระบบ';
    $pageSubtitle = 'ทั้งหมด ' . number_format($totalUsers) . ' รายการ';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $h($pageTitle) ?></title>
                <link href="assets/vendor/bootstrap-5.3.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <style>
        .admin-alert {
            margin-bottom: 10px;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        .admin-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .admin-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .admin-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .admin-summary {
            font-size: 0.84rem;
            color: var(--muted);
        }

        .admin-edit-btn,
        .admin-status-btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: var(--accent);
            color: #1f2937;
            cursor: pointer;
        }

        .admin-status-btn {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .admin-edit-btn:hover,
        .admin-status-btn:hover {
            filter: brightness(0.96);
        }

        .admin-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 40;
        }

        .admin-modal.open {
            display: flex;
        }

        .admin-modal-box {
            width: min(720px, 100%);
            max-height: calc(100dvh - 40px);
            overflow: auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.24);
            padding: 18px;
        }

        .admin-status-modal-box {
            width: min(480px, 100%);
        }

        .admin-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .admin-modal-head h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--brand);
        }

        .admin-close-btn {
            border: none;
            background: transparent;
            font-size: 1.3rem;
            color: #6b7280;
            cursor: pointer;
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .admin-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .admin-form-group label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
        }

        .admin-form-group input,
        .admin-form-group select {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
        }

        .admin-form-group input:focus,
        .admin-form-group select:focus {
            outline: 2px solid #fecaca;
            border-color: #fca5a5;
        }

        .admin-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 14px;
        }

        .admin-cancel-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
        }

        .admin-save-btn {
            border: none;
            background: linear-gradient(120deg, #b91c1c, #7f1d1d);
            color: #fff;
            border-radius: 999px;
            padding: 7px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .status-pill-W {
            background: #e5e7eb;
            color: #374151;
        }

        .status-pill-P {
            background: #dcfce7;
            color: #166534;
        }

        .status-pill-F {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-user-meta {
            margin: 0 0 12px;
            color: #6b7280;
            font-size: 0.84rem;
        }

        .admin-delete-btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 700;
            background: #dc2626;
            color: #fff;
            cursor: pointer;
        }

        .admin-delete-btn:hover {
            filter: brightness(0.96);
        }

        @media (max-width: 720px) {
            .admin-form-grid {
                grid-template-columns: 1fr;
            }
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
                <strong><?= $h($pageTitle) ?></strong>
                <span><?= $h($pageSubtitle) ?></span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h((string) ($adminRow['firstname'] ?? '')) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= $h(trim(($adminRow['firstname'] ?? '') . ' ' . ($adminRow['lastname'] ?? ''))) ?></p>
                    <?php if (($adminRow['username'] ?? '') !== ''): ?>
                        <p class="profile-username">@<?= $h((string) $adminRow['username']) ?></p>
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
            <a class="menu-btn<?= $view === 'users' ? ' active' : '' ?>" href="admin.php?view=users">รายชื่อผู้เข้าใช้</a>
            <a class="menu-btn<?= $view === 'pending' ? ' active' : '' ?>" href="admin.php?view=pending">ยืนยันสิทธิ์การเข้าใช้</a>
            <a class="menu-btn<?= $view === 'delete_year' ? ' active' : '' ?>" href="admin.php?view=delete_year">ลบปีข้อมูลนสต</a>
        </aside>

        <main class="content">
            <?php if ($formSuccess !== ''): ?>
                <div class="admin-alert success"><?= $h($formSuccess) ?></div>
            <?php endif; ?>
            <?php if ($formError !== ''): ?>
                <div class="admin-alert error"><?= $h($formError) ?></div>
            <?php endif; ?>

            <?php if ($view === 'delete_year'): ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">ลบข้อมูลผู้สมัครทั้งรุ่นจาก `exam_year` ที่มีอยู่ในระบบ</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>นสต.รุ่นที่</th>
                                <th>จำนวนข้อมูล</th>
                                <th>ลบข้อมูล</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$examYears): ?>
                                <tr>
                                    <td colspan="4" class="empty-row">ยังไม่มีข้อมูลปี นสต. ในระบบ</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($examYears as $index => $yearRow): ?>
                                <?php $examYearValue = trim((string) ($yearRow['exam_year'] ?? '')); ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h('นสต.' . $examYearValue) ?></td>
                                    <td><?= number_format((int) ($yearRow['total_rows'] ?? 0)) ?> รายการ</td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('ยืนยันการลบข้อมูล นสต.รุ่นที่ <?= $h($examYearValue) ?> ทั้งหมดหรือไม่');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_exam_year">
                                            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                                            <input type="hidden" name="exam_year" value="<?= $h($examYearValue) ?>">
                                            <button type="submit" class="admin-delete-btn">ลบข้อมูล</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($view === 'pending'): ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">รายการผู้สมัครใหม่ที่ยังรอการยืนยันสิทธิ์เข้าใช้งานจากผู้ดูแลระบบ</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>ชื่อผู้ใช้</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>อีเมล</th>
                                <th>เบอร์โทร</th>
                                <th>สถานะ</th>
                                <th>แก้ไขสถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$pendingUsers): ?>
                                <tr>
                                    <td colspan="8" class="empty-row">ไม่มีรายการที่รอการยืนยันสิทธิ์</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($pendingUsers as $index => $user): ?>
                                <?php $fullName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')); ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h((string) $user['username']) ?></td>
                                    <td class="name-cell" style="text-align:left;padding-left:14px;"><?= $h($fullName) ?></td>
                                    <td><?= $h((string) $user['position']) ?></td>
                                    <td><?= $h((string) ($user['email'] ?? '')) ?></td>
                                    <td><?= $h((string) $user['number']) ?></td>
                                    <td><span class="status-pill status-pill-W">รอยืนยัน</span></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="admin-status-btn"
                                            data-id="<?= (int) $user['id'] ?>"
                                            data-fullname="<?= $h($fullName) ?>"
                                            data-username="<?= $h((string) $user['username']) ?>"
                                            data-userstatus="<?= $h((string) $user['userstatus']) ?>"
                                        >
                                            แก้ไขสถานะ
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="admin-toolbar">
                    <div class="admin-summary">จัดการข้อมูลผู้ใช้งานที่อยู่ในระบบจากหน้าเดียว</div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>ชื่อผู้ใช้</th>
                                <th>ชื่อ-สกุล</th>
                                <th>ตำแหน่ง</th>
                                <th>เลขบัตรประชาชน</th>
                                <th>อีเมล</th>
                                <th>เบอร์โทร</th>
                                <th>สถานะ</th>
                                <th>แก้ไข</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$users): ?>
                                <tr>
                                    <td colspan="9" class="empty-row">ไม่พบข้อมูลผู้เข้าใช้</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($users as $index => $user): ?>
                                <?php
                                $userStatus = strtoupper(trim((string) ($user['userstatus'] ?? 'P')));
                                if (!in_array($userStatus, ['W', 'P', 'F'], true)) {
                                    $userStatus = 'P';
                                }
                                $statusLabel = $userStatus === 'W' ? 'รอยืนยัน' : ($userStatus === 'F' ? 'ไม่อนุมัติ' : 'อนุมัติแล้ว');
                                $statusClass = 'status-pill-' . $userStatus;
                                ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= $h((string) $user['username']) ?></td>
                                    <td class="name-cell" style="text-align:left;padding-left:14px;">
                                        <?= $h(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))) ?>
                                    </td>
                                    <td><?= $h((string) $user['position']) ?></td>
                                    <td><?= $h((string) $user['idnumber']) ?></td>
                                    <td><?= $h((string) ($user['email'] ?? '')) ?></td>
                                    <td><?= $h((string) $user['number']) ?></td>
                                    <td><span class="status-pill <?= $h($statusClass) ?>"><?= $h($statusLabel) ?></span></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="admin-edit-btn"
                                            data-id="<?= (int) $user['id'] ?>"
                                            data-position="<?= $h((string) $user['position']) ?>"
                                            data-idnumber="<?= $h((string) $user['idnumber']) ?>"
                                            data-firstname="<?= $h((string) $user['firstname']) ?>"
                                            data-lastname="<?= $h((string) $user['lastname']) ?>"
                                            data-username="<?= $h((string) $user['username']) ?>"
                                            data-email="<?= $h((string) ($user['email'] ?? '')) ?>"
                                            data-number="<?= $h((string) $user['number']) ?>"
                                        >
                                            แก้ไข
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="adminModal" class="admin-modal<?= $isEditOpen ? ' open' : '' ?>">
        <div class="admin-modal-box">
            <div class="admin-modal-head">
                <h3>แก้ไขข้อมูลผู้เข้าใช้</h3>
                <button type="button" id="closeAdminModal" class="admin-close-btn" aria-label="ปิด">&times;</button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" id="user_id" name="user_id" value="<?= (int) $formData['id'] ?>">

                <div class="admin-form-grid">
                    <div class="admin-form-group">
                        <label for="position">ตำแหน่ง</label>
                        <input id="position" name="position" type="text" value="<?= $h($formData['position']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="idnumber">เลขบัตรประชาชน</label>
                        <input id="idnumber" name="idnumber" type="text" value="<?= $h($formData['idnumber']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="firstname">ชื่อ</label>
                        <input id="firstname" name="firstname" type="text" value="<?= $h($formData['firstname']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="lastname">นามสกุล</label>
                        <input id="lastname" name="lastname" type="text" value="<?= $h($formData['lastname']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="username">ชื่อผู้ใช้</label>
                        <input id="username" name="username" type="text" value="<?= $h($formData['username']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="email">อีเมล</label>
                        <input id="email" name="email" type="email" value="<?= $h($formData['email']) ?>" required>
                    </div>
                    <div class="admin-form-group">
                        <label for="number">เบอร์โทร</label>
                        <input id="number" name="number" type="text" value="<?= $h($formData['number']) ?>" required>
                    </div>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="admin-save-btn">บันทึกข้อมูล</button>
                    <button type="button" id="cancelAdminModal" class="admin-cancel-btn">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <div id="statusModal" class="admin-modal<?= $isStatusOpen ? ' open' : '' ?>">
        <div class="admin-modal-box admin-status-modal-box">
            <div class="admin-modal-head">
                <h3>แก้ไขสถานะการเข้าใช้</h3>
                <button type="button" id="closeStatusModal" class="admin-close-btn" aria-label="ปิด">&times;</button>
            </div>

            <form method="post">
                <input type="hidden" name="action" value="update_user_status">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" id="status_user_id" name="status_user_id" value="<?= (int) $statusFormData['id'] ?>">
                <input type="hidden" id="status_fullname" name="status_fullname" value="<?= $h($statusFormData['fullname']) ?>">
                <input type="hidden" id="status_username" name="status_username" value="<?= $h($statusFormData['username']) ?>">

                <p class="status-user-meta">
                    ผู้ใช้: <strong id="statusUserNameLabel"><?= $h($statusFormData['fullname']) ?></strong>
                    <span id="statusUsernameLabel"><?= $statusFormData['username'] !== '' ? '(' . $h($statusFormData['username']) . ')' : '' ?></span>
                </p>

                <div class="admin-form-group">
                    <label for="userstatus">สถานะการเข้าใช้</label>
                    <select id="userstatus" name="userstatus" required>
                        <option value="W"<?= $statusFormData['userstatus'] === 'W' ? ' selected' : '' ?>>รอยืนยัน</option>
                        <option value="P"<?= $statusFormData['userstatus'] === 'P' ? ' selected' : '' ?>>อนุมัติให้เข้าใช้</option>
                        <option value="F"<?= $statusFormData['userstatus'] === 'F' ? ' selected' : '' ?>>ไม่อนุมัติ</option>
                    </select>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="admin-save-btn">บันทึกสถานะ</button>
                    <button type="button" id="cancelStatusModal" class="admin-cancel-btn">ยกเลิก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const adminModal = document.getElementById('adminModal');
        const statusModal = document.getElementById('statusModal');
        const closeAdminModal = document.getElementById('closeAdminModal');
        const cancelAdminModal = document.getElementById('cancelAdminModal');
        const closeStatusModal = document.getElementById('closeStatusModal');
        const cancelStatusModal = document.getElementById('cancelStatusModal');

        const formFields = {
            id: document.getElementById('user_id'),
            position: document.getElementById('position'),
            idnumber: document.getElementById('idnumber'),
            firstname: document.getElementById('firstname'),
            lastname: document.getElementById('lastname'),
            username: document.getElementById('username'),
            email: document.getElementById('email'),
            number: document.getElementById('number'),
        };

        const statusFields = {
            id: document.getElementById('status_user_id'),
            fullname: document.getElementById('status_fullname'),
            username: document.getElementById('status_username'),
            userstatus: document.getElementById('userstatus'),
            fullNameLabel: document.getElementById('statusUserNameLabel'),
            usernameLabel: document.getElementById('statusUsernameLabel'),
        };

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });

        function openAdminModal(button) {
            formFields.id.value = button.dataset.id || '';
            formFields.position.value = button.dataset.position || '';
            formFields.idnumber.value = button.dataset.idnumber || '';
            formFields.firstname.value = button.dataset.firstname || '';
            formFields.lastname.value = button.dataset.lastname || '';
            formFields.username.value = button.dataset.username || '';
            formFields.email.value = button.dataset.email || '';
            formFields.number.value = button.dataset.number || '';
            adminModal.classList.add('open');
        }

        function openStatusModal(button) {
            const fullName = button.dataset.fullname || '';
            const username = button.dataset.username || '';
            statusFields.id.value = button.dataset.id || '';
            statusFields.fullname.value = fullName;
            statusFields.username.value = username;
            statusFields.userstatus.value = button.dataset.userstatus || 'W';
            statusFields.fullNameLabel.textContent = fullName;
            statusFields.usernameLabel.textContent = username ? '(' + username + ')' : '';
            statusModal.classList.add('open');
        }

        function closeModal(modal) {
            modal.classList.remove('open');
        }

        document.querySelectorAll('.admin-edit-btn').forEach((button) => {
            button.addEventListener('click', function() {
                openAdminModal(this);
            });
        });

        document.querySelectorAll('.admin-status-btn').forEach((button) => {
            button.addEventListener('click', function() {
                openStatusModal(this);
            });
        });

        if (closeAdminModal) {
            closeAdminModal.addEventListener('click', function() {
                closeModal(adminModal);
            });
        }
        if (cancelAdminModal) {
            cancelAdminModal.addEventListener('click', function() {
                closeModal(adminModal);
            });
        }
        if (closeStatusModal) {
            closeStatusModal.addEventListener('click', function() {
                closeModal(statusModal);
            });
        }
        if (cancelStatusModal) {
            cancelStatusModal.addEventListener('click', function() {
                closeModal(statusModal);
            });
        }

        adminModal.addEventListener('click', function(event) {
            if (event.target === adminModal) {
                closeModal(adminModal);
            }
        });

        statusModal.addEventListener('click', function(event) {
            if (event.target === statusModal) {
                closeModal(statusModal);
            }
        });
    </script>
</body>

</html>
