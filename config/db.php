<?php
$servername = "192.168.1.9";   // IP ตัวเอง
$username   = "shareduser";    // user กลาง
$password   = "1234";

try {
    $conn = new PDO(
        "mysql:host=$servername;dbname=register;charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
