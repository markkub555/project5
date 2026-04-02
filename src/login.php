<?php
require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap 5 -->
    <link href="assets/vendor/bootstrap-5.0.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
    body {
        min-height: 100vh;
        margin: 0;
        background-color: #f5f0f0;
        background-image: url('upload/5-login.jpg');
        background-repeat: no-repeat;
        background-position: center;
        background-size: cover;
        position: relative;
        padding: 16px;
    }

    .login-card {
        border-radius: 15px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
    }

    .form-control:focus {
        box-shadow: none !important;
        border-color: #ced4da !important;
    }

    .btn-custom {
        background-color: #b30000;
        border-color: #b30000;
        color: #ffffff;
    }

    .btn-custom:hover {
        background-color: #8b0000;
        border-color: #8b0000;
        color: #ffffff;
    }

    .login-shell {
        width: 100%;
        max-width: 420px;
    }

    @media (max-width: 576px) {
        body {
            align-items: stretch !important;
            padding: 12px;
        }

        .login-shell {
            max-width: none;
            display: flex;
            align-items: center;
        }

        .login-card {
            width: 100%;
            padding: 1.1rem !important;
            border-radius: 14px;
        }

        .text-center h3 {
            font-size: 1.35rem;
        }
    }
    </style>
</head>

<body class="d-flex align-items-center justify-content-center">

    <div class="login-shell">
        <div class="card login-card p-4">

            <div class="text-center mb-4">
                <h3 class="fw-bold">🔐 เข้าสู่ระบบ</h3>
                <p class="text-muted">กรอกข้อมูลเพื่อเข้าสู่ระบบ</p>
            </div>

            <!-- แสดงข้อความแจ้งเตือน -->
            <?php if (isset($_SESSION['error'])) { ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
            </div>
            <?php } ?>

            <?php if (isset($_SESSION['success'])) { ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
            </div>
            <?php } ?>

            <form action="login_db.php" method="post" autocomplete="off">

                <!-- เปลี่ยนจาก อีเมล เป็น ชื่อผู้ใช้ -->
                <div class="mb-3">
                    <label class="form-label">ชื่อผู้ใช้</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" name="username" autocomplete="username" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">รหัสผ่าน</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" name="password" autocomplete="current-password"
                            required>
                    </div>
                </div>

                <button type="submit" name="login" class="btn btn-primary btn-custom w-100">
                    <i class="bi bi-box-arrow-in-right"></i> เข้าสู่ระบบ
                </button>

            </form>

            <hr>

            <div class="text-center">
                <small>ยังไม่เป็นสมาชิก?
                    <a href="index.php" class="text-decoration-none fw-bold">สมัครสมาชิก</a>
                </small>
            </div>

            <div class="text-center mt-2">
                <a href="forgot_password.php" class="text-decoration-none" style="color:#b30000; font-size:0.9rem;">
                    ลืมรหัสผ่าน?
                </a>
            </div>

        </div>
    </div>

</body>

</html>
