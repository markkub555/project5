<?php
require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/audit_log.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$userId = (int) $_SESSION['user_login'];

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$stmt = $conn->prepare('SELECT id, position, idnumber, firstname, lastname, username, email, number FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$formData = [
    'position' => trim((string) ($user['position'] ?? '')),
    'idnumber' => trim((string) ($user['idnumber'] ?? '')),
    'firstname' => trim((string) ($user['firstname'] ?? '')),
    'lastname' => trim((string) ($user['lastname'] ?? '')),
    'username' => trim((string) ($user['username'] ?? '')),
    'email' => trim((string) ($user['email'] ?? '')),
    'number' => trim((string) ($user['number'] ?? '')),
];
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $errorMessage = 'การยืนยันไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $formData = [
            'position' => trim((string) ($_POST['position'] ?? '')),
            'idnumber' => trim((string) ($_POST['idnumber'] ?? '')),
            'firstname' => trim((string) ($_POST['firstname'] ?? '')),
            'lastname' => trim((string) ($_POST['lastname'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'number' => trim((string) ($_POST['number'] ?? '')),
        ];

        if (in_array('', $formData, true)) {
            $errorMessage = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'รูปแบบอีเมลไม่ถูกต้อง';
        } else {
            $usernameStmt = $conn->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $usernameStmt->execute([
                ':username' => $formData['username'],
                ':id' => $userId,
            ]);

            $emailStmt = $conn->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $emailStmt->execute([
                ':email' => $formData['email'],
                ':id' => $userId,
            ]);

            if ($usernameStmt->fetch(PDO::FETCH_ASSOC)) {
                $errorMessage = 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว';
            } elseif ($emailStmt->fetch(PDO::FETCH_ASSOC)) {
                $errorMessage = 'อีเมลนี้ถูกใช้งานแล้ว';
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
                    ':id' => $userId,
                ]);

                auditLog($conn, 'profile_update_self', 'user', (string) $userId, [
                    'username' => $formData['username'],
                    'email' => $formData['email'],
                ], $userId, $formData['username'], 'user');

                $successMessage = 'บันทึกข้อมูลโปรไฟล์เรียบร้อย';
            }
        }
    }
}

$userProfile = getCurrentUserProfile($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>แก้โปรไฟล์</title>
    <link href="assets/vendor/bootstrap-5.3.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css" rel="stylesheet">
    <style>
        .profile-shell {
            display: grid;
            gap: 14px;
        }

        .profile-hero {
            background: linear-gradient(135deg, #fff 0%, #fff5f5 48%, #f8fbff 100%);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .profile-identity {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .profile-avatar {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, #7f1d1d, #b91c1c);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 14px 22px rgba(127, 29, 29, 0.22);
            flex: 0 0 auto;
        }

        .profile-hero-title {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #7f1d1d;
        }

        .profile-hero-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 0.84rem;
        }

        .profile-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .profile-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            border: 1px solid #ead7d7;
            background: #fff;
            color: #374151;
            padding: 7px 11px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .profile-page-card {
            background: rgba(255,255,255,0.88);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .profile-section-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.76rem;
            font-weight: 700;
            color: #9f1239;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 999px;
            padding: 5px 10px;
            margin-bottom: 10px;
        }

        .profile-page-title {
            margin: 0 0 6px;
            color: var(--brand);
            font-size: 1.06rem;
            font-weight: 700;
        }

        .profile-page-subtitle {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 0.84rem;
        }

        .profile-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .profile-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .profile-form-group label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #374151;
        }

        .profile-form-group input {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.86rem;
            background: #fff;
            transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
        }

        .profile-form-group input:focus {
            outline: 2px solid #fecaca;
            border-color: #fca5a5;
            transform: translateY(-1px);
        }

        .profile-page-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .profile-back-btn,
        .profile-save-btn {
            border: none;
            border-radius: 999px;
            padding: 9px 16px;
            font-size: 0.84rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .profile-back-btn {
            background: #fff;
            border: 1px solid var(--line);
            color: #374151;
        }

        .profile-save-btn {
            background: linear-gradient(120deg, #b91c1c, #7f1d1d);
            color: #fff;
            box-shadow: 0 10px 18px rgba(127, 29, 29, 0.2);
        }

        .profile-back-btn:hover {
            background: #f8fafc;
            color: #111827;
        }

        .profile-save-btn:hover {
            color: #fff;
            filter: brightness(1.03);
        }

        @media (max-width: 720px) {
            .profile-hero {
                padding: 14px;
            }

            .profile-identity {
                width: 100%;
            }

            .profile-badges {
                width: 100%;
                justify-content: flex-start;
            }

            .profile-form-grid {
                grid-template-columns: 1fr;
            }

            .profile-page-actions > * {
                width: 100%;
            }
        }

        @media (max-width: 560px) {
            .profile-avatar {
                width: 58px;
                height: 58px;
                border-radius: 16px;
                font-size: 1.45rem;
            }

            .profile-hero-title {
                font-size: 1rem;
            }

            .profile-page-card,
            .profile-hero {
                padding: 12px;
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
                <strong>แก้โปรไฟล์</strong>
                <span><?= $h($userProfile['fullname']) ?></span>
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
                    <div class="profile-actions">
                    <a class="profile-menu-item profile-menu-item-primary" href="profile.php" style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 12px;border-radius:12px;border:1px solid #dbe4f0;background:linear-gradient(180deg,#ffffff,#f8fafc);color:#334155 !important;text-decoration:none !important;font-size:.84rem;font-weight:700;line-height:1.2;box-shadow:0 6px 14px rgba(15,23,42,.06);">
                        <i class="bi bi-pencil-square" style="color:#334155 !important;"></i>
                        <span style="color:#334155 !important;">แก้โปรไฟล์</span>
                    </a>
                    <a class="profile-menu-item profile-menu-item-danger" href="logout.php" style="display:flex;align-items:center;gap:10px;width:100%;padding:11px 12px;border-radius:12px;border:1px solid #fecaca;background:linear-gradient(180deg,#fff7f7,#fff1f2);color:#b91c1c !important;text-decoration:none !important;font-size:.84rem;font-weight:700;line-height:1.2;box-shadow:0 6px 14px rgba(185,28,28,.08);">
                        <i class="bi bi-box-arrow-right" style="color:#b91c1c !important;"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="menu-title">เมนู</div>
            <a class="menu-btn" href="menu.php">กลับหน้าเมนูหลัก</a>
            <a class="menu-btn active" href="profile.php">แก้โปรไฟล์</a>
        </aside>

        <main class="content">
            <div class="profile-shell">
                <section class="profile-hero">
                    <div class="profile-identity">
                        <div class="profile-avatar">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <div>
                            <h2 class="profile-hero-title"><?= $h($userProfile['fullname']) ?></h2>
                            <p class="profile-hero-subtitle">อัปเดตข้อมูลส่วนตัวและข้อมูลติดต่อของบัญชีผู้ใช้งาน</p>
                        </div>
                    </div>
                    <div class="profile-badges">
                        <div class="profile-badge">
                            <i class="bi bi-at"></i>
                            <?= $h($formData['username']) ?>
                        </div>
                        <div class="profile-badge">
                            <i class="bi bi-envelope"></i>
                            <?= $h($formData['email']) ?>
                        </div>
                    </div>
                </section>

                <section class="profile-page-card">
                    <div class="profile-section-label">
                        <i class="bi bi-pencil-square" style="color:#334155 !important;"></i>
                        แบบฟอร์มแก้ไขข้อมูล
                    </div>
                    <h2 class="profile-page-title">ข้อมูลผู้ใช้งาน</h2>
                    <p class="profile-page-subtitle">แก้ไขข้อมูลส่วนตัวของบัญชีที่กำลังใช้งานอยู่</p>

                    <?php if ($errorMessage !== ''): ?>
                        <div class="alert alert-danger mb-3"><?= $h($errorMessage) ?></div>
                    <?php endif; ?>
                    <?php if ($successMessage !== ''): ?>
                        <div class="alert alert-success mb-3"><?= $h($successMessage) ?></div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                        <div class="profile-form-grid">
                            <div class="profile-form-group">
                                <label for="position">ตำแหน่ง</label>
                                <input id="position" name="position" type="text" value="<?= $h($formData['position']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="idnumber">เลขบัตรประชาชน</label>
                                <input id="idnumber" name="idnumber" type="text" value="<?= $h($formData['idnumber']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="firstname">ชื่อ</label>
                                <input id="firstname" name="firstname" type="text" value="<?= $h($formData['firstname']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="lastname">นามสกุล</label>
                                <input id="lastname" name="lastname" type="text" value="<?= $h($formData['lastname']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="username">ชื่อผู้ใช้</label>
                                <input id="username" name="username" type="text" value="<?= $h($formData['username']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="email">อีเมล</label>
                                <input id="email" name="email" type="email" value="<?= $h($formData['email']) ?>" required>
                            </div>
                            <div class="profile-form-group">
                                <label for="number">เบอร์โทร</label>
                                <input id="number" name="number" type="text" value="<?= $h($formData['number']) ?>" required>
                            </div>
                        </div>
                        <div class="profile-page-actions">
                            <a class="profile-back-btn" href="menu.php">
                                <i class="bi bi-arrow-left"></i>
                                กลับหน้าเมนู
                            </a>
                            <button type="submit" class="profile-save-btn">
                                <i class="bi bi-check2-circle"></i>
                                บันทึกข้อมูล
                            </button>
                        </div>
                    </form>
                </section>
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
