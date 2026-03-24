<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/phpmailer/src/SMTP.php';

ensureUserResetSchema($conn);

$mailConfig = require __DIR__ . '/config/mail.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$emailValue = '';
$requestMessage = '';
$requestError = '';
$verifyMessage = '';
$verifyError = '';

$sendOtpMail = static function (string $toEmail, string $otpCode) use ($mailConfig): array {
    $requiredKeys = ['host', 'username', 'password', 'from_email'];
    foreach ($requiredKeys as $key) {
        if (!isset($mailConfig[$key]) || trim((string) $mailConfig[$key]) === '') {
            return [
                'success' => false,
                'error' => 'ยังไม่ได้ตั้งค่า SMTP ใน config/mail.php หรือ environment variables',
            ];
        }
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $mailConfig['host'];
        $mail->Port = (int) ($mailConfig['port'] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = (string) $mailConfig['username'];
        $mail->Password = (string) $mailConfig['password'];
        $mail->CharSet = 'UTF-8';

        $secure = strtolower(trim((string) ($mailConfig['secure'] ?? 'tls')));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom((string) $mailConfig['from_email'], (string) ($mailConfig['from_name'] ?? 'Training Center of Provincial Police Region 5'));
        $mail->addAddress($toEmail);
        $mail->isHTML(false);
        $mail->Subject = 'OTP สำหรับเปลี่ยนรหัสผ่าน';
        $mail->Body = "รหัส OTP สำหรับเปลี่ยนรหัสผ่านของคุณคือ: {$otpCode}\nรหัสนี้จะหมดอายุใน 15 นาที\nหากคุณไม่ได้เป็นผู้ร้องขอ กรุณาเพิกเฉยต่ออีเมลฉบับนี้";
        $mail->send();

        return ['success' => true];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'ส่งอีเมลไม่สำเร็จ: ' . $e->getMessage(),
        ];
    }
};

if (isset($_SESSION['forgot_success'])) {
    $verifyMessage = (string) $_SESSION['forgot_success'];
    unset($_SESSION['forgot_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $requestError = 'การยืนยันไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } elseif (($_POST['action'] ?? '') === 'send_otp') {
        $emailValue = trim((string) ($_POST['email'] ?? ''));

        if ($emailValue === '' || !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
            $requestError = 'กรุณากรอกอีเมลให้ถูกต้อง';
        } else {
            $stmt = $conn->prepare('SELECT id, firstname, lastname, email FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $emailValue]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $requestError = 'ไม่พบอีเมลนี้ในระบบ';
            } else {
                $resetToken = bin2hex(random_bytes(32));
                $resetCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $resetExpire = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

                $updateStmt = $conn->prepare(
                    'UPDATE users
                     SET token = :token,
                         expire = :expire,
                         code = :code
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':token' => $resetToken,
                    ':expire' => $resetExpire,
                    ':code' => $resetCode,
                    ':id' => (int) $user['id'],
                ]);

                $_SESSION['forgot_reset_email'] = $emailValue;
                $_SESSION['forgot_reset_token'] = $resetToken;

                $mailResult = $sendOtpMail($emailValue, $resetCode);

                if ($mailResult['success']) {
                    $requestMessage = 'ส่ง OTP ไปยังอีเมลเรียบร้อยแล้ว';
                } else {
                    $requestError = (string) $mailResult['error'];
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reset_password') {
        $emailValue = trim((string) ($_POST['email'] ?? ''));
        $otpCode = trim((string) ($_POST['otp_code'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $sessionEmail = (string) ($_SESSION['forgot_reset_email'] ?? '');
        $sessionTokenValue = (string) ($_SESSION['forgot_reset_token'] ?? '');

        if ($emailValue === '' || $otpCode === '' || $password === '' || $confirmPassword === '') {
            $verifyError = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        } elseif ($password !== $confirmPassword) {
            $verifyError = 'รหัสผ่านไม่ตรงกัน';
        } elseif (strlen($password) < 6) {
            $verifyError = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($sessionEmail === '' || $sessionTokenValue === '' || $sessionEmail !== $emailValue) {
            $verifyError = 'เซสชันหมดอายุ กรุณาขอ OTP ใหม่';
        } else {
            $stmt = $conn->prepare(
                'SELECT id, token, expire, code
                 FROM users
                 WHERE email = :email LIMIT 1'
            );
            $stmt->execute([':email' => $emailValue]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $expired = true;
            if ($user && !empty($user['expire'])) {
                $expired = strtotime((string) $user['expire']) < time();
            }

            if (
                !$user ||
                (string) ($user['token'] ?? '') !== $sessionTokenValue ||
                (string) ($user['code'] ?? '') !== $otpCode
            ) {
                $verifyError = 'OTP ไม่ถูกต้อง';
            } elseif ($expired) {
                $verifyError = 'OTP หมดอายุแล้ว กรุณาขอใหม่';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $updateStmt = $conn->prepare(
                    'UPDATE users
                     SET password = :password,
                         token = NULL,
                         expire = NULL,
                         code = NULL
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':password' => $hashedPassword,
                    ':id' => (int) $user['id'],
                ]);

                unset($_SESSION['forgot_reset_email'], $_SESSION['forgot_reset_token']);
                $_SESSION['forgot_success'] = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่';
                header('Location: forgot_password.php');
                exit;
            }
        }
    }
}

if (isset($_SESSION['forgot_reset_email'], $_SESSION['forgot_reset_token']) && $emailValue === '') {
    $emailValue = (string) $_SESSION['forgot_reset_email'];
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>ลืมรหัสผ่าน</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background-image: url('upload/5.png');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Segoe UI", Tahoma, sans-serif;
            position: relative;
            padding: 20px;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            backdrop-filter: blur(12px);
            background: rgba(0, 0, 0, 0.08);
            z-index: 0;
        }

        .forgot-card {
            width: 100%;
            max-width: 720px;
            background: #ffffff;
            border-radius: 16px;
            padding: 42px 46px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            position: relative;
            z-index: 1;
        }

        .header-section {
            text-align: center;
            margin-bottom: 18px;
        }

        .header-section img {
            width: 360px;
            max-width: 100%;
            margin: 10px 0 16px;
        }

        .system-title {
            font-size: 24px;
            font-weight: 600;
            color: #8b0000;
            margin: 0;
        }

        .system-subtitle {
            margin-top: 6px;
            color: #6b7280;
            font-size: 0.95rem;
        }

        .form-label {
            font-weight: 500;
        }

        .form-control:focus {
            border-color: #8b0000 !important;
            box-shadow: 0 0 0 0.2rem rgba(139, 0, 0, 0.25) !important;
        }

        .btn-custom {
            background-color: #8b0000;
            border-color: #8b0000;
            color: #fff;
            font-weight: 600;
            padding: 10px;
        }

        .btn-custom:hover {
            background-color: #600000;
            border-color: #600000;
            color: #fff;
        }

        .soft-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            margin-top: 12px;
        }
    </style>
</head>

<body>
    <div class="forgot-card">
        <div class="header-section">
            <img src="upload/4.webp" alt="Logo">
            <h4 class="system-title">ลืมรหัสผ่าน</h4>
            <div class="system-subtitle">รับ OTP ทางอีเมลและตั้งรหัสผ่านใหม่</div>
        </div>

        <?php if ($requestError !== ''): ?>
            <div class="alert alert-danger"><?= $h($requestError) ?></div>
        <?php endif; ?>
        <?php if ($requestMessage !== ''): ?>
            <div class="alert alert-success"><?= $h($requestMessage) ?></div>
        <?php endif; ?>
        <?php if ($verifyError !== ''): ?>
            <div class="alert alert-danger"><?= $h($verifyError) ?></div>
        <?php endif; ?>
        <?php if ($verifyMessage !== ''): ?>
            <div class="alert alert-success"><?= $h($verifyMessage) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="action" value="send_otp">

            <div class="mb-3">
                <label class="form-label">อีเมลที่ลงทะเบียนไว้</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" name="email" value="<?= $h($emailValue) ?>" required>
                </div>
            </div>

            <button type="submit" class="btn btn-custom w-100">
                <i class="bi bi-send"></i> ส่ง OTP
            </button>
        </form>

        <div class="soft-box">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="action" value="reset_password">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">อีเมล</label>
                        <input type="email" class="form-control" name="email" value="<?= $h($emailValue) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">OTP</label>
                        <input type="text" class="form-control" name="otp_code" maxlength="6" inputmode="numeric" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">รหัสผ่านใหม่</label>
                        <input type="password" class="form-control" name="password" minlength="6" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                        <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-custom w-100 mt-3">
                    <i class="bi bi-shield-lock"></i> เปลี่ยนรหัสผ่าน
                </button>
            </form>
        </div>

        <div class="text-center mt-4">
            <a href="login.php" class="fw-bold text-decoration-none" style="color:#8b0000;">
                กลับไปหน้าเข้าสู่ระบบ
            </a>
        </div>
    </div>
</body>

</html>
