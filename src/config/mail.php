<?php

return [
    'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port' => (int) (getenv('SMTP_PORT') ?: 587),
    'username' => getenv('SMTP_USERNAME') ?: 'panu.060944@gmail.com',
    'password' => getenv('SMTP_PASSWORD') ?: 'tppr ltyb kxcn nqgm',
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'panu.060944@gmail.com',
    'from_name' => getenv('SMTP_FROM_NAME') ?: 'Training Center of Provincial Police Region 5',
    'secure' => getenv('SMTP_SECURE') ?: 'tls',
];
