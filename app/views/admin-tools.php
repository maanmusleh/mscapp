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
    <title>Admin Settings · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('admin-tools.js')) ?>" defer></script>
</head>
<body class="app-page">
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= e(url('dashboard')) ?>">
            <span class="sidebar-brand-mark" aria-hidden="true"><img src="<?= e(asset('cat-logo.png')) ?>" alt=""></span>
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
            <a class="nav-item nav-item-icon-only active" href="<?= e(url('admin-tools')) ?>" aria-label="Admin Settings" title="Admin Settings"><span class="nav-icon nav-gear-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path><circle cx="12" cy="12" r="3.1"></circle></svg></span></a>
        </nav>
        <div class="sidebar-footer">
            <a class="avatar account-avatar nav-account-avatar" href="<?= e(url('account')) ?>" title="Manage account for <?= e($displayName) ?>"><?= e($userInitials) ?></a>
            <form method="post" action="<?= e(url('logout')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="sign-out" type="submit">Sign out</button></form>
        </div>
    </aside>
    <main class="app-main admin-tools-main">
        <header class="topbar">
            <div><h1>System Administration</h1><p>Manage users, the shared group-colour palette, and protect the club’s CAT data.</p></div>
        </header>
        <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div><?php endforeach; ?>

        <section class="user-card admin-tools-card" id="club-settings" aria-labelledby="club-settings-heading">
            <div class="card-heading"><span class="eyebrow">Club</span><h2 id="club-settings-heading">Club settings</h2><p>Set the club name and local time zone used when recording Coach App attendance.</p></div>
            <?php if ($clubSettingsFlash !== null): ?><div class="alert alert-<?= e($clubSettingsFlash['type'] ?? 'error') ?> user-management-alert" role="status"><?= e($clubSettingsFlash['message'] ?? '') ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('admin-tools/club-settings')) ?>" class="totp-policy-form"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Club name<input name="club_name" value="<?= e($clubSettings['club_name']) ?>" required maxlength="160"></label><label>Time zone<select name="time_zone" required><?php foreach ($clubTimeZones as $timeZone): ?><option value="<?= e($timeZone) ?>" <?= $clubSettings['time_zone'] === $timeZone ? 'selected' : '' ?>><?= e($timeZone) ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Save club settings</button></form>
        </section>

        <section class="user-card admin-tools-card totp-policy-card">
            <div class="card-heading"><span class="eyebrow">Security</span><h2>Two-factor authentication</h2><p>Choose whether authenticator-app codes are unavailable, optional, or required for all users.</p></div>
            <?php if ($totpPolicyFlash !== null): ?><div class="alert alert-<?= e($totpPolicyFlash['type'] ?? 'error') ?> user-management-alert"><?= e($totpPolicyFlash['message'] ?? '') ?></div><?php endif; ?>
            <form method="post" action="<?= e(url('admin-tools/totp-policy')) ?>" class="totp-policy-form"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>2FA policy<select name="totp_policy"><option value="UNAVAILABLE" <?= $totpPolicy === 'UNAVAILABLE' ? 'selected' : '' ?>>Unavailable</option><option value="OPTIONAL" <?= $totpPolicy === 'OPTIONAL' ? 'selected' : '' ?>>Optional</option><option value="REQUIRED" <?= $totpPolicy === 'REQUIRED' ? 'selected' : '' ?>>Required</option></select></label><button class="button button-primary" type="submit">Save policy</button></form>
        </section>

        <div class="admin-tools-layout">
            <section class="user-card admin-tools-card database-management-card" id="database-management" aria-labelledby="database-management-heading">
                <div class="card-heading"><span class="eyebrow">Data protection</span><h2 id="database-management-heading">Database management</h2><p>Download a complete database backup that can serve as a restore point or be used to relocate the database to another server. Backups contain personal data. Ensure they are stored securely.</p></div>
                <p class="database-summary">Database: <strong><?= e(number_format((int) $databaseSummary['users'])) ?></strong> user<?= (int) $databaseSummary['users'] === 1 ? '' : 's' ?> · <strong><?= e(number_format((int) $databaseSummary['skaters'])) ?></strong> skater<?= (int) $databaseSummary['skaters'] === 1 ? '' : 's' ?> · <strong><?= e(number_format((int) $databaseSummary['seasons'])) ?></strong> season<?= (int) $databaseSummary['seasons'] === 1 ? '' : 's' ?> · <strong><?= e(number_format((int) $databaseSummary['programs'])) ?></strong> program<?= (int) $databaseSummary['programs'] === 1 ? '' : 's' ?> · <strong><?= e(number_format((int) $databaseSummary['achievements'])) ?></strong> achievement<?= (int) $databaseSummary['achievements'] === 1 ? '' : 's' ?>.</p>
                <?php if ($databaseManagementFlash !== null): ?><div class="alert alert-<?= e($databaseManagementFlash['type'] ?? 'error') ?> database-management-alert" role="status"><?= e($databaseManagementFlash['message'] ?? '') ?></div><?php endif; ?>
                <div class="database-management-body">
                    <div class="database-backup-row">
                        <div><h3>Download a backup</h3><p>Save a complete, portable SQL file that can be restored under a different database name.</p></div>
                        <form method="post" action="<?= e(url('admin-tools/backup')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="button button-primary" type="submit">Download SQL backup</button></form>
                    </div>
                    <div class="database-backup-row">
                        <div><h3>Import historical CanSkate data</h3><p>Run the one-time, all-or-nothing Excel import for skaters, season registrations, and Stage 1–5 achievement dates.</p></div>
                        <a class="button button-secondary" href="<?= e(url('legacy-achievement-import')) ?>">Open historical import</a>
                    </div>
                    <div class="database-restore-panel" aria-labelledby="restore-heading">
                        <div class="database-restore-heading"><div><span class="eyebrow">Destructive action</span><h3 id="restore-heading">Restore a backup</h3></div><p>Restoring replaces the contents of the configured CAT database, regardless of the database name in the backup. CAT creates a temporary safety backup before replacement.</p></div>
                        <form class="database-restore-form" method="post" action="<?= e(url('admin-tools/restore')) ?>" enctype="multipart/form-data">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <label>SQL backup file<input name="backup_file" type="file" accept=".sql,application/sql,text/sql" required></label>
                            <label><span>Type <strong>RESTORE</strong> to confirm</span><input name="confirmation" autocomplete="off" required></label>
                            <button class="button button-danger" type="submit" data-database-restore-submit>Restore database</button>
                        </form>
                        <div class="database-restore-progress" data-database-restore-progress role="status" aria-live="assertive" hidden>
                            <span class="database-restore-spinner" aria-hidden="true"></span>
                            <span><strong>Restoring database…</strong><small>Uploading the backup and replacing the database. This may take several minutes. Do not close or refresh this page.</small></span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="user-card admin-tools-card admin-colours-card" id="group-colours" aria-labelledby="group-colours-heading">
                <div class="card-heading"><span class="eyebrow">Shared assignment palette</span><h2 id="group-colours-heading">Group colours</h2><p>These colours are available to all authorized users when assigning skaters to a session group.</p></div>
                <?php if ($groupColoursFlash !== null): ?>
                    <div class="alert alert-<?= e($groupColoursFlash['type'] ?? 'error') ?> admin-colours-alert" role="status"><?= e($groupColoursFlash['message'] ?? '') ?></div>
                <?php endif; ?>
                <div class="admin-colour-list">
                    <?php foreach ($groupColours as $colour): ?>
                        <form class="admin-colour-row" method="post" action="<?= e(url('admin-tools/group-colours')) ?>">
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="original_name" value="<?= e($colour['name']) ?>">
                            <label><span class="sr-only">Group colour name</span><input name="name" value="<?= e($colour['name']) ?>" required maxlength="100"></label>
                            <label><span class="sr-only">Group colour</span><input name="hex" type="color" value="<?= e($colour['hex']) ?>" required></label>
                            <button class="button button-secondary" type="submit" name="intent" value="update">Save</button>
                            <button class="button button-ghost admin-colour-remove" type="submit" name="intent" value="remove">Remove</button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <form class="admin-colour-add" method="post" action="<?= e(url('admin-tools/group-colours')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>Colour name<input name="name" required maxlength="100" placeholder="e.g. Black"></label>
                    <label>Colour<input name="hex" type="color" value="#000000" required></label>
                    <button class="button button-primary" type="submit" name="intent" value="add">Add colour</button>
                </form>
            </section>

            <section class="user-card admin-tools-card stage-settings-card" id="stage-settings" aria-labelledby="stage-settings-heading">
                <div class="card-heading"><h2 id="stage-settings-heading">Enabled stages</h2><p>Stage availability applies system-wide. Disabled stages are unavailable in the Coach App.</p></div>
                <?php if ($stageSettingsFlash !== null): ?><div class="alert alert-<?= e($stageSettingsFlash['type'] ?? 'error') ?> user-management-alert" role="status"><?= e($stageSettingsFlash['message'] ?? '') ?></div><?php endif; ?>
                <form method="post" action="<?= e(url('admin-tools/stages')) ?>" class="stage-settings-form"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><div class="stage-setting-grid"><?php foreach ($stages as $stage): ?><label class="setting-checkbox"><input type="checkbox" name="enabled_stage_ids[]" value="<?= e($stage['id']) ?>" <?= $stage['active'] ? 'checked' : '' ?>><span><?= e($stage['stage_number'] === 0 ? 'Pre-CanSkate' : 'Stage ' . $stage['stage_number']) ?></span></label><?php endforeach; ?></div><button class="button button-primary" type="submit">Save stages</button></form>
            </section>

        </div>

        <section class="user-card admin-users-card" id="users" aria-labelledby="users-heading">
            <div class="card-heading admin-users-heading"><div><h2 id="users-heading">User management</h2><p>Add accounts, assign access, and issue temporary password resets.</p></div><span class="count-pill"><?= count($users) ?> <?= count($users) === 1 ? 'user' : 'users' ?></span></div>
            <?php if ($userManagementFlash !== null): ?><div class="alert alert-<?= e($userManagementFlash['type'] ?? 'error') ?> user-management-alert"><?= e($userManagementFlash['message'] ?? '') ?></div><?php endif; ?>
            <div class="admin-users-content">
                <div class="admin-users-create" aria-labelledby="add-user-heading">
                    <h3 id="add-user-heading">Add a user</h3><p>They will use their email address to sign in and must change this temporary password at first login.</p>
                <form class="user-form" method="post" action="<?= e(url('users')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <div class="form-grid"><label>First name<input name="first_name" autocomplete="given-name" required maxlength="100"></label><label>Last name<input name="last_name" autocomplete="family-name" required maxlength="100"></label></div>
                    <label>Email address <small>Used as the user ID</small><input name="email" type="email" autocomplete="email" required maxlength="254"></label>
                    <div class="form-grid"><label>Initial password<input name="initial_password" type="password" autocomplete="new-password" required minlength="12"></label><label>Confirm password<input name="initial_password_confirmation" type="password" autocomplete="new-password" required minlength="12"></label></div>
                    <label>User type<select name="role_code" required><option value="ADMINISTRATOR">Administrator — full access, including Admin Settings</option><option value="REGISTRAR">Editor — all operational features; no Admin Settings</option><option value="COACH">Coach — Coach App access</option></select></label>
                    <button class="button button-primary" type="submit">Add user</button>
                </form>
                </div>
                <div class="admin-users-list" aria-labelledby="club-users-heading">
                <h3 id="club-users-heading">Users</h3>
                <div class="user-list">
                    <?php foreach ($users as $managedUser): ?>
                        <?php $managedName = trim((string) ($managedUser['first_name'] ?? '') . ' ' . (string) ($managedUser['last_name'] ?? '')) ?: $managedUser['username']; ?>
                        <?php $managedInitials = strtoupper(trim(mb_substr(trim((string) ($managedUser['first_name'] ?? '')), 0, 1) . mb_substr(trim((string) ($managedUser['last_name'] ?? '')), 0, 1)) ?: mb_substr($managedName, 0, 2)); ?>
                        <?php $passwordLocked = !empty($managedUser['password_lock_until']) && strtotime((string) $managedUser['password_lock_until'] . ' UTC') > time(); ?>
                        <article class="user-row <?= (bool) $managedUser['active'] ? '' : 'is-suspended' ?>">
                            <div class="user-row-identity"><span class="user-initials"><?= e($managedInitials) ?></span><div><h3><?= e($managedName) ?></h3><p><?= e($managedUser['email'] ?: $managedUser['username']) ?></p></div></div>
                            <div class="user-row-meta"><span class="role-badge role-<?= e(strtolower($managedUser['role_code'])) ?>"><?= e($managedUser['role_name']) ?></span><?php if (!(bool) $managedUser['active']): ?><span class="suspended-badge">Paused</span><?php endif; ?><?php if ((bool) $managedUser['must_change_password']): ?><span class="temporary-badge">Password change required</span><?php endif; ?><?php if ($passwordLocked): ?><span class="temporary-badge">Password locked</span><?php endif; ?><?php if ($managedUser['totp_enabled_at']): ?><span class="temporary-badge">2FA enabled</span><?php endif; ?><small><?= $managedUser['last_login_at'] ? 'Last sign-in ' . e(format_date($managedUser['last_login_at'])) : 'Not signed in yet' ?></small></div>
                            <details class="user-row-actions"><summary>Actions</summary><div class="user-row-actions-menu">
                                <?php if (strcasecmp((string) $managedUser['public_id'], (string) $user['public_id']) !== 0): ?><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/' . ((bool) $managedUser['active'] ? 'suspend' : 'resume'))) ?>" class="user-access-toggle"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="button button-ghost" type="submit"><?= (bool) $managedUser['active'] ? 'Pause user' : 'Resume user' ?></button></form><?php endif; ?>
                                <?php if ($passwordLocked): ?><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/clear-password-lock')) ?>" class="user-totp-reset"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="button button-ghost" type="submit">Clear password lock</button></form><?php endif; ?>
                                <details class="reset-password"><summary>Reset password</summary><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/reset-password')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label>Temporary password<input name="temporary_password" type="password" required minlength="12" autocomplete="new-password"></label><label>Confirm temporary password<input name="temporary_password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label><button class="button button-secondary" type="submit">Set temporary password</button></form></details>
                                <?php if ($managedUser['totp_enabled_at']): ?><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/reset-2fa')) ?>" class="user-totp-reset"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="button button-ghost" type="submit">Reset 2FA</button></form><?php endif; ?>
                                <?php if (strcasecmp((string) $managedUser['public_id'], (string) $user['public_id']) !== 0): ?><details class="reset-password user-delete"><summary>Delete user</summary><form method="post" action="<?= e(url('users/' . $managedUser['public_id'] . '/delete')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><p>Type <strong>delete</strong> to permanently remove this account.</p><label>Confirmation<input name="confirmation" autocomplete="off" required></label><button class="button button-danger" type="submit">Delete user</button></form></details><?php endif; ?>
                            </div></details>
                        </article>
                    <?php endforeach; ?>
                </div>
                </div>
            </div>
        </section>

        <?php
        $activityLabels = [
            'login_success' => 'Signed in',
            'password_failed' => 'Password rejected',
            'login_blocked' => 'Sign-in blocked after too many attempts',
            'login_throttled' => 'Sign-in throttled for this network',
            'password_blocked' => 'Password sign-in blocked by account lock',
            'password_locked_10_minutes' => 'Password sign-in locked for 10 minutes',
            'password_locked_24_hours' => 'Password sign-in locked for 24 hours',
            'password_lock_cleared_by_administrator' => 'Password login lock cleared by administrator',
            'totp_failed' => 'Authenticator code rejected',
            'totp_blocked' => 'Authenticator code blocked after too many attempts',
            'totp_enabled' => 'Two-factor authentication enabled',
            'totp_disabled' => 'Two-factor authentication disabled',
            'totp_reset_by_administrator' => 'Two-factor authentication reset by administrator',
            'account_created' => 'Account created',
            'account_paused' => 'Account paused',
            'account_resumed' => 'Account resumed',
            'account_deleted' => 'Account deleted',
            'password_changed' => 'Password changed',
            'password_reset_by_administrator' => 'Password reset by administrator',
            'report_card_signature_uploaded' => 'Report-card signature uploaded',
            'report_card_signature_deleted' => 'Report-card signature removed',
        ];
        ?>
        <section class="user-card login-activity-card" id="login-activity" aria-labelledby="login-activity-heading">
            <div class="card-heading"><h2 id="login-activity-heading">Login and Account Activity</h2><p>Most recent sign-in, security, and account-management activity. Activity older than 90 days is not retained.</p></div>
            <div class="login-activity-table-wrap">
                <table class="login-activity-table">
                    <thead><tr><th>When</th><th>Activity</th><th>User / attempted ID</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php foreach ($loginActivity as $activity): ?>
                        <?php
                        $userName = trim((string) ($activity['user_first_name'] ?? '') . ' ' . (string) ($activity['user_last_name'] ?? ''));
                        $userName = $userName !== '' ? $userName : ($activity['user_username'] ?? 'Unknown user');
                        $actorName = trim((string) ($activity['actor_first_name'] ?? '') . ' ' . (string) ($activity['actor_last_name'] ?? ''));
                        $actorName = $actorName !== '' ? $actorName : ($activity['actor_username'] ?? '');
                        $description = $activityLabels[$activity['event_type']] ?? $activity['event_type'];
                        if ($actorName !== '') $description .= ' (' . $actorName . ')';
                        ?>
                        <tr><td><?= e(format_datetime($activity['created_at'])) ?></td><td><?= e($description) ?></td><td><?= e($activity['attempted_identity'] ?: $userName) ?></td><td><?= e($activity['source_ip'] ?: '—') ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($loginActivity === []): ?><tr><td colspan="4" class="login-activity-empty">No login activity has been recorded yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav class="login-activity-pagination" aria-label="Login and account activity pages">
                <?php if (($loginActivityPage['page'] ?? 1) > 1): ?><a class="button button-secondary" href="<?= e(url('admin-tools?activity_page=' . ((int) $loginActivityPage['page'] - 1) . '#login-activity')) ?>">Previous</a><?php else: ?><span class="button button-secondary is-disabled" aria-disabled="true">Previous</span><?php endif; ?>
                <span>Page <?= e((string) $loginActivityPage['page']) ?> of <?= e((string) $loginActivityPage['pages']) ?></span>
                <?php if (($loginActivityPage['page'] ?? 1) < ($loginActivityPage['pages'] ?? 1)): ?><a class="button button-secondary" href="<?= e(url('admin-tools?activity_page=' . ((int) $loginActivityPage['page'] + 1) . '#login-activity')) ?>">Next</a><?php else: ?><span class="button button-secondary is-disabled" aria-disabled="true">Next</span><?php endif; ?>
            </nav>
        </section>

    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
