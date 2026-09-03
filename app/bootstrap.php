<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('CAT requires PHP 7.4 or newer.');
}

$GLOBALS['cat_config'] = require dirname(__DIR__) . '/config/app.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/TotpService.php';
require_once __DIR__ . '/LoginActivityService.php';
require_once __DIR__ . '/CanSkateRequirements.php';
require_once __DIR__ . '/DashboardRepository.php';
require_once __DIR__ . '/SkaterService.php';
require_once __DIR__ . '/RinkService.php';
require_once __DIR__ . '/LegacyAchievementImportService.php';
require_once __DIR__ . '/UserService.php';
require_once __DIR__ . '/AdminToolsService.php';
require_once __DIR__ . '/ScheduleAdminService.php';
require_once __DIR__ . '/AchievementExportService.php';

date_default_timezone_set((string) config('timezone', 'America/Toronto'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    header_remove('X-Powered-By');
    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $requestIsHttps = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $requestIsHttps = $requestIsHttps
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || $forwardedProto === 'https';
    $forceHttpsEnvironment = getenv('CAT_FORCE_HTTPS');
    $forceHttps = $forceHttpsEnvironment === false
        ? (bool) config('security.force_https', true)
        : filter_var($forceHttpsEnvironment, FILTER_VALIDATE_BOOLEAN);
    if ($forceHttps && !$requestIsHttps) {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if (preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host) !== 1) {
            http_response_code(400);
            exit('Invalid request host.');
        }
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($requestUri === '' || $requestUri[0] !== '/' || strpbrk($requestUri, "\r\n") !== false) {
            $requestUri = '/';
        }
        header('Location: https://' . $host . $requestUri, true, 308);
        exit;
    }
    $hstsMaxAgeEnvironment = getenv('CAT_HSTS_MAX_AGE');
    $hstsMaxAge = $hstsMaxAgeEnvironment === false
        ? (int) config('security.hsts_max_age_seconds', 31536000)
        : max(0, (int) $hstsMaxAgeEnvironment);
    if ($requestIsHttps && $hstsMaxAge > 0) {
        $hsts = 'max-age=' . $hstsMaxAge;
        if ((bool) config('security.hsts_include_subdomains', false)) {
            $hsts .= '; includeSubDomains';
        }
        header('Strict-Transport-Security: ' . $hsts);
    }

    session_name((string) config('session.name', 'cat_session'));
    ini_set('session.gc_maxlifetime', (string) config('session.lifetime_seconds', 3600));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (bool) config('session.secure', false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
    Auth::enforceSessionLifetime();
}
