<?php
session_start();
require_once 'config/db.php';
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
$formError = '';
$formSuccess = '';
$isEditOpen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $formError = 'การยืนยันไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
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
                header('Location: admin.php');
                exit;
            }
        }
    }
}

if (isset($_SESSION['admin_success'])) {
    $formSuccess = (string) $_SESSION['admin_success'];
    unset($_SESSION['admin_success']);
}

$listStmt = $conn->query('SELECT id, position, idnumber, firstname, lastname, username, email, number FROM users ORDER BY id');
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ผู้เข้าใช้ระบบ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
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

        .admin-edit-btn {
            border: none;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            background: var(--accent);
            color: #1f2937;
            cursor: pointer;
        }

        .admin-edit-btn:hover {
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

        .admin-form-group input {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
        }

        .admin-form-group input:focus {
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
            <a class="header-home" href="menu.php" aria-label="กลับหน้าเมนูหลัก" style="color:#fff;">
                <i class="bi bi-house-door-fill" style="color:#fff;"></i>
            </a>
            <div class="header-meta">
                <strong>ผู้เข้าใช้ระบบ</strong>
                <span>ทั้งหมด <?= number_format($totalUsers) ?> รายการ</span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h($adminRow['firstname']) ?></span>
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
            <a class="menu-btn active" href="admin.php">รายชื่อผู้เข้าใช้</a>
        </aside>

        <main class="content">
            <?php if ($formSuccess !== ''): ?>
                <div class="admin-alert success"><?= $h($formSuccess) ?></div>
            <?php endif; ?>
            <?php if ($formError !== ''): ?>
                <div class="admin-alert error"><?= $h($formError) ?></div>
            <?php endif; ?>

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
                            <th>แก้ไข</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="8" class="empty-row">ไม่พบข้อมูลผู้เข้าใช้</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($users as $index => $user): ?>
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

    <script>
        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');
        const adminModal = document.getElementById('adminModal');
        const closeAdminModal = document.getElementById('closeAdminModal');
        const cancelAdminModal = document.getElementById('cancelAdminModal');

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

        function closeModal() {
            adminModal.classList.remove('open');
        }

        document.querySelectorAll('.admin-edit-btn').forEach((button) => {
            button.addEventListener('click', function() {
                openAdminModal(this);
            });
        });

        closeAdminModal.addEventListener('click', closeModal);
        cancelAdminModal.addEventListener('click', closeModal);

        adminModal.addEventListener('click', function(event) {
            if (event.target === adminModal) {
                closeModal();
            }
        });
    </script>
</body>

</html>
