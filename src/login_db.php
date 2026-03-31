<?php

require_once __DIR__ . '/includes/bootstrap.php';
secureSessionStart();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';

ensureUserResetSchema($conn);

if (isset($_POST['login'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!consumeRateLimit('login_attempt', 10, 900, clientIpAddress() . '|' . mb_strtolower($username))) {
        $_SESSION['error'] = 'พยายามเข้าสู่ระบบบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่';
        header("location: login.php");
        exit();
    }

    // ตรวจสอบชื่อผู้ใช้
    if (empty($username)) {
        $_SESSION['error'] = 'กรุณากรอกชื่อผู้ใช้';
        header("location: login.php");
        exit();
    }

    // ตรวจสอบรหัสผ่าน
    if (empty($password)) {
        $_SESSION['error'] = 'กรุณากรอกรหัสผ่าน';
        header("location: login.php");
        exit();
    }

    try {

        // ค้นหาข้อมูลจาก username
        $stmt = $conn->prepare(
            "SELECT id, position, password, userstatus
             FROM users
             WHERE username = :username
             LIMIT 1"
        );
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stmt->rowCount() > 0) {

            // ตรวจสอบรหัสผ่าน
            if (password_verify($password, $row['password'])) {

                if ($row['position'] === 'admin') {
                    session_regenerate_id(true);
                    $_SESSION['admin_login'] = $row['id'];
                    header("location: admin.php");
                    exit();
                } else {
                    $userStatus = strtoupper(trim((string) ($row['userstatus'] ?? '')));
                    if ($userStatus === 'W') {
                        $_SESSION['error'] = 'บัญชีของคุณกำลังรอการยืนยันสิทธิ์เข้าใช้งาน';
                        header("location: login.php");
                        exit();
                    }

                    if ($userStatus === 'F') {
                        $_SESSION['error'] = 'บัญชีของคุณไม่ได้รับสิทธิ์เข้าใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
                        header("location: login.php");
                        exit();
                    }

                    session_regenerate_id(true);
                    $_SESSION['user_login'] = $row['id'];
                    header("location: import_gptV1.php");
                    exit();
                }

            } else {
                $_SESSION['error'] = 'รหัสผ่านไม่ถูกต้อง';
                header("location: login.php");
                exit();
            }

        } else {
            $_SESSION['error'] = "ไม่พบชื่อผู้ใช้ในระบบ";
            header("location: login.php");
            exit();
        }

    } catch (PDOException $e) {

        $_SESSION['error'] = "เกิดข้อผิดพลาดในระบบ";
        header("location: login.php");
        exit();
    }
}
?>
