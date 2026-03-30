<?php

session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/ensure_user_reset_schema.php';

ensureUserResetSchema($conn);

if (isset($_POST['login'])) {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

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
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($stmt->rowCount() > 0) {

            // ตรวจสอบรหัสผ่าน
            if (password_verify($password, $row['password'])) {

                if ($row['position'] === 'admin') {
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

                    $_SESSION['user_login'] = $row['id'];
                    header("location: import_gptv1.php");
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
