<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

if (config('installed', false)) {
    header('Location: ' . url('login'), true, 302);
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$error = null;
$pdo = null;
$configWritten = false;
$temporaryConfig = null;
$destination = dirname(__DIR__) . '/config/installed.php';
$values = [
    'host' => 'localhost', 'port' => '3306',
    'database' => '', 'username' => '', 'club_name' => '',
    'time_zone' => 'America/Toronto', 'first_name' => '', 'last_name' => '', 'email' => '',
];

if (!$isHttps && !$isLocal) {
    $error = 'Open this installer over HTTPS before entering database credentials.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $dbPassword = (string) ($_POST['database_password'] ?? '');

    try {
        if (!csrf_is_valid($_POST['_token'] ?? null)) throw new InvalidArgumentException('The form expired. Refresh and try again.');
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $values['host'])) throw new InvalidArgumentException('Enter a valid database host.');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $values['database'])) throw new InvalidArgumentException('Enter a valid database name.');
        if (!ctype_digit($values['port']) || (int) $values['port'] < 1 || (int) $values['port'] > 65535) throw new InvalidArgumentException('Enter a valid database port.');
        if ($values['username'] === '' || $values['club_name'] === '' || !in_array($values['time_zone'], DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Complete all required fields.');
        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false || $values['first_name'] === '') throw new InvalidArgumentException('Enter the administrator’s name and email.');
        if (strlen($password) < 12 || !hash_equals($password, $confirmation)) throw new InvalidArgumentException('Passwords must match and contain at least 12 characters.');

        $configDirectory = dirname($destination);
        if (!is_dir($configDirectory)) {
            throw new RuntimeException('The config folder is missing. Upload the complete CAT application and try again.');
        }
        $writeProbe = @tempnam($configDirectory, 'cat-install-');
        if ($writeProbe === false) {
            throw new RuntimeException('CAT cannot write to the config folder. Make it writable by the web-server user before continuing.');
        }
        if (!@unlink($writeProbe)) {
            throw new RuntimeException('CAT could not remove a temporary file from the config folder. Check its ownership and permissions before continuing.');
        }

        $dsn = "mysql:host={$values['host']};port={$values['port']};dbname={$values['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $values['username'], $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $tableCount = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        $schemaReady = false;
        if ($tableCount !== 0) {
            $hasUsersTable = (bool) $pdo->query("SHOW TABLES LIKE 'app_user'")->fetchColumn();
            $hasClubTable = (bool) $pdo->query("SHOW TABLES LIKE 'club'")->fetchColumn();
            $userCount = $hasUsersTable ? (int) $pdo->query('SELECT COUNT(*) FROM app_user')->fetchColumn() : -1;
            if (!$hasUsersTable || !$hasClubTable || $userCount !== 0) {
                throw new RuntimeException('The selected database is not empty. CAT will only install into a new empty database.');
            }
            $schemaReady = true;
        }
        if (!$schemaReady) {
            $sql = file_get_contents(dirname(__DIR__) . '/createBlankDb.sql');
            if ($sql === false) throw new RuntimeException('The fresh-install SQL file is missing.');
            $pdo->exec($sql);
        }

        $pdo->beginTransaction();
        $clubId = $pdo->query('SELECT id FROM club WHERE active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();
        if ($clubId === false) {
            $club = $pdo->prepare('INSERT INTO club (club_name, time_zone, active) VALUES (:club_name, :time_zone, 1)');
            $club->execute(['club_name' => $values['club_name'], 'time_zone' => $values['time_zone']]);
        }
        $roleId = $pdo->query("SELECT id FROM user_role WHERE code = 'ADMINISTRATOR' LIMIT 1")->fetchColumn();
        $user = $pdo->prepare('INSERT INTO app_user (user_role_id, first_name, last_name, username, password_hash, email, active) VALUES (:role, :first, :last, :username, :hash, :email, 1)');
        $user->execute([
            'role' => $roleId, 'first' => $values['first_name'], 'last' => $values['last_name'] ?: null,
            'username' => $values['email'], 'email' => $values['email'], 'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install.php')), '/');
        $config = [
            'name' => 'CanSkate Achievement Tracker', 'installed' => true, 'environment' => 'production',
            'timezone' => $values['time_zone'], 'base_path' => $basePath,
            'session' => [
                'name' => 'cat_session',
                'secure' => $isHttps,
                'lifetime_seconds' => 3600,
                'absolute_lifetime_seconds' => 43200,
            ],
            'database' => ['dsn' => $dsn, 'username' => $values['username'], 'password' => $dbPassword],
            'security' => [
                'totp_encryption_key' => base64_encode(random_bytes(32)),
                'admin_setup_token' => '',
                'force_https' => $isHttps,
                'hsts_max_age_seconds' => $isHttps ? 31536000 : 0,
                'hsts_include_subdomains' => false,
            ],
        ];
        $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $temporaryConfig = @tempnam($configDirectory, 'cat-config-');
        if ($temporaryConfig === false || file_put_contents($temporaryConfig, $contents, LOCK_EX) === false) {
            throw new RuntimeException('CAT could not write config/installed.php. Make the config folder writable and reload to retry.');
        }
        @chmod($temporaryConfig, 0640);
        if (is_file($destination) || !@rename($temporaryConfig, $destination)) {
            throw new RuntimeException('CAT could not finalize config/installed.php. Check the config folder ownership and permissions, then retry.');
        }
        $temporaryConfig = null;
        $configWritten = true;
        $pdo->commit();
        header('Location: ' . ($basePath === '' ? '' : $basePath) . '/index.php?route=login', true, 302);
        exit;
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $rollbackException) {
                // Preserve the original installation error shown to the user.
            }
        }
        if (is_string($temporaryConfig) && is_file($temporaryConfig)) {
            @unlink($temporaryConfig);
        }
        if ($configWritten && is_file($destination)) {
            @unlink($destination);
        }
        $error = $exception->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install CAT</title>
<style>body{font:16px system-ui;margin:0;background:#f2f6fb;color:#142542}.card{max-width:600px;margin:5vh auto;padding:30px;background:#fff;border-radius:14px}label{display:block;margin-top:12px;font-weight:650}input,select{box-sizing:border-box;width:100%;padding:9px;margin-top:4px}button{margin-top:22px;padding:12px 18px;background:#1763b8;color:#fff;border:0;border-radius:6px;font-weight:700}.error{padding:12px;background:#fff0f0;color:#8e1d1d}.note{padding:12px;background:#eef5ff;color:#274e7d}small{color:#526784}</style>
</head><body><main class="card"><h1>Set up CanSkate Achievement Tracker</h1><p>Before continuing, create a new empty database and give its database user full access to it.</p><p class="note"><?php if ($isLocal): ?>Use the database host, port, name, and credentials configured for your local development server. Create the empty database before continuing.<?php else: ?>Find these database details in your hosting control panel. CAT does not create databases because shared-host database users usually do not have that permission.<?php endif; ?></p>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
<label>Database host<input name="host" value="<?= e($values['host']) ?>" required></label><label>Database port<input name="port" value="<?= e($values['port']) ?>" required></label><label>Database name<input name="database" value="<?= e($values['database']) ?>" required></label><label>Database user<input name="username" value="<?= e($values['username']) ?>" required></label><label>Database password<input name="database_password" type="password"><small>Required unless the database account was deliberately configured with no password.</small></label><label>Club name<input name="club_name" value="<?= e($values['club_name']) ?>" required></label><label>Time zone<select name="time_zone"><?php foreach(DateTimeZone::listIdentifiers() as $zone): ?><option <?= $zone === $values['time_zone'] ? 'selected' : '' ?>><?= e($zone) ?></option><?php endforeach; ?></select></label><label>Administrator first name<input name="first_name" value="<?= e($values['first_name']) ?>" required></label><label>Administrator last name<input name="last_name" value="<?= e($values['last_name']) ?>"></label><label>Administrator email<input name="email" type="email" value="<?= e($values['email']) ?>" required></label><label>Administrator password<input name="password" type="password" minlength="12" required></label><label>Confirm password<input name="password_confirmation" type="password" minlength="12" required></label><button>Install CAT</button></form><p><small>The installer locks itself after successful setup.</small></p>
</main></body></html>
