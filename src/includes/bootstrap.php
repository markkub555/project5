<?php

declare(strict_types=1);

function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
    );

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function clientIpAddress(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function consumeRateLimit(string $bucket, int $limit, int $windowSeconds, ?string $key = null): bool
{
    $identity = $key !== null && $key !== '' ? $key : clientIpAddress();
    $hash = hash('sha256', $bucket . '|' . $identity);
    $file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'project5_rate_' . $hash . '.json';
    $now = time();
    $payload = [
        'count' => 0,
        'reset_at' => $now + $windowSeconds,
    ];

    if (is_file($file)) {
        $raw = file_get_contents($file);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $payload['count'] = (int) ($decoded['count'] ?? 0);
            $payload['reset_at'] = (int) ($decoded['reset_at'] ?? ($now + $windowSeconds));
        }
    }

    if ($payload['reset_at'] <= $now) {
        $payload = [
            'count' => 0,
            'reset_at' => $now + $windowSeconds,
        ];
    }

    if ($payload['count'] >= $limit) {
        file_put_contents($file, json_encode($payload), LOCK_EX);
        return false;
    }

    $payload['count']++;
    file_put_contents($file, json_encode($payload), LOCK_EX);
    return true;
}
