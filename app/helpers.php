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
 * Stops requests safely when the installed schema does not match this release.
 * The detailed checklist is intentionally operational, not a request to run
 * database-changing SQL from the browser.
 *
 * @param array{missing_tables: string[], missing_migrations: string[], inconsistent_migrations: array<string, string[]>, out_of_order_migrations: array<string, string[]>, missing_schema: string[], missing_schema_by_migration: array<string, string[]>} $health
 * @param string[] $bundleVersions
 */
function render_schema_migration_required(array $health, string $requestPath, array $bundleVersions = []): void
{
    http_response_code(503);
    header('Retry-After: 300');

    $missingTables = $health['missing_tables'];
    $missingMigrations = $health['missing_migrations'];
    $inconsistent = $health['inconsistent_migrations'];
    $outOfOrder = $health['out_of_order_migrations'];
    $migrationCreatedTables = [
        'report_card_note_library', 'skater_season_report_card_note', 'rink_offline_change',
    ];
    $missingBaseTables = array_values(array_diff($missingTables, $migrationCreatedTables));
    $applyableMigrations = $bundleVersions;
    $manualReviewMigrations = [];
    if ($bundleVersions === [] && $inconsistent === [] && $outOfOrder === [] && $missingBaseTables === []) {
        foreach ($missingMigrations as $version) {
            $missingForMigration = $health['missing_schema_by_migration'][$version] ?? [];
            if ($missingForMigration !== [] && !isset($outOfOrder[$version]) && SchemaHealthService::migrationFile($version) !== null && $missingBaseTables === []) {
                $applyableMigrations[] = $version;
            } else {
                $manualReviewMigrations[] = $version;
            }
        }
    }

    if (string_starts_with($requestPath, '/api/')) {
        json_response([
            'error' => 'CAT database migration required. The application is temporarily unavailable while an administrator completes the upgrade.',
            'missing_migrations' => $missingMigrations,
            'missing_tables' => $missingTables,
            'missing_schema' => $health['missing_schema'],
        ], 503);
    }

    $list = static function (array $items): string {
        if ($items === []) {
            return '';
        }
        return '<ul><li>' . implode('</li><li>', array_map('e', $items)) . '</li></ul>';
    };

    $instructions = '<ol>'
        . '<li>Put CAT into maintenance mode and create a verified database backup.</li>'
        . '<li>Deploy the complete matching CAT release, preserving <code>config/installed.php</code>.</li>';
    if ($applyableMigrations !== []) {
        $files = [];
        foreach ($applyableMigrations as $version) {
            $files[] = $version . ': ' . (string) SchemaHealthService::migrationFile($version);
        }
        $instructions .= '<li>Apply these missing migration files once, in version order, to the configured CAT database:'
            . $list($files) . '</li>';
    }
    if ($manualReviewMigrations !== []) {
        $instructions .= '<li>These migration records need a manual review before changing the database: '
            . e(implode(', ', $manualReviewMigrations))
            . '. Their required structure is already present, cannot be verified from this release, or a base table is missing. Do not run a migration merely to recreate its record.</li>';
    }
    if ($inconsistent !== []) {
        $items = [];
        foreach ($inconsistent as $version => $requirements) {
            $items[] = $version . ' is recorded as installed but is missing ' . implode(', ', $requirements);
        }
        $instructions .= '<li><strong>Do not rerun the listed migration blindly.</strong> Its migration record and schema disagree. Restore the backup or repair the missing item with a database administrator after comparing the migration file.'
            . $list($items) . '</li>';
    }
    if ($outOfOrder !== []) {
        $items = [];
        foreach ($outOfOrder as $version => $laterVersions) {
            $items[] = $version . ' is missing even though later migration records exist: ' . implode(', ', $laterVersions);
        }
        $instructions .= '<li><strong>Do not apply the missing migration out of sequence.</strong> Restore a known-good backup or have a database administrator reconcile the migration history.'
            . $list($items) . '</li>';
    }
    if ($missingBaseTables !== []) {
        $instructions .= '<li><strong>Do not run <code>createBlankDb.sql</code> against this database.</strong> Required base tables are missing. Restore a known-good CAT backup or have a database administrator repair the incomplete schema.'
            . $list($missingBaseTables) . '</li>';
    }
    $instructions .= '<li>Reload CAT after the database has been upgraded. This check will clear automatically when all requirements are present.</li></ol>';

    $download = $bundleVersions === []
        ? '<p><strong>A combined migration download is unavailable for this database state.</strong> Follow the manual-review instructions above.</p>'
        : '<p><a href="' . e(url('schema-migration-download')) . '">Download the complete migration SQL script</a> '
            . 'for versions ' . e($bundleVersions[0]) . ' through ' . e($bundleVersions[count($bundleVersions) - 1]) . '.</p>';

    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>CAT database upgrade required</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:820px;margin:4rem auto;padding:0 1.5rem;color:#1d2939}main{border:1px solid #f0b429;border-radius:10px;padding:2rem;background:#fffbeb}h1{margin-top:0}code{background:#f3f4f6;padding:.1rem .3rem;border-radius:3px;word-break:break-all}strong{color:#9a3412}</style></head><body><main><h1>CAT database upgrade required</h1><p>This CAT release detected that the configured database does not meet its schema requirements. No database changes have been made; CAT is temporarily unavailable to prevent partial writes.</p>'
        . ($missingMigrations !== [] ? '<h2>Missing migration records</h2>' . $list($missingMigrations) : '')
        . ($health['missing_schema'] !== [] ? '<h2>Missing schema requirements</h2>' . $list($health['missing_schema']) : '')
        . '<h2>Administrator checklist</h2>' . $instructions . $download
        . '<p>See <code>docs/SETUP.md</code> in this release for the upgrade procedure.</p></main></body></html>');
}

/** @param string[] $versions */
function download_schema_migration_bundle(array $versions): void
{
    if ($versions === []) {
        http_response_code(404);
        exit('No safe CAT migration bundle is available for this database state.');
    }

    $priorVersions = SchemaHealthService::priorMigrationVersions($versions[0]);
    $quoteVersions = static function (array $items): string {
        return implode(', ', array_map(static function (string $item): string {
            return "'" . str_replace("'", "''", $item) . "'";
        }, $items));
    };
    $priorVersionSql = $quoteVersions($priorVersions);
    $bundleVersionSql = $quoteVersions($versions);
    $requiredPriorCount = count($priorVersions);

    $sql = "-- CAT database upgrade bundle\n"
        . "-- Generated by CAT after a read-only schema verification.\n"
        . "-- This bundle makes schema changes. Back up and verify the CAT database before importing it.\n"
        . "-- It preserves legacy report-card-note columns; it never drops tables or columns.\n"
        . "-- Run it once against the configured CAT database, then reload CAT.\n\n"
        . "DELIMITER //\n"
        . "CREATE PROCEDURE cat_verify_migration_bundle()\n"
        . "BEGIN\n"
        . "  DECLARE migration_table_count INT DEFAULT 0;\n"
        . "  DECLARE applied_prior_count INT DEFAULT 0;\n"
        . "  DECLARE already_applied_count INT DEFAULT 0;\n"
        . "  DECLARE recorded_bundle_versions TEXT DEFAULT '';\n"
        . "  DECLARE failure_message VARCHAR(128) DEFAULT '';\n"
        . "  SELECT COUNT(*) INTO migration_table_count\n"
        . "  FROM information_schema.TABLES\n"
        . "  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migration' AND TABLE_TYPE = 'BASE TABLE';\n"
        . "  IF migration_table_count <> 1 THEN\n"
        . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CAT migration bundle stopped: schema_migration is missing.';\n"
        . "  END IF;\n"
        . "  SELECT COUNT(*) INTO applied_prior_count FROM schema_migration\n"
        . "  WHERE version_number IN (" . $priorVersionSql . ");\n"
        . "  IF applied_prior_count <> " . $requiredPriorCount . " THEN\n"
        . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CAT migration bundle stopped: the database is not at the expected starting version.';\n"
        . "  END IF;\n"
        . "  SELECT COUNT(*) INTO already_applied_count FROM schema_migration\n"
        . "  WHERE version_number IN (" . $bundleVersionSql . ");\n"
        . "  IF already_applied_count <> 0 THEN\n"
        . "    SELECT GROUP_CONCAT(version_number ORDER BY version_number SEPARATOR ', ') INTO recorded_bundle_versions\n"
        . "    FROM schema_migration WHERE version_number IN (" . $bundleVersionSql . ");\n"
        . "    SET failure_message = CONCAT('CAT migration bundle stopped in ', DATABASE(), ': already recorded ', recorded_bundle_versions);\n"
        . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = failure_message;\n"
        . "  END IF;\n"
        . "END//\n"
        . "CALL cat_verify_migration_bundle()//\n"
        . "DROP PROCEDURE cat_verify_migration_bundle//\n"
        . "DELIMITER ;\n\n";
    foreach ($versions as $version) {
        $migrationFile = SchemaHealthService::migrationFile($version);
        if ($migrationFile === null) {
            http_response_code(500);
            exit('CAT could not build the migration bundle.');
        }
        $path = dirname(__DIR__) . '/' . $migrationFile;
        $contents = file_get_contents($path);
        if ($contents === false) {
            http_response_code(500);
            exit('CAT could not read a required migration file. Deploy the complete CAT release and try again.');
        }
        $sql .= "-- ============================================================================\n"
            . '-- Migration ' . $version . ': ' . $migrationFile . "\n"
            . "-- ============================================================================\n\n"
            . rtrim($contents) . "\n\n";
    }

    $filename = 'cat-migration-' . $versions[0] . '-to-' . $versions[count($versions) - 1] . '.sql';
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $sql;
    exit;
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
