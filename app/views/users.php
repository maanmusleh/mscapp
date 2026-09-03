<?php
$displayName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')) ?: $user['username'];
$userInitials = trim(mb_substr(trim((string) ($user['first_name'] ?? '')), 0, 1)
    . mb_substr(trim((string) ($user['last_name'] ?? '')), 0, 1));
$userInitials = strtoupper($userInitials !== '' ? $userInitials : mb_substr($displayName, 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Users · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
</head>
<body class="app-page">
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= e(url('dashboard')) ?>">
            <span class="sidebar-brand-mark" aria-hidden="true">
                <img src="<?= e(asset('cat-logo.png')) ?>" alt="">
            </span>
            <span class="brand-lockup" aria-label="CanSkate Achievement Tracker">
                <span class="brand-word"><b class="brand-initial">C</b>anSkate</span>
                <span class="brand-word"><b class="brand-initial">A</b>chievement</span>
                <span class="brand-word"><b class="brand-initial">T</b>racker</span>
            </span>
        </a>
        <nav class="primary-nav" aria-label="Main navigation">
            <a class="nav-item" href="<?= e(url('dashboard')) ?>">Skaters</a>
            <a class="nav-item" href="<?= e(url('sessions')) ?>">Registration</a>
            <a class="nav-item" href="<?= e(url('reports')) ?>">Reports</a>
            <a class="nav-item nav-item-rink" href="<?= e(url('rink-app')) ?>">Coach App</a>
            <a class="nav-item active" href="<?= e(url('users')) ?>"><span class="nav-icon">♙</span> Users</a>
            <a class="nav-item nav-item-icon-only" href="<?= e(url('admin-tools')) ?>" aria-label="Admin Settings" title="Admin Settings"><span class="nav-icon nav-gear-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path><circle cx="12" cy="12" r="3.1"></circle></svg></span></a>
        </nav>
        <div class="sidebar-footer">
            <a class="avatar account-avatar nav-account-avatar" href="<?= e(url('account')) ?>" title="Manage account for <?= e($displayName) ?>"><?= e($userInitials) ?></a>
            <form method="post" action="<?= e(url('logout')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="sign-out" type="submit">Sign out</button></form>
        </div>
    </aside>
    <main class="app-main">
        <header class="topbar">
            <div><span class="eyebrow">Club administration</span><h1>User management</h1><p>Add accounts, assign access, and issue temporary password resets.</p></div>
        </header>
        <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div><?php endforeach; ?>
        <div class="user-management-layout">
            <section class="user-card user-create-card" aria-labelledby="add-user-heading">
                <div class="card-heading"><span class="eyebrow">New account</span><h2 id="add-user-heading">Add a user</h2><p>They will use their email address to sign in and must change this temporary password at first login.</p></div>
                <form class="user-form" method="post" action="<?= e(url('users')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <div class="form-grid"><label>First name<input name="first_name" autocomplete="given-name" required maxlength="100"></label><label>Last name<input name="last_name" autocomplete="family-name" required maxlength="100"></label></div>
                    <label>Email address <small>Used as the user ID</small><input name="email" type="email" autocomplete="email" required maxlength="254"></label>
                    <div class="form-grid"><label>Initial password<input name="initial_password" type="password" autocomplete="new-password" required minlength="12"></label><label>Confirm password<input name="initial_password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label></div>
                    <label>User type<select name="role_code" required><option value="ADMINISTRATOR">Administrator — full access, including Admin Settings</option><option value="REGISTRAR">Editor — all operational features; no Admin Settings</option><option value="COACH">Coach — Coach App access</option></select></label>
                    <button class="button button-primary" type="submit">Add user</button>
                </form>
            </section>
            <section class="user-card" aria-labelledby="users-heading">
                <div class="card-heading user-list-heading"><div><span class="eyebrow">Club accounts</span><h2 id="users-heading">Users</h2></div><span class="count-pill"><?= count($users) ?> <?= count($users) === 1 ? 'user' : 'users' ?></span></div>
                <div class="user-list">
                    <?php foreach ($users as $managedUser): ?>
                        <?php $managedName = trim((string) ($managedUser['first_name'] ?? '') . ' ' . (string) ($managedUser['last_name'] ?? '')) ?: $managedUser['username']; ?>
                        <article class="user-row">
                            <div class="user-row-identity"><span class="user-initials"><?= e(strtoupper(substr($managedName, 0, 2))) ?></span><div><h3><?= e($managedName) ?></h3><p><?= e($managedUser['email'] ?: $managedUser['username']) ?></p></div></div>
                            <div class="user-row-meta"><span class="role-badge role-<?= e(strtolower($managedUser['role_code'])) ?>"><?= e($managedUser['role_name']) ?></span><?php if ((bool) $managedUser['must_change_password']): ?><span class="temporary-badge">Password change required</span><?php endif; ?><small><?= $managedUser['last_login_at'] ? 'Last sign-in ' . e(format_date($managedUser['last_login_at'])) : 'Not signed in yet' ?></small></div>
                            <details class="reset-password"><summary>Reset password</summary><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/reset-password')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Temporary password<input name="temporary_password" type="password" required minlength="12" autocomplete="new-password"></label><label>Confirm temporary password<input name="temporary_password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label><button class="button button-secondary" type="submit">Set temporary password</button></form></details>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
