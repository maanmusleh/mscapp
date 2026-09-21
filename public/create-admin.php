<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$pdo = Database::connection();
$hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM app_user')->fetchColumn() > 0;
$setupToken = (string) config('security.admin_setup_token', '');
$error = null;
$created = false;
$values = ['email' => '', 'first_name' => '', 'last_name' => ''];

if ($hasUsers) {
    http_response_code(403);
    $error = 'Setup is unavailable because this CAT installation already has a user account.';
} elseif ($setupToken === '') {
    http_response_code(503);
    $error = 'Setup is disabled. Configure security.admin_setup_token in config/app.php first.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $values = [
        'email' => trim((string) ($_POST['email'] ?? '')),
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $providedToken = (string) ($_POST['setup_token'] ?? '');

    try {
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            throw new InvalidArgumentException('The form expired. Refresh the page and try again.');
        }
        if (!hash_equals($setupToken, $providedToken)) {
            throw new InvalidArgumentException('The setup token is not correct.');
        }
        if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if ($values['first_name'] === '' || mb_strlen($values['first_name']) > 100) {
            throw new InvalidArgumentException('Enter a first name of up to 100 characters.');
        }
        if (mb_strlen($values['last_name']) > 100) {
            throw new InvalidArgumentException('Enter a last name of up to 100 characters.');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('The password must contain at least 12 characters.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('The passwords do not match.');
        }

        $roleId = $pdo->query("SELECT id FROM user_role WHERE code = 'ADMINISTRATOR' AND active = 1 LIMIT 1")->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('The ADMINISTRATOR role is not installed. Import the fresh-install SQL first.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO app_user (user_role_id, first_name, last_name, username, password_hash, email, active)
             VALUES (:role_id, :first_name, :last_name, :username, :password_hash, :email, 1)'
        );
        $insert->execute([
            'role_id' => $roleId,
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'] === '' ? null : $values['last_name'],
            'username' => $values['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $values['email'],
        ]);
        LoginActivityService::record('account_created', (int) $pdo->lastInsertId(), 'Initial administrator');
        $created = true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Create CAT administrator</title>
<style>body{margin:0;background:#f2f6fb;color:#142542;font:16px system-ui,sans-serif}.card{max-width:470px;margin:8vh auto;padding:32px;background:#fff;border-radius:16px;box-shadow:0 14px 40px #17345b22}h1{margin-top:0}label{display:block;font-weight:650;margin-top:16px}input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #aebdd3;border-radius:7px;font:inherit}.error{padding:12px;border-radius:8px;background:#fff0f0;color:#8e1d1d}.success{padding:12px;border-radius:8px;background:#ebf8ef;color:#176538}button{margin-top:24px;width:100%;border:0;border-radius:7px;padding:12px;background:#1763b8;color:#fff;font:inherit;font-weight:700}.note{color:#526784;font-size:.9rem}</style>
</head><body><main class="card"><h1>Create first administrator</h1>
<?php if ($created): ?><p class="success">Administrator created. You can now <a href="<?= e(url('login')) ?>">sign in</a>. Delete <code>public/create-admin.php</code> from the server.</p>
<?php elseif ($error !== null): ?><p class="error"><?= e($error) ?></p>
<?php endif; ?>
<?php if (!$hasUsers && !$created && $setupToken !== ''): ?><form method="post" autocomplete="off"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
<label>Setup token<input name="setup_token" type="password" required></label><label>Email address<input name="email" type="email" value="<?= e($values['email']) ?>" required></label><label>First name<input name="first_name" value="<?= e($values['first_name']) ?>" required maxlength="100"></label><label>Last name<input name="last_name" value="<?= e($values['last_name']) ?>" maxlength="100"></label><label>Password<input name="password" type="password" required minlength="12"></label><label>Confirm password<input name="password_confirmation" type="password" required minlength="12"></label><button type="submit">Create administrator</button></form><p class="note">This page works only before the first user is created.</p><?php endif; ?>
</main></body></html>
