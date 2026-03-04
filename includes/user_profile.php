<?php

declare(strict_types=1);

function getCurrentUserProfile(PDO $conn): array
{
    $default = [
        'firstname' => 'ผู้ใช้',
        'lastname' => '',
        'fullname' => 'ผู้ใช้',
        'username' => '',
    ];

    if (!isset($_SESSION['user_login'])) {
        return $default;
    }

    try {
        $stmt = $conn->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', (int) $_SESSION['user_login'], PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        $firstname = trim((string) ($row['firstname'] ?? $row['first_name'] ?? ''));
        $lastname = trim((string) ($row['lastname'] ?? $row['last_name'] ?? ''));

        if ($firstname === '' && $lastname === '') {
            $name = trim((string) ($row['name'] ?? $row['fullname'] ?? ''));
            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 2);
                $firstname = trim((string) ($parts[0] ?? ''));
                $lastname = trim((string) ($parts[1] ?? ''));
            }
        }

        $username = trim((string) ($row['username'] ?? ''));

        if ($firstname === '' && $username !== '') {
            $firstname = $username;
        }

        if ($firstname === '') {
            $firstname = 'ผู้ใช้';
        }

        $fullname = trim($firstname . ' ' . $lastname);

        return [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'fullname' => $fullname !== '' ? $fullname : $firstname,
            'username' => $username,
        ];
    } catch (Throwable $e) {
        return $default;
    }
}
