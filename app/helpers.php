<?php

declare(strict_types=1);

function config(?string $key = null, $default = null)
{
    $config = $GLOBALS['cat_config'] ?? [];

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** PHP 7.4-compatible equivalents of PHP 8 string helpers. */
function string_starts_with(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) === 0;
}

function string_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function string_ends_with(string $haystack, string $needle): bool
{
    return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
}

/**
 * Records a server-side exception outside the public document root and returns
 * a short identifier that support staff can use to locate the event.
 */
function log_application_exception(Throwable $exception): string
{
    try {
        $reference = 'CAT-' . strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $ignored) {
        $reference = 'CAT-' . strtoupper(uniqid('', false));
    }

    $entry = sprintf(
        "[%s] %s\n%s\n\n",
        $reference,
        gmdate('c'),
        (string) $exception
    );
    $logFile = dirname(__DIR__) . '/tmp/cat-error.log';

    if (@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log($entry);
    }

    return $reference;
}

/** Only authenticated administrators may receive a diagnostic exception summary. */
function can_view_diagnostic_exception(): bool
{
    try {
        return class_exists('Auth') && Auth::hasRole('ADMINISTRATOR');
    } catch (Throwable $ignored) {
        return false;
    }
}

function ribbon_class(string $name): string
{
    switch (strtolower($name)) {
        case 'balance': return 'ribbon-balance';
        case 'agility': return 'ribbon-agility';
        case 'control': return 'ribbon-control';
        case 'pre-canskate': return 'ribbon-pre-canskate';
        default: return '';
    }
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('base_path', ''), '/');
    $fragment = '';
    $fragmentPosition = strpos($path, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($path, $fragmentPosition);
        $path = substr($path, 0, $fragmentPosition);
    }

    $query = '';
    $queryPosition = strpos($path, '?');
    if ($queryPosition !== false) {
        $query = substr($path, $queryPosition + 1);
        $path = substr($path, 0, $queryPosition);
    }

    $route = trim($path, '/');
    $location = ($base === '' ? '' : $base) . '/index.php';
    if ($route !== '') {
        $location .= '?route=' . rawurlencode($route);
    }
    if ($query !== '') {
        $location .= ($route === '' ? '?' : '&') . $query;
    }

    return $location . $fragment;
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    $base = rtrim((string) config('base_path', ''), '/');
    $assetUrl = ($base === '' ? '' : $base) . '/assets/' . $path;
    $assetFile = dirname(__DIR__) . '/public/assets/' . $path;

    return is_file($assetFile)
        ? $assetUrl . '?v=' . filemtime($assetFile)
        : $assetUrl;
}

function skate_canada_badge_assets(): array
{
    return [
        'emblem' => asset('skate-canada-emblem.png'),
        'emblem_outline' => asset('skate-canada-emblem-outline.png'),
        'shape' => asset('skate-canada-badge-shape.png'),
        'outline' => asset('skate-canada-badge-outline.png'),
    ];
}

function skate_canada_badge_icon(): string
{
    static $instance = 0;
    $instance++;
    $maskPrefix = 'skate-canada-badge-' . $instance;
    $assets = skate_canada_badge_assets();

    return '<svg viewBox="0 0 87 72" aria-hidden="true"><defs>'
        . '<mask id="' . e($maskPrefix . '-fill') . '" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="' . e($assets['shape']) . '" x="0" y="0" width="87" height="72" preserveAspectRatio="none"></image></mask>'
        . '<mask id="' . e($maskPrefix . '-outline') . '" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="' . e($assets['outline']) . '" x="0" y="0" width="87" height="72" preserveAspectRatio="none"></image></mask>'
        . '<mask id="' . e($maskPrefix . '-mark-outline') . '" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="' . e($assets['emblem_outline']) . '" x="17.5" y="10" width="52" height="52" preserveAspectRatio="xMidYMid meet"></image></mask>'
        . '</defs><rect class="badge-icon-shape badge-icon-fill" x="0" y="0" width="87" height="72" mask="url(#' . e($maskPrefix . '-fill') . ')"></rect>'
        . '<rect class="badge-icon-outline" x="0" y="0" width="87" height="72" mask="url(#' . e($maskPrefix . '-outline') . ')"></rect>'
        . '<rect class="badge-icon-mark-outline" x="17.5" y="10" width="52" height="52" mask="url(#' . e($maskPrefix . '-mark-outline') . ')"></rect>'
        . '<image class="badge-icon-mark" href="' . e($assets['emblem']) . '" x="17.5" y="10" width="52" height="52" preserveAspectRatio="xMidYMid meet"></image></svg>';
}

function redirect(string $path): void
{
    header('Location: ' . url($path), true, 302);
    exit;
}

function authenticated_landing_path(array $user): string
{
    $rememberedPath = $_SESSION['post_login_destination'] ?? null;
    unset($_SESSION['post_login_destination']);

    if (
        $rememberedPath === 'legacy-achievement-import'
        && ($user['role_code'] ?? '') === 'ADMINISTRATOR'
    ) {
        return $rememberedPath;
    }
    if ($rememberedPath === 'rink-app') {
        return $rememberedPath;
    }

    return ($user['role_code'] ?? '') === 'COACH' ? 'rink-app' : 'dashboard';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_is_valid(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flashes) ? $flashes : [];
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/app/views/' . $view . '.php';
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        json_response(['error' => 'The request body is not valid JSON.'], 400);
    }

    return is_array($data) ? $data : [];
}

function format_date(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));

    return $date ? $date->format('M j, Y') : $fallback;
}

function format_datetime(?string $value, string $fallback = '—'): string
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), new DateTimeZone('UTC'));

    return $date ? $date->setTimezone(new DateTimeZone((string) config('timezone', 'UTC')))->format('M j, Y g:i A') : $fallback;
}

function format_time(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $time = DateTimeImmutable::createFromFormat('H:i:s', $value);

    return $time ? $time->format('H:i') : $value;
}
