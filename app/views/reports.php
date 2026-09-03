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
    <title>Reports · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('reports.js')) ?>" defer></script>
</head>
<body class="app-page">
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= e(url($user['role_code'] === 'COACH' ? 'reports' : 'dashboard')) ?>">
            <span class="sidebar-brand-mark" aria-hidden="true"><img src="<?= e(asset('cat-logo.png')) ?>" alt=""></span>
            <span class="brand-lockup" aria-label="CanSkate Achievement Tracker">
                <span class="brand-word"><b class="brand-initial">C</b>anSkate</span>
                <span class="brand-word"><b class="brand-initial">A</b>chievement</span>
                <span class="brand-word"><b class="brand-initial">T</b>racker</span>
            </span>
        </a>
        <nav class="primary-nav" aria-label="Main navigation">
            <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY'], true)): ?><a class="nav-item" href="<?= e(url('dashboard')) ?>">Skaters</a><?php endif; ?>
            <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><a class="nav-item" href="<?= e(url('sessions')) ?>">Registration</a><?php endif; ?>
            <a class="nav-item active" href="<?= e(url('reports')) ?>">Reports</a>
            <a class="nav-item nav-item-rink" href="<?= e(url('rink-app')) ?>">Coach App</a>
            <?php if ($user['role_code'] === 'ADMINISTRATOR'): ?>
                <a class="nav-item nav-item-icon-only" href="<?= e(url('admin-tools')) ?>" aria-label="Admin Settings" title="Admin Settings"><span class="nav-icon nav-gear-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path><circle cx="12" cy="12" r="3.1"></circle></svg></span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a class="avatar account-avatar nav-account-avatar" href="<?= e(url('account')) ?>" title="Manage account for <?= e($displayName) ?>"><?= e($userInitials) ?></a>
            <form method="post" action="<?= e(url('logout')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="sign-out" type="submit">Sign out</button></form>
        </div>
    </aside>
    <main class="app-main reports-main">
        <header class="topbar"><div><h1>Reports</h1><p>Generate spreadsheet reports.</p></div></header>
        <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div><?php endforeach; ?>
        <?php if ($canExportSkaterAchievements): ?>
            <section class="user-card report-export-card" id="export-skater-achievements" aria-labelledby="achievement-export-heading">
                <div class="card-heading">
                    <h2 id="achievement-export-heading">Export Skater Achievements</h2>
                </div>
                <?php if ($achievementExportOptions['seasons'] === []): ?>
                    <p class="report-export-empty">Create a season before exporting skater achievements.</p>
                <?php else: ?>
                    <form class="report-export-form" method="post" action="<?= e(url('reports/skater-achievements/export')) ?>" data-achievement-export-form>
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="columns_selected" value="1">
                        <div class="report-export-grid">
                            <label><span class="report-export-label">Season</span>
                                <select name="season_id" required data-export-season>
                                    <?php foreach ($achievementExportOptions['seasons'] as $index => $season): ?>
                                        <option value="<?= e((string) $season['id']) ?>" <?= $index === 0 ? 'selected' : '' ?>><?= e($season['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span class="report-export-label">Session <span class="optional-label">(Optional)</span></span>
                                <select name="session_id" data-export-session>
                                    <option value="">All sessions</option>
                                    <?php foreach ($achievementExportOptions['sessions'] as $session): ?>
                                        <option value="<?= e((string) $session['id']) ?>" data-season-id="<?= e((string) $session['season_id']) ?>"><?= e($session['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span class="report-export-label">Group <span class="optional-label">(Optional)</span></span>
                                <select name="group_id" data-export-group>
                                    <option value="">All groups</option>
                                    <?php foreach ($achievementExportOptions['groups'] as $group): ?>
                                        <option value="<?= e((string) $group['id']) ?>" data-season-id="<?= e((string) $group['season_id']) ?>" data-session-id="<?= e((string) $group['program_session_id']) ?>"><?= e($group['name'] . ' · ' . $group['session_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span class="report-export-label">Age <span class="optional-label">(Optional)</span></span>
                                <select name="age">
                                    <option value="">All ages</option>
                                    <?php foreach ($achievementExportOptions['ages'] as $age): ?>
                                        <option value="<?= e((string) $age) ?>"><?= e((string) $age) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span class="report-export-label">Highest badge <span class="optional-label">(Optional)</span></span>
                                <select name="highest_badge">
                                    <option value="">All badges</option>
                                    <option value="0">No badge</option>
                                    <?php foreach ($achievementExportOptions['badges'] as $badge): ?>
                                        <option value="<?= e((string) $badge['stage_number']) ?>">Stage <?= e((string) $badge['stage_number']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><span class="report-export-label">File format</span>
                                <select name="format" required>
                                    <option value="xlsx">Excel (.xlsx)</option>
                                    <option value="csv">Comma-Separated Values (.csv)</option>
                                </select>
                            </label>
                        </div>
                        <fieldset class="report-export-columns">
                            <legend>Columns to include</legend>
                            <div class="report-export-column-options">
                                <label><input type="checkbox" name="columns[]" value="name" checked> <span>Name</span></label>
                                <label><input type="checkbox" name="columns[]" value="canskate_number" checked> <span>CanSkate Number</span></label>
                                <label><input type="checkbox" name="columns[]" value="date_of_birth" checked> <span>Date of birth</span></label>
                                <label><input type="checkbox" name="columns[]" value="gender" checked> <span>Gender</span></label>
                                <label><input type="checkbox" name="columns[]" value="guardian_info" checked> <span>Guardian info</span></label>
                                <label><input type="checkbox" name="columns[]" value="general_notes" checked> <span>General notes</span></label>
                                <label><input type="checkbox" name="columns[]" value="medical_notes" checked> <span>Medical/accommodation notes</span></label>
                                <label><input type="checkbox" name="columns[]" value="skills" checked> <span>Skills</span></label>
                                <label><input type="checkbox" name="columns[]" value="ribbons" checked> <span>Ribbons</span></label>
                                <label><input type="checkbox" name="columns[]" value="badges" checked> <span>Badges</span></label>
                            </div>
                        </fieldset>
                        <button class="button button-primary" type="submit">Export achievements</button>
                    </form>
                <?php endif; ?>
            </section>
            <section class="user-card report-export-card session-report-card" id="session-report" aria-labelledby="session-report-heading">
                <div class="card-heading"><h2 id="session-report-heading">Session Report</h2></div>
                <form class="report-export-form" method="post" action="<?= e(url('reports/sessions/export')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <div class="report-export-grid session-report-grid">
                        <label><span class="report-export-label">Season <span class="optional-label">(Optional)</span></span><select name="season_filter"><option value="all">All seasons</option><option value="unassigned">Unassigned</option><?php foreach ($achievementExportOptions['seasons'] as $season): ?><option value="<?= e((string) $season['id']) ?>"><?= e($season['name']) ?></option><?php endforeach; ?></select></label>
                        <label><span class="report-export-label">File format</span><select name="format" required><option value="xlsx">Excel (.xlsx)</option><option value="csv">Comma-Separated Values (.csv)</option></select></label>
                    </div>
                    <button class="button button-primary" type="submit">Export sessions</button>
                </form>
            </section>
        <?php else: ?>
            <section class="user-card reports-placeholder" aria-labelledby="reports-access-heading">
                <span class="eyebrow">Reporting workspace</span>
                <h2 id="reports-access-heading">No reports available</h2>
                <p>Achievement exports are available to Editors and Administrators.</p>
            </section>
        <?php endif; ?>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
