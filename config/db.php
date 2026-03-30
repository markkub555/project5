<?php

$dbHost = getenv('DB_HOST') ?: '192.168.1.17';
$dbPort = (int) (getenv('DB_PORT') ?: 3306);
$dbName = getenv('DB_NAME') ?: 'register';
$dbUser = getenv('DB_USERNAME') ?: 'shareduser';
$dbPass = getenv('DB_PASSWORD') ?: '1234';

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
