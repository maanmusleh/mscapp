<?php

declare(strict_types=1);

// This file is the distribution-safe bootstrap configuration. The web installer
// writes installed.php (outside the public directory) on first run.
$installedConfig = __DIR__ . '/installed.php';
if (is_file($installedConfig)) {
    return require $installedConfig;
}

return [
    'name' => 'CanSkate Achievement Tracker',
    'installed' => false,
    'environment' => 'production',
    'timezone' => 'America/Toronto',
    'base_path' => '',
    'session' => [
        'name' => 'cat_session',
        'secure' => true,
        'lifetime_seconds' => 3600,
        'absolute_lifetime_seconds' => 43200,
    ],
    'database' => [
        'dsn' => '',
        'username' => '',
        'password' => '',
    ],
    'security' => [
        'totp_encryption_key' => '',
        'admin_setup_token' => '',
        'force_https' => false,
        'hsts_max_age_seconds' => 0,
        'hsts_include_subdomains' => false,
    ],
];
