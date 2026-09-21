<?php
$displayName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')) ?: $user['username'];
$userInitials = trim(mb_substr(trim((string) ($user['first_name'] ?? '')), 0, 1)
    . mb_substr(trim((string) ($user['last_name'] ?? '')), 0, 1));
$userInitials = strtoupper($userInitials !== '' ? $userInitials : mb_substr($displayName, 0, 2));
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$sessionRinkNames = array_merge(['No rink'], $scheduleData['rinks'] ?? []);
$sessionRinkNameLength = max(array_map(static fn (string $rink): int => mb_strlen($rink), $sessionRinkNames));
$sessionRinkSelectWidth = max(100, 40 + ($sessionRinkNameLength * 8));
$selectedSeason = null;
foreach ($scheduleData['seasons'] as $season) {
    if ((int) $season['id'] === (int) ($selectedSeasonId ?? 0)) {
        $selectedSeason = $season;
        break;
    }
}
$sessionGroups = [];
foreach ($scheduleData['groups'] ?? [] as $group) {
    $sessionGroups[(int) $group['program_session_id']][] = $group;
}
$populatedSessionGroups = [];
$sessionCoachesComplete = [];
foreach ($sessionGroups as $sessionId => $groups) {
    $populatedSessionGroups[$sessionId] = array_values(array_filter(
        $groups,
        static fn (array $group): bool => (int) ($group['skater_count'] ?? 0) > 0
    ));
    $sessionCoachesComplete[$sessionId] = $populatedSessionGroups[$sessionId] !== []
        && count(array_filter(
            $populatedSessionGroups[$sessionId],
            static fn (array $group): bool => (int) ($group['report_card_coach_user_id'] ?? 0) > 0
        )) === count($populatedSessionGroups[$sessionId]);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Registrations · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('sessions.js')) ?>" defer></script>
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
            <a class="nav-item active" href="<?= e(url('sessions')) ?>">Registration</a>
            <a class="nav-item" href="<?= e(url('reports')) ?>">Reports</a>
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
    <main class="app-main sessions-main">
        <header class="topbar">
            <div><h1>Registration</h1><p>Manage seasons, sessions, rinks, and skater registration imports.</p></div>
        </header>
        <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div><?php endforeach; ?>

        <div class="session-tools-grid">
        <section class="schedule-admin schedule-admin-collapsible" aria-labelledby="seasons-heading">
            <details data-preserve-details="seasons">
                <summary><span class="audit-toggle" aria-hidden="true"></span><strong id="seasons-heading">Seasons</strong></summary>
                <div class="schedule-admin-grid">
                <section class="schedule-admin-card">
                    <?php foreach ($scheduleData['seasons'] as $season): ?>
                        <form method="post" action="<?= e(url('sessions/seasons')) ?>" class="schedule-row season-row" data-preserve-scroll>
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= e($season['id']) ?>">
                            <input type="hidden" name="updated_at" value="<?= e($season['updated_at']) ?>">
                            <input name="name" value="<?= e($season['name']) ?>" required aria-label="Season name">
                            <input name="start_date" type="date" value="<?= e($season['start_date']) ?>" required aria-label="Start date">
                            <input name="end_date" type="date" value="<?= e($season['end_date']) ?>" required aria-label="End date">
                            <label class="inline-check"><input name="active" type="checkbox" <?= $season['active'] ? 'checked' : '' ?>>Active</label>
                            <button class="button button-secondary" name="intent" value="save">Save</button>
                            <button class="button button-ghost" name="intent" value="remove">Remove</button>
                        </form>
                    <?php endforeach; ?>
                    <form method="post" action="<?= e(url('sessions/seasons')) ?>" class="schedule-add season-row" data-preserve-scroll>
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input name="name" placeholder="New season name" required aria-label="Season name">
                        <input name="start_date" type="date" required aria-label="Start date">
                        <input name="end_date" type="date" required aria-label="End date">
                        <label class="inline-check"><input name="active" type="checkbox" checked>Active</label>
                        <button class="button button-primary" name="intent" value="save">Add season</button>
                    </form>
                </section>
                </div>
            </details>
        </section>

        <section class="schedule-admin schedule-admin-collapsible session-rinks-card" aria-labelledby="rinks-heading">
            <details data-preserve-details="rinks">
                <summary><span class="audit-toggle" aria-hidden="true"></span><strong id="rinks-heading">Rinks</strong></summary>
                <div class="schedule-admin-grid">
                    <section class="schedule-admin-card rink-manager-card">
                        <div class="rink-manager-list">
                        <?php foreach ($scheduleData['rinks'] as $rink): ?>
                            <form method="post" action="<?= e(url('sessions/rinks')) ?>" class="rink-row" data-preserve-scroll>
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="return_season_id" value="<?= e($selectedSeasonId ?? '') ?>">
                                <input type="hidden" name="original_name" value="<?= e($rink) ?>">
                                <input name="name" value="<?= e($rink) ?>" required>
                                <button class="button button-secondary" name="intent" value="save">Save</button>
                                <button class="button button-ghost" name="intent" value="remove">Remove</button>
                            </form>
                        <?php endforeach; ?>
                        </div>
                        <form method="post" action="<?= e(url('sessions/rinks')) ?>" class="rink-row rink-row-add" data-preserve-scroll>
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="return_season_id" value="<?= e($selectedSeasonId ?? '') ?>">
                            <input name="name" placeholder="New rink" required>
                            <button class="button button-primary" name="intent" value="save">Add rink</button>
                        </form>
                    </section>
                </div>
            </details>
        </section>
        </div>

        <section class="schedule-admin schedule-admin-collapsible" aria-labelledby="sessions-heading">
            <details data-preserve-details="sessions">
                <summary><span class="audit-toggle" aria-hidden="true"></span><strong id="sessions-heading">Sessions</strong></summary>
                <div class="card-heading sessions-heading"><p>Choose a season to view and manage its sessions.</p><form method="get" action="<?= e(url()) ?>" class="season-session-filter"><input type="hidden" name="route" value="sessions"><label>Season<select name="season_id" data-season-session-select><option value="">Select a season</option><?php foreach ($scheduleData['seasons'] as $season): ?><option value="<?= e($season['id']) ?>" <?= (int) $season['id'] === (int) ($selectedSeasonId ?? 0) ? 'selected' : '' ?>><?= e($season['name']) ?></option><?php endforeach; ?></select></label></form><p class="session-sku-note">NOTE: Sessions with unmatched SKUs will be automatically created when skaters are imported. The initial session name will be the SKU and can be changed here.</p></div>
            <?php if ($selectedSeason !== null): ?>
            <div class="schedule-admin-grid">
                <section class="schedule-admin-card" style="--session-rink-width: <?= e((string) $sessionRinkSelectWidth) ?>px">
                    <div class="session-column-headings" aria-hidden="true">
                        <span>SKU</span>
                        <span>Name</span>
                        <span>Day</span>
                        <span>Start time</span>
                        <span>End time</span>
                        <span>Rink</span>
                        <span>Coaches</span>
                        <span></span>
                        <span></span>
                    </div>
                    <?php foreach ($scheduleData['sessions'] as $session): ?>
                        <form method="post" action="<?= e(url('sessions/sessions')) ?>" class="schedule-row session-row" data-preserve-scroll>
                            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= e($session['id']) ?>">
                            <input type="hidden" name="season_id" value="<?= e($selectedSeason['id']) ?>">
                            <input type="hidden" name="updated_at" value="<?= e($session['updated_at']) ?>">
                            <input name="sku" value="<?= e($session['sku']) ?>" required maxlength="64" aria-label="SKU" placeholder="SKU">
                            <input name="name" value="<?= e($session['name']) ?>" aria-label="Session name" placeholder="Mon 1700-1750">
                            <select name="day_of_week" aria-label="Day of week"><?php foreach ($days as $day => $label): ?><option value="<?= $day ?>" <?= $day === (int) $session['day_of_week'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                            <input name="start_time" type="time" value="<?= e(substr($session['start_time'], 0, 5)) ?>" required aria-label="Start time">
                            <input name="end_time" type="time" value="<?= e(substr($session['end_time'], 0, 5)) ?>" required aria-label="End time">
                            <select name="location" aria-label="Rink"><option value="">No rink</option><?php foreach ($scheduleData['rinks'] as $rink): ?><option value="<?= e($rink) ?>" <?= $rink === $session['location'] ? 'selected' : '' ?>><?= e($rink) ?></option><?php endforeach; ?></select>
                            <button class="button button-secondary" type="button" data-group-coaches-open="group-coaches-<?= e($session['id']) ?>"><?= !empty($sessionCoachesComplete[(int) $session['id']]) ? 'Change Coaches' : 'Assign Coaches' ?></button>
                            <button class="button button-secondary" name="intent" value="save">Save</button>
                            <button class="button button-ghost" name="intent" value="remove">Remove</button>
                        </form>
                    <?php endforeach; ?>
                    <form method="post" action="<?= e(url('sessions/sessions')) ?>" class="schedule-add session-row" data-preserve-scroll>
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="season_id" value="<?= e($selectedSeason['id']) ?>">
                        <input name="sku" placeholder="SKU" required maxlength="64" aria-label="SKU">
                        <input name="name" placeholder="Mon 1700-1750" aria-label="Session name">
                        <select name="day_of_week" aria-label="Day of week"><?php foreach ($days as $day => $label): ?><option value="<?= $day ?>"><?= e($label) ?></option><?php endforeach; ?></select>
                        <input name="start_time" type="time" required aria-label="Start time">
                        <input name="end_time" type="time" required aria-label="End time">
                        <select name="location" aria-label="Rink"><option value="">No rink</option><?php foreach ($scheduleData['rinks'] as $rink): ?><option value="<?= e($rink) ?>"><?= e($rink) ?></option><?php endforeach; ?></select>
                        <span class="session-coaches-unavailable">Add session first</span>
                        <button class="button button-primary" name="intent" value="save">Add session</button>
                    </form>
                    <?php foreach ($scheduleData['sessions'] as $session): ?>
                        <?php $groupsWithSkaters = $populatedSessionGroups[(int) $session['id']] ?? []; ?>
                        <dialog class="group-coaches-dialog" id="group-coaches-<?= e($session['id']) ?>" aria-labelledby="group-coaches-title-<?= e($session['id']) ?>">
                            <form method="post" action="<?= e(url('sessions/group-coaches')) ?>" class="group-coaches-form" data-preserve-scroll>
                                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="session_id" value="<?= e($session['id']) ?>">
                                <input type="hidden" name="season_id" value="<?= e($selectedSeason['id']) ?>">
                                <div class="group-coaches-dialog-heading">
                                    <div><h2 id="group-coaches-title-<?= e($session['id']) ?>">Coaches · <?= e($session['name']) ?></h2><p>Assign a report-card coach to each colour group with skaters.</p></div>
                                    <button class="dialog-close" type="button" data-group-coaches-close aria-label="Close">×</button>
                                </div>
                                <?php if ($groupsWithSkaters === []): ?>
                                    <p class="group-coaches-empty">No colour groups in this session currently have skaters assigned.</p>
                                <?php else: ?>
                                    <div class="group-coaches-list">
                                        <?php foreach ($groupsWithSkaters as $group): ?>
                                            <?php $skaterCount = (int) $group['skater_count']; ?>
                                            <label class="group-coaches-row">
                                                <span class="group-coaches-colour"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="<?= e($group['colour_hex'] ?: '#64748b') ?>" stroke="#5f6e80" stroke-width="1.5"></circle></svg><span><strong><?= e($group['name']) ?></strong><small><?= e($skaterCount . ' ' . ($skaterCount === 1 ? 'skater' : 'skaters')) ?></small></span></span>
                                                <select name="coach_user_id[<?= e($group['id']) ?>]" aria-label="Coach for <?= e($group['name']) ?>"><option value="">No coach assigned</option><?php foreach ($scheduleData['users'] as $coach): ?><option value="<?= e($coach['id']) ?>" <?= (int) ($group['report_card_coach_user_id'] ?? 0) === (int) $coach['id'] ? 'selected' : '' ?>><?= e(trim((string) $coach['first_name'] . ' ' . (string) $coach['last_name'])) ?></option><?php endforeach; ?></select>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="group-coaches-actions"><button class="button button-secondary" type="button" data-group-coaches-close>Cancel</button><button class="button button-primary" type="submit" <?= $groupsWithSkaters === [] ? 'disabled' : '' ?>>Save coaches</button></div>
                            </form>
                        </dialog>
                    <?php endforeach; ?>
                </section>
            </div>
            <?php else: ?>
                <p class="session-selection-empty">Select a season above to display its sessions.</p>
            <?php endif; ?>
            </details>
        </section>
        <section class="schedule-admin registration-import-card" aria-labelledby="registration-import-heading">
            <div class="registration-import-heading">
                <h2 id="registration-import-heading">Registration Import</h2>
                <p>Choose the season first. Every skater in this file will be imported into the selected season.</p>
            </div>
            <div class="registration-import-content">
                <div class="import-note">
                    Accepted files are CSV or Excel (.xlsx). These columns are required (in any order):
                    <code>Participant First Name</code>, <code>Participant Last Name</code>, <code>Gender</code>,
                    <code>Birthdate</code>, and <code>Registered Program SKU</code>.
                    Optional mapped columns include <code>Member Names</code>, <code>Member Email</code>,
                    <code>Member Telephone</code>, <code>Skate Canada Number</code>, and <code>Notes</code>. Other columns are ignored.
                    Gender accepts any text up to 80 characters and is displayed as entered.
                    The import validates every row before saving anything.
                </div>
                <a class="template-link" href="<?= e(url('imports/template.csv')) ?>">Download CSV template</a>
                <form method="post" action="<?= e(url('imports/skaters')) ?>" enctype="multipart/form-data" class="stacked-form registration-import-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        <span>Import season</span>
                        <select name="season_id" required>
                            <option value="">Select a season</option>
                            <?php foreach ($scheduleData['seasons'] as $season): ?>
                                <option value="<?= e($season['id']) ?>"><?= e($season['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="file-drop">
                        <span>Choose a CSV or Excel file</span>
                        <small>Maximum 2 MB · up to 10,000 rows</small>
                        <input type="file" name="skater_csv" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <button class="button button-primary" type="submit">Registration Import</button>
                </form>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
</body>
</html>
