<?php

require_once __DIR__ . '/../includes/env.php';

return [
    'host' => envValue('SMTP_HOST', ''),
    'port' => (int) (envValue('SMTP_PORT', '587') ?: '587'),
    'username' => envValue('SMTP_USERNAME', ''),
    'password' => envValue('SMTP_PASSWORD', ''),
    'from_email' => envValue('SMTP_FROM_EMAIL', ''),
    'from_name' => envValue('SMTP_FROM_NAME', 'Training Center of Provincial Police Region 5'),
    'secure' => envValue('SMTP_SECURE', 'tls'),
];
