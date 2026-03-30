<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';

ensureUserResetSchema($conn);
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนเข้าใช้งานระบบ</title>

    <link href="assets/vendor/bootstrap-5.0.2/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/local-fonts.css" rel="stylesheet">

    <style>
    body {
        min-height: 100vh;
        margin: 0;
        background-image: url('upload/5-login.jpg');
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

    .register-card {
        width: 100%;
        max-width: 750px;
        background: #ffffff;
        border-radius: 16px;
        padding: 50px 55px;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
        position: relative;
        z-index: 1;
    }

    .header-section {
        text-align: center;
        margin-bottom: 15px;
    }

    .header-section img {
        width: 420px;
        margin: 15px 0;
    }

    .system-title {
        font-size: 26px;
        font-weight: 500;
        letter-spacing: 1px;
        color: #8b0000;
        margin: 0;
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
        font-weight: 500;
        padding: 10px;
    }

    .btn-custom:hover {
        background-color: #600000;
    }
    </style>
</head>

<body>

    <div class="register-card">

        <div class="header-section">
            <img src="upload/4.webp" alt="Logo" decoding="async">
            <h4 class="system-title">ระบบลงทะเบียน</h4>
        </div>

        <form action="register_db.php" method="post" autocomplete="off">

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

            <div class="row">

                <div class="col-md-6 mb-3">
                    <label class="form-label">ตำแหน่ง</label>
                    <input type="text" class="form-control" name="position" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">เลขบัตรประจำตัวประชาชน</label>
                    <input type="text" class="form-control" name="idnumber" maxlength="13" pattern="[0-9]{13}"
                        inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g,'').slice(0,13);"
                        required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">ชื่อ</label>
                    <input type="text" class="form-control" name="firstname" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">นามสกุล</label>
                    <input type="text" class="form-control" name="lastname" required>
                </div>

                <!-- เปลี่ยนจาก Username เป็น ชื่อผู้ใช้ -->
                <div class="col-md-6 mb-3">
                    <label class="form-label">ชื่อผู้ใช้</label>
                    <input type="text" class="form-control" name="username" pattern="^[a-zA-Z0-9_]{3,20}$"
                        title="ชื่อผู้ใช้ต้องมี 3-20 ตัว (อังกฤษ ตัวเลข _ เท่านั้น)" autocomplete="username" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input type="text" class="form-control" name="number" maxlength="10" pattern="[0-9]{9,10}"
                        inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g,'')" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">อีเมล</label>
                    <input type="email" class="form-control" name="email" autocomplete="email" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label class="form-label">รหัสผ่าน</label>
                    <input type="password" class="form-control" name="password" minlength="6"
                        autocomplete="new-password" required>
                </div>

                <div class="col-md-6 mb-4">
                    <label class="form-label">ยืนยันรหัสผ่าน</label>
                    <input type="password" class="form-control" name="c_password" minlength="6"
                        autocomplete="new-password" required>
                </div>

            </div>

            <button type="submit" name="register" class="btn btn-custom w-100">
                ยืนยันลงทะเบียน
            </button>

        </form>

        <hr>

        <div class="text-center">
            เป็นสมาชิกแล้ว ?
            <a href="login.php" class="fw-bold text-decoration-none" style="color:#8b0000;">
                เข้าสู่ระบบ
            </a>
        </div>

    </div>

</body>

</html>
