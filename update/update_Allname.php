<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_login'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($csrfToken === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload) || $payload === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$normalize = static function ($value, int $maxLength): string {
    $text = trim((string) $value);
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength);
    }

    return $text;
};

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare(
        'UPDATE applicantname
         SET prefix = :prefix,
             firstname = :firstname,
             lastname = :lastname
         WHERE id = :id'
    );

    $updated = 0;

    foreach ($payload as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $prefix = $normalize($row['prefix'] ?? '', 30);
        $firstname = $normalize($row['firstname'] ?? '', 100);
        $lastname = $normalize($row['lastname'] ?? '', 100);

        if ($firstname === '' || $lastname === '') {
            continue;
        }

        $stmt->execute([
            ':prefix' => $prefix,
            ':firstname' => $firstname,
            ':lastname' => $lastname,
            ':id' => $id,
        ]);

        $updated += $stmt->rowCount();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'updated' => $updated,
    ]);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
    ]);
}
