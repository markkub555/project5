<?php
session_start();
require_once 'config/db.php';

if (isset($_POST['register'])) {

    $position   = trim($_POST['position']);
    $idnumber   = trim($_POST['idnumber']);
    $firstname  = trim($_POST['firstname']);
    $lastname   = trim($_POST['lastname']);
    $username   = trim($_POST['username']);
    $number     = trim($_POST['number']);
    $password   = $_POST['password'];
    $c_password = $_POST['c_password'];

    // ตรวจสอบกรอกครบ
    if (empty($position) || empty($idnumber) || empty($firstname) ||
        empty($lastname) || empty($username) || empty($number) ||
        empty($password) || empty($c_password)) {

        $_SESSION['error'] = "กรุณากรอกข้อมูลให้ครบทุกช่อง";
        header("location: register.php");
        exit();
    }

    // ตรวจสอบรหัสผ่านตรงกัน
    if ($password !== $c_password) {
        $_SESSION['error'] = "รหัสผ่านไม่ตรงกัน";
        header("location: register.php");
        exit();
    }

    try {

        // ตรวจสอบชื่อผู้ใช้ซ้ำ
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);

        if ($check->rowCount() > 0) {
            $_SESSION['error'] = "ชื่อผู้ใช้นี้ถูกใช้งานแล้ว";
            header("location: register.php");
            exit();
        }

        // เข้ารหัสรหัสผ่าน
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // บันทึกข้อมูล
        $sql = "INSERT INTO users 
                (position, idnumber, firstname, lastname, username, number, password) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $position,
            $idnumber,
            $firstname,
            $lastname,
            $username,
            $number,
            $hashed_password
        ]);

        $_SESSION['success'] = "สมัครสมาชิกเรียบร้อยแล้ว กรุณาเข้าสู่ระบบ";
        header("location: login.php"); // ส่งไปหน้า login เลย
        exit();

    } catch (PDOException $e) {

        // ไม่แสดง error ภายในระบบจริง
        $_SESSION['error'] = "เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง";
        header("location: register.php");
        exit();
    }
}
?>