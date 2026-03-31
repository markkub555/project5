<?php

require_once __DIR__ . '/../includes/env.php';

$dbHost = envValue('DB_HOST', '');
$dbPort = (int) (envValue('DB_PORT', '3306') ?: '3306');
$dbName = envValue('DB_NAME', '');
$dbUser = envValue('DB_USERNAME', '');
$dbPass = envValue('DB_PASSWORD', '');

try {
    $conn = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}
