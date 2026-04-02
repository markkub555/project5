<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function runtimeStorageDir(): string
{
    $baseDir = dirname(__DIR__, 2) . '/storage/runtime';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0775, true);
    }

    if (!is_dir($baseDir) || !is_writable($baseDir)) {
        $fallbackDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'project5_runtime';
        if (!is_dir($fallbackDir)) {
            @mkdir($fallbackDir, 0775, true);
        }
        return $fallbackDir;
    }

    return $baseDir;
}

function isHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    $trustProxy = envValue('TRUST_PROXY', '0') === '1';
    if ($trustProxy) {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }
    }

    return false;
}

function forceHttpsIfConfigured(): void
{
    if (envValue('FORCE_HTTPS', '0') !== '1') {
        return;
    }

    if (isHttpsRequest()) {
        return;
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return;
    }

    $serverName = strtolower(preg_replace('/:\d+$/', '', $host) ?? '');
    if (in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)) {
        return;
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $host . $requestUri, true, 301);
    exit;
}

function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    forceHttpsIfConfigured();

    $isHttps = isHttpsRequest();

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
    $file = runtimeStorageDir() . DIRECTORY_SEPARATOR . 'project5_rate_' . $hash . '.json';
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
        @file_put_contents($file, json_encode($payload), LOCK_EX);
        return false;
    }

    $payload['count']++;
    @file_put_contents($file, json_encode($payload), LOCK_EX);
    return true;
}
