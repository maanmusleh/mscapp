<?php
$displayName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')) ?: $user['username'];
$userInitials = strtoupper(trim(
    mb_substr(trim((string) ($user['first_name'] ?? '')), 0, 1)
    . mb_substr(trim((string) ($user['last_name'] ?? '')), 0, 1)
) ?: mb_substr($displayName, 0, 2));
$rinkStateScope = hash('sha256', session_id());
$rinkWorkerFile = dirname(__DIR__, 2) . '/public/rink-offline-sw.js';
$rinkWorkerUrl = rtrim((string) config('base_path', ''), '/') . '/rink-offline-sw.js'
    . (is_file($rinkWorkerFile) ? '?v=' . rawurlencode((string) filemtime($rinkWorkerFile)) : '');
$days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$formatTime = static fn (?string $time): string => $time ? date('g:i a', strtotime($time)) : '';
$chatRetentionDays = intdiv((int) $chatRetentionHours, 24);
$chatRetentionRemainingHours = (int) $chatRetentionHours % 24;
$chatRetentionParts = [];
if ($chatRetentionDays > 0) {
    $chatRetentionParts[] = $chatRetentionDays . ' ' . ($chatRetentionDays === 1 ? 'day' : 'days');
}
if ($chatRetentionRemainingHours > 0) {
    $chatRetentionParts[] = $chatRetentionRemainingHours . ' ' . ($chatRetentionRemainingHours === 1 ? 'hour' : 'hours');
}
$chatRetentionLabel = implode(' and ', $chatRetentionParts);
$selectedSession = null;
foreach ($seasonSessions as $session) {
    if ($session['id'] === $filters['session_id']) {
        $selectedSession = $session;
        break;
    }
}
$coachNavigator = [
    'seasons' => $filterOptions['seasons'],
    'sessions' => $filterOptions['sessions'],
    'season_id' => $filters['season_id'],
    'session_id' => $filters['session_id'],
];
$accountUrl = $user['role_code'] === 'COACH'
    ? url('account?' . http_build_query(array_filter([
        'season_id' => $filters['season_id'],
        'session_id' => $filters['session_id'],
    ], static fn ($value): bool => $value !== null)))
    : url('account');
$sessionGroupsByName = [];
$sessionGroupColours = [];
foreach ($sessionGroups as $group) {
    $sessionGroupsByName[mb_strtolower((string) $group['name'])] = $group;
    $colourKey = mb_strtolower((string) $group['colour_hex']);
    if (!isset($sessionGroupColours[$colourKey])) {
        $sessionGroupColours[$colourKey] = $group;
    }
}
$selectedGroup = null;
foreach ($sessionGroups as $group) {
    if ($group['id'] === $filters['group_id']) {
        $selectedGroup = $group;
        break;
    }
}
$activityUrl = static function (string $target) use ($filters): string {
    return url('rink-app?' . http_build_query([
        'season_id' => $filters['season_id'],
        'session_id' => $filters['session_id'],
        'activity' => $target,
        'group_id' => $filters['group_id'],
    ]));
};
$groupFilterUrl = static function (?int $targetGroupId) use ($filters, $activity): string {
    return url('rink-app?' . http_build_query([
        'season_id' => $filters['season_id'],
        'session_id' => $filters['session_id'],
        'activity' => $activity,
        'group_id' => $targetGroupId,
    ])) . '#rink-activity-bar';
};
$renderGroupSelector = static function () use ($selectedGroup, $selectedSession, $filters, $sessionGroups, $groupFilterUrl): string {
    $allSkaterCount = (int) ($selectedSession['skater_count'] ?? 0);
    $allSkaterCountLabel = 'Show all ' . $allSkaterCount . ' ' . ($allSkaterCount === 1 ? 'skater' : 'skaters');
    $allSkaterSummaryLabel = $allSkaterCount . ' ' . ($allSkaterCount === 1 ? 'skater' : 'skaters');
    $selectedGroupSkaterCount = (int) ($selectedGroup['skater_count'] ?? 0);
    $selectedGroupCountLabel = $selectedGroupSkaterCount . ' ' . ($selectedGroupSkaterCount === 1 ? 'skater' : 'skaters');
    $selectorColour = (string) ($selectedGroup['colour_hex'] ?? '#64748b');
    $selectorTopColour = $selectorColour;
    ob_start();
    ?>
    <details class="rink-bar-group-selector rink-roster-group-selector" data-rink-group-selector data-selector-colour="<?= e($selectorColour) ?>" data-selector-top-colour="<?= e($selectorTopColour) ?>" data-all-group-count="<?= e((string) $allSkaterCount) ?>">
        <summary class="<?= $selectedGroup === null ? 'is-all-groups' : '' ?>"><strong><?= e($selectedGroup['name'] ?? 'All Groups') ?></strong><small><?= $selectedGroup === null ? e($allSkaterSummaryLabel) : e($selectedGroupCountLabel) ?></small></summary>
        <div class="rink-bar-group-menu"><a class="is-all-groups <?= $selectedGroup === null ? 'is-selected' : '' ?>" data-rink-group-filter data-group-id="" data-group-name="All Groups" data-group-colour="#64748b" data-group-count="<?= e((string) $allSkaterCount) ?>" href="<?= e($groupFilterUrl(null)) ?>"><span class="rink-all-groups-swatch" aria-hidden="true"></span><span><strong>All Groups</strong><small><?= e($allSkaterCountLabel) ?></small></span></a><?php foreach ($sessionGroups as $group): ?><?php $groupSkaterCount = (int) ($group['skater_count'] ?? 0); ?><?php if ($groupSkaterCount === 0) { continue; } ?><a class="<?= $filters['group_id'] === $group['id'] ? 'is-selected' : '' ?>" data-rink-group-filter data-group-id="<?= e($group['id']) ?>" data-group-name="<?= e($group['name']) ?>" data-group-colour="<?= e($group['colour_hex']) ?>" data-group-count="<?= e((string) $groupSkaterCount) ?>" href="<?= e($groupFilterUrl($group['id'])) ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="<?= e($group['colour_hex']) ?>" stroke="#5f6e80" stroke-width="1.5"></circle></svg><span><strong><?= e($group['name']) ?></strong><small><?= e($groupSkaterCount . ' ' . ($groupSkaterCount === 1 ? 'skater' : 'skaters')) ?></small></span></a><?php endforeach; ?></div>
    </details>
    <?php
    return (string) ob_get_clean();
};
$skateCanadaBadgeAssets = skate_canada_badge_assets();
$ribbonIcon = static function (string $name): string {
    $letter = mb_strtoupper(mb_substr(trim($name), 0, 1));
    return '<svg viewBox="0 0 24 28" aria-hidden="true"><circle class="ribbon-icon-shape ribbon-icon-centre" cx="12" cy="8" r="7"></circle><path class="ribbon-icon-shape" d="M8 13.2 5.5 26 12 22.2 18.5 26 16 13.2"></path><text class="ribbon-icon-letter" x="12" y="10.7">' . e($letter) . '</text></svg>';
};
$renderRinkNavigator = static function (bool $global = false) use ($filterOptions, $filters, $seasonSessions, $selectedSession, $days, $formatTime): string {
    ob_start();
    ?>
    <section class="rink-navigator<?= $global ? ' is-global' : '' ?>" aria-label="Season and session navigator">
        <form method="get" action="<?= e(url()) ?>" data-rink-navigator>
            <input type="hidden" name="route" value="rink-app">
            <label><span>Season</span><select name="season_id" data-rink-season>
                <?php foreach ($filterOptions['seasons'] as $season): ?>
                    <option value="<?= e($season['id']) ?>" <?= $filters['season_id'] === $season['id'] ? 'selected' : '' ?>><?= e($season['name']) ?><?= date('Y-m-d') >= $season['start_date'] && date('Y-m-d') <= $season['end_date'] ? ' · Current' : '' ?></option>
                <?php endforeach; ?>
            </select></label>
            <label><span>Session</span><select name="session_id" data-rink-session>
                <option value="">Choose a session</option>
                <?php foreach ($seasonSessions as $session): ?>
                    <option value="<?= e($session['id']) ?>" <?= $filters['session_id'] === $session['id'] ? 'selected' : '' ?>><?= e($session['name']) ?> · <?= e(substr($days[$session['day_of_week']] ?? '', 0, 3)) ?> <?= e($formatTime($session['start_time'])) ?></option>
                <?php endforeach; ?>
            </select></label>
            <?php if ($selectedSession): ?><div class="rink-navigator-session"><strong><?= e($days[$selectedSession['day_of_week']] ?? '') ?></strong><span><?= e($formatTime($selectedSession['start_time'])) ?>–<?= e($formatTime($selectedSession['end_time'])) ?></span></div><?php endif; ?>
        </form>
    </section>
    <?php
    return (string) ob_get_clean();
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="rink-state-scope" content="<?= e($rinkStateScope) ?>">
    <title>Coach App · CAT</title>
    <script src="<?= e(asset('rink-restore-state.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <?php if ($user['role_code'] === 'COACH'): ?><link rel="stylesheet" href="<?= e(asset('coach-navigator.css')) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('rink-app.css')) ?>">
    <?php if ($user['role_code'] === 'COACH'): ?><script src="<?= e(asset('coach-navigator.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('badge-icons.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/pdf-lib.min.js')) ?>" defer></script>
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('rink-offline-model.js')) ?>" defer></script>
    <script src="<?= e(asset('rink-offline.js')) ?>" defer></script>
    <script src="<?= e(asset('rink-app.js')) ?>" defer></script>
</head>
<body
    class="app-page rink-app-page<?= $user['role_code'] === 'COACH' ? ' coach-account' : '' ?>"
    data-rink-api-base="<?= e(url('api/rink/skaters')) ?>"
    data-rink-status-url="<?= e(url('api/rink/status')) ?>"
    data-rink-roster-url="<?= e(url('api/rink/roster')) ?>"
    data-rink-assess-url="<?= e(url('api/rink/assess')) ?>"
    data-rink-chat-url="<?= e(url('api/rink/chat')) ?>"
    data-rink-chat-status-url="<?= e(url('api/rink/chat/status')) ?>"
    data-rink-role="<?= e($user['role_code']) ?>"
    data-current-club-id="<?= e((string) $user['club_id']) ?>"
    data-rink-offline-data-url="<?= e(url('api/rink/offline-data')) ?>"
    data-rink-offline-sync-url="<?= e(url('api/rink/offline-sync')) ?>"
    data-rink-worker-url="<?= e($rinkWorkerUrl) ?>"
    data-rink-page-url="<?= e(url('rink-app')) ?>"
    data-rink-login-url="<?= e(url('login')) ?>"
    data-current-user-id="<?= e((string) $user['id']) ?>"
    data-rink-can-edit="<?= in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true) ? 'true' : 'false' ?>"
    data-rink-can-customize-chat-expiry="<?= in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true) ? 'true' : 'false' ?>"
    data-current-user-first-name="<?= e($user['first_name'] ?? '') ?>"
    data-current-user-last-name="<?= e($user['last_name'] ?? '') ?>"
    data-rink-group-url="<?= e(url('api/rink/group-assignment')) ?>"
    data-rink-attendance-url="<?= e(url('api/rink/attendance')) ?>"
    data-rink-report-card-notes-url="<?= e(url('api/rink/report-card-notes')) ?>"
    data-rink-report-card-note-library-url="<?= e(url('api/rink/report-card-note-library')) ?>"
    data-rink-report-card-template-url="<?= e(url('api/rink/report-card-template')) ?>"
    data-rink-report-card-coach-url="<?= e(url('api/rink/report-card-coach')) ?>"
    data-club-name="<?= e($user['club_name'] ?? '') ?>"
    data-rink-medical-icon="<?= e(asset('medical-note-icon.png')) ?>"
    data-skate-canada-emblem="<?= e($skateCanadaBadgeAssets['emblem']) ?>"
    data-skate-canada-emblem-outline="<?= e($skateCanadaBadgeAssets['emblem_outline']) ?>"
    data-skate-canada-badge-shape="<?= e($skateCanadaBadgeAssets['shape']) ?>"
    data-skate-canada-badge-outline="<?= e($skateCanadaBadgeAssets['outline']) ?>"
    data-season-id="<?= e($filters['season_id'] ?? '') ?>"
    data-session-id="<?= e($filters['session_id'] ?? '') ?>"
    data-group-id="<?= e($filters['group_id'] ?? '') ?>"
    data-rink-activity="<?= e($activity) ?>"
    data-rink-state-scope="<?= e($rinkStateScope) ?>"
>
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= e(url($user['role_code'] === 'COACH' ? 'rink-app' : 'dashboard')) ?>"><span class="sidebar-brand-mark" aria-hidden="true"><img src="<?= e(asset('cat-logo.png')) ?>" alt=""></span><span class="brand-lockup" aria-label="CanSkate Achievement Tracker"><span class="brand-word"><b class="brand-initial">C</b>anSkate</span><span class="brand-word"><b class="brand-initial">A</b>chievement</span><span class="brand-word"><b class="brand-initial">T</b>racker</span></span></a>
        <nav class="primary-nav" aria-label="Main navigation">
            <?php if ($user['role_code'] === 'COACH'): ?><?php require __DIR__ . '/partials/coach-rink-navigator.php'; ?><?php endif; ?>
            <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY'], true)): ?><a class="nav-item" href="<?= e(url('dashboard')) ?>">Skaters</a><?php endif; ?>
            <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><a class="nav-item" href="<?= e(url('sessions')) ?>">Registration</a><?php endif; ?>
            <?php if ($user['role_code'] !== 'COACH'): ?><a class="nav-item" href="<?= e(url('reports')) ?>">Reports</a><a class="nav-item nav-item-rink active" href="<?= e(url('rink-app')) ?>">Coach App</a><?php endif; ?>
            <?php if ($user['role_code'] === 'ADMINISTRATOR'): ?><a class="nav-item nav-item-icon-only" href="<?= e(url('admin-tools')) ?>" aria-label="Admin Settings" title="Admin Settings"><span class="nav-icon nav-gear-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path><circle cx="12" cy="12" r="3.1"></circle></svg></span></a><?php endif; ?>
        </nav>
        <div class="sidebar-footer"><a class="avatar account-avatar nav-account-avatar" href="<?= e($accountUrl) ?>" title="Manage account for <?= e($displayName) ?>"><?= e($userInitials) ?></a><form method="post" action="<?= e(url('logout')) ?>"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="sign-out" type="submit">Sign out</button></form></div>
    </aside>

    <main class="app-main rink-main">
        <?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div><?php endforeach; ?>

        <?php if ($user['role_code'] !== 'COACH'): ?><?= $renderRinkNavigator() ?><?php endif; ?>

        <?php if ($selectedSession === null): ?>
            <section class="rink-empty-state<?= $user['role_code'] === 'COACH' ? ' coach-rink-empty-state' : '' ?>"><span class="rink-empty-icon" aria-hidden="true">⌁</span><h2>Choose a season and session</h2><p><?= $user['role_code'] === 'COACH' ? 'Use the navigator in the sidebar, then select Go to open your rink.' : 'Select a season and session above, then press Go.' ?></p></section>
        <?php else: ?>
            <nav class="rink-activity-grid is-workspace" id="rink-activity-bar" aria-label="Rink activities">
                <a class="rink-activity-card rink-activity-roster <?= $activity === 'roster' ? 'is-active' : '' ?>" href="<?= e($activityUrl('roster')) ?>"><span class="rink-activity-icon" aria-hidden="true">☷</span><strong>Roster</strong><small>Skaters &amp; attendance</small></a>
                <a class="rink-activity-card rink-activity-skills <?= $activity === 'skills' ? 'is-active' : '' ?>" href="<?= e($activityUrl('skills')) ?>"><span class="rink-activity-icon" aria-hidden="true">★</span><strong>Assess</strong><small>Skills, Ribbons &amp; Badges</small></a>
                <a class="rink-activity-card rink-activity-chat <?= $activity === 'chat' ? 'is-active' : '' ?>" href="<?= e($activityUrl('chat')) ?>"><span class="rink-activity-icon rink-chat-icon"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 12.5c0-3.6 3-6.5 6.6-6.5h18.8c3.6 0 6.6 2.9 6.6 6.5v13c0 3.6-3 6.5-6.6 6.5H24.8L16 39v-7c-3.4-.3-6-3.1-6-6.5v-13Z"></path><circle cx="18" cy="19" r="2"></circle><circle cx="26" cy="19" r="2"></circle><circle cx="34" cy="19" r="2"></circle></svg><span class="rink-chat-unread" data-rink-chat-unread data-unread-count="<?= e((string) $chatUnreadCount) ?>" role="status" aria-live="polite" aria-atomic="true" aria-label="<?= e($chatUnreadCount . ' unread ' . ($chatUnreadCount === 1 ? 'message' : 'messages')) ?>" <?= $chatUnreadCount > 0 ? '' : 'hidden' ?>><?= e($chatUnreadCount > 99 ? '99+' : (string) $chatUnreadCount) ?></span></span><span class="rink-chat-labels"><strong>Chat</strong><small>Session group chat</small></span></a>
                <div class="rink-connection-status is-connected" data-rink-connection-status role="status" aria-live="polite"><span class="rink-connection-dot" aria-hidden="true"></span><strong data-rink-connection-label>Connected</strong><span class="rink-offline-status" data-rink-offline-status>Setting up…</span></div>
            </nav>

            <div class="rink-activity-page rink-activity-page-<?= e($activity) ?>">
            <?php if ($activity === 'roster'): ?>
                <section class="rink-roster-card">
                    <header class="rink-roster-toolbar">
                        <?= $renderGroupSelector() ?>
                    </header>
                    <div class="rink-table-scroll" tabindex="0" aria-label="Sortable session roster and attendance">
                        <table class="rink-roster-table is-roster">
                            <thead><tr>
                                <?php if ($selectedGroup === null): ?>
                                    <th class="rink-skater-icon-header" data-rink-sort-header="group" aria-sort="none"><button type="button" data-rink-sort="group">Group<span aria-hidden="true">↕</span></button></th>
                                <?php else: ?>
                                    <th class="rink-skater-icon-header"><span class="rink-static-header">Group</span></th>
                                <?php endif; ?>
                                <?php foreach ([['first_name','First name'],['last_name','Last name'],['gender','Gender'],['age','Age'],['attendance','Here']] as [$key,$label]): ?>
                                    <th data-rink-sort-header="<?= e($key) ?>" aria-sort="<?= $key === 'first_name' ? 'ascending' : 'none' ?>">
                                        <button type="button" data-rink-sort="<?= e($key) ?>"><?= e($label) ?><span aria-hidden="true"><?= $key === 'first_name' ? '↑' : '↕' ?></span></button>
                                    </th>
                                <?php endforeach; ?>
                                <th class="rink-medical-header" aria-hidden="true"></th>
                            </tr></thead>
                            <tbody data-rink-roster-body></tbody>
                        </table>
                    </div>
                    <div class="rink-no-results" data-rink-no-results <?= $rosterPayload['skaters'] !== [] ? 'hidden' : '' ?>><h3>No skaters found</h3><p>No active skaters match this group.</p></div>
                </section>
                <script type="application/json" id="rink-roster-data"><?= json_encode($rosterPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
            <?php elseif ($activity === 'skills'): ?>
                <section class="rink-assess-stage-strip" data-rink-assess-stages>
                    <?= $renderGroupSelector() ?>
                    <details class="rink-assess-category-filter" data-rink-assess-category-selector aria-disabled="true">
                        <summary><span class="rink-assess-category-icons" data-rink-assess-category-icons aria-hidden="true"><span class="rink-assess-category-icon ribbon-balance"><?= $ribbonIcon('Balance') ?></span><span class="rink-assess-category-icon ribbon-control"><?= $ribbonIcon('Control') ?></span><span class="rink-assess-category-icon ribbon-agility"><?= $ribbonIcon('Agility') ?></span></span><strong data-rink-assess-category-label>Choose a category</strong></summary>
                        <div class="rink-assess-category-menu">
                            <p>Select category</p>
                            <button type="button" class="is-all" data-rink-assess-category-option data-category="all"><span class="rink-assess-category-icons" aria-hidden="true"><span class="rink-assess-category-icon ribbon-balance"><?= $ribbonIcon('Balance') ?></span><span class="rink-assess-category-icon ribbon-control"><?= $ribbonIcon('Control') ?></span><span class="rink-assess-category-icon ribbon-agility"><?= $ribbonIcon('Agility') ?></span></span><strong>Show All</strong></button>
                            <?php foreach (['balance' => 'Balance', 'control' => 'Control', 'agility' => 'Agility'] as $categoryValue => $categoryLabel): ?><button type="button" class="ribbon-<?= e($categoryValue) ?>" data-rink-assess-category-option data-category="<?= e($categoryValue) ?>"><span class="rink-assess-category-icon ribbon-<?= e($categoryValue) ?>" aria-hidden="true"><?= $ribbonIcon($categoryLabel) ?></span><strong><?= e($categoryLabel) ?></strong></button><?php endforeach; ?>
                        </div>
                    </details>
                    <?php foreach ($assessPayload['stages'] as $stage): ?>
                        <button class="rink-assess-stage-button stage-badge-stage-<?= e((string) $stage['number']) ?> <?= (int) $stage['number'] === 0 ? 'is-pre-canskate' : '' ?>" type="button" data-rink-assess-stage data-stage-id="<?= e((string) $stage['id']) ?>" data-stage-number="<?= e((string) $stage['number']) ?>" aria-label="Select <?= e((string) $stage['name']) ?>">
                            <?php if (!empty($stage['has_badge'])): ?>
                                <span class="rink-assess-stage-badge" aria-hidden="true"><?= skate_canada_badge_icon() ?></span>
                            <?php elseif ((int) $stage['number'] === 0): ?>
                                <span class="rink-assess-stage-participation ribbon-pre-canskate" aria-hidden="true"><?= $ribbonIcon('Participation') ?></span>
                            <?php endif; ?>
                            <span class="rink-assess-stage-label"><?= e((string) $stage['name']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </section>
                <section class="rink-assess-roster-card">
                    <div class="rink-assess-empty" data-rink-assess-empty>
                        <h3>No stage selected</h3>
                        <p>Select a stage button above.</p>
                    </div>
                    <div class="rink-table-scroll" tabindex="0" aria-label="Sortable assess roster and skills" data-rink-assess-table-wrap hidden>
                        <table class="rink-roster-table rink-assess-table">
                            <thead data-rink-assess-table-head></thead>
                            <tbody data-rink-assess-table-body></tbody>
                        </table>
                    </div>
                </section>
                <div class="rink-report-cards-dock">
                    <div class="rink-report-cards-panel is-collapsed" data-rink-report-cards-panel aria-hidden="true"><div class="rink-report-cards-toolbar"><button class="rink-report-cards-action rink-report-cards-action-generate" type="button" data-rink-generate-report-cards title="Generate report cards for the selected skaters" disabled><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.2 2.8h6l3.6 3.6v10.8H5.2V2.8Z"></path><path d="M11.2 2.8v3.7h3.6M7.7 10h4.8M7.7 13h4.8"></path></svg><span>Generate</span></button><button class="rink-report-cards-action rink-report-cards-action-note" type="button" data-rink-open-note-library title="Create a reusable note clip"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.2 3.4h9.6v9.2H9.2l-3.4 3v-3H5.2V3.4Z"></path><path d="M10 6.2v4M8 8.2h4"></path></svg><span>Create Clip</span></button><div class="rink-note-library-list" data-rink-note-library-list aria-label="Your notes library"></div><div class="rink-note-library-actions" data-rink-note-library-actions hidden><button type="button" data-rink-edit-library-note aria-label="Edit note" title="Edit note"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4 13.8-.7 3 3-.7L15.4 7 13 4.6 4 13.8Z"></path><path d="m11.9 5.7 2.4 2.4"></path></svg></button><button type="button" data-rink-delete-library-note aria-label="Delete note" title="Delete note"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 6.5h9l-.6 10h-7.8l-.6-10Z"></path><path d="M4 6.5h12M7.5 6.5V4h5v2.5M8.5 9v4.5M11.5 9v4.5"></path></svg></button></div></div></div>
                    <button class="rink-report-cards-tab" type="button" data-rink-report-cards-tab aria-expanded="false">Report Cards</button>
                </div>
                <script type="application/json" id="rink-assess-data"><?= json_encode($assessPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
            <?php elseif ($activity === 'chat'): ?>
                <section class="rink-chat-card" data-rink-chat>
                    <header><div><span class="eyebrow">Session group chat</span></div><small>Messages are automatically deleted <?= e($chatRetentionLabel) ?> after posting.</small></header>
                    <div class="rink-chat-history" data-rink-chat-history aria-live="polite"></div>
                    <form class="rink-chat-compose" data-rink-chat-form><textarea data-rink-chat-input rows="1" maxlength="1500" placeholder="Write a message to this session…" aria-label="New chat message"></textarea><div class="rink-chat-post-actions"><span class="rink-chat-post-status" data-rink-chat-post-status role="status" hidden>Offline</span><button type="submit" data-rink-chat-post>Post</button></div></form>
                </section>
                <script type="application/json" id="rink-chat-data"><?= json_encode($chatPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
            <?php else: ?>
                <section class="rink-placeholder"><span class="rink-activity-icon" aria-hidden="true"><?= $activity === 'skills' ? '★' : '◌' ?></span><h2><?= e($activity === 'skills' ? 'Assess' : ucfirst($activity)) ?></h2><p>This activity is reserved for the next design stage.</p></section>
            <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <dialog class="rink-detail-dialog rink-skater-dialog" id="rink-profile-dialog"><form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close skater details">×</button></form><div data-rink-profile-content><div class="loading-state"><span></span><p>Loading skater details…</p></div></div></dialog>
    <dialog class="rink-detail-dialog rink-medical-dialog" id="rink-medical-dialog"><form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close note">×</button></form><span class="eyebrow">Medical / accommodations</span><h2 data-medical-title>Skater note</h2><p data-medical-content></p></dialog>
    <dialog class="rink-detail-dialog rink-general-note-dialog" id="rink-general-note-dialog"><form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close note">×</button></form><span class="eyebrow">General notes</span><h2 data-general-note-title>Skater note</h2><p data-general-note-content></p></dialog>
    <div class="rink-report-card-note-backdrop" data-rink-report-card-note-backdrop hidden></div>
    <dialog class="rink-detail-dialog rink-report-card-note-dialog" id="rink-report-card-note-dialog" aria-labelledby="rink-report-card-note-title"><form data-rink-report-card-note-form><span class="eyebrow">Report card notes</span><h2 id="rink-report-card-note-title" data-rink-report-card-note-title>Skater note</h2><p class="rink-report-card-registration" data-rink-report-card-registration></p><div class="rink-report-card-note-layout"><div class="rink-report-card-note-editor"><label class="rink-report-card-note-field">Note<textarea data-rink-report-card-note-input rows="7" maxlength="2000" aria-label="Report-card note"></textarea><small data-rink-report-card-note-count>2000 characters remaining</small></label><p class="rink-report-card-note-updated" data-rink-report-card-note-updated hidden></p><div class="form-message" data-rink-report-card-note-message hidden></div><div class="rink-report-card-note-actions"><button class="button button-ghost rink-report-card-note-delete" type="button" data-rink-report-card-note-delete hidden>Delete note</button><button class="button button-ghost" type="button" data-rink-report-card-note-cancel>Cancel</button><button class="button button-primary" type="submit" data-rink-report-card-note-save>Save note</button></div></div><aside class="rink-report-card-achievements" aria-label="Recent achievements"><h3>Achievements from past <label><span class="sr-only">Weeks to show</span><select data-rink-report-card-achievement-weeks><option value="4">4</option><option value="8">8</option><option value="12" selected>12</option><option value="16">16</option><option value="26">26</option><option value="52">52</option></select></label> weeks</h3><div data-rink-report-card-achievements></div></aside></div></form></dialog>
    <dialog class="rink-detail-dialog rink-note-library-dialog" id="rink-note-library-dialog" aria-labelledby="rink-note-library-title"><form data-rink-note-library-form><span class="eyebrow">Notes library</span><h2 id="rink-note-library-title">Create reusable note clip</h2><label class="rink-note-library-field">Title<input type="text" maxlength="32" required data-rink-note-library-title><small data-rink-note-library-title-count>32 characters remaining</small></label><label class="rink-note-library-field">Note<textarea rows="6" maxlength="512" required data-rink-note-library-content></textarea><small data-rink-note-library-content-count>512 characters remaining</small></label><div class="rink-note-library-fields" aria-label="Insert a report-card field"><span>Insert field</span><button type="button" draggable="true" data-rink-note-library-field-token="{skater first name}">{skater first name}</button><button type="button" draggable="true" data-rink-note-library-field-token="{skater last name}">{skater last name}</button><button type="button" draggable="true" data-rink-note-library-field-token="{coach first name}">{coach first name}</button><button type="button" draggable="true" data-rink-note-library-field-token="{coach last name}">{coach last name}</button></div><div class="form-message" data-rink-note-library-message hidden></div><div class="rink-report-card-note-actions"><button class="button button-ghost" type="button" data-rink-note-library-cancel>Cancel</button><button class="button button-primary" type="submit" data-rink-note-library-save>Save note</button></div></form></dialog>
    <dialog class="import-dialog group-assignment-dialog" id="rink-group-dialog" aria-labelledby="rink-group-title">
        <form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close group selection">×</button></form>
        <span class="eyebrow">Colour group</span>
        <h2 id="rink-group-title" data-rink-group-title>Choose a group</h2>
        <p>Changes save immediately.</p>
        <div class="group-picker">
            <?php foreach ($groupColours as $colour): ?>
                <?php $existingGroup = $sessionGroupsByName[mb_strtolower((string) $colour['name'])] ?? null; ?>
                <button class="group-picker-choice" type="button" data-rink-group-choice data-group-id="<?= e($existingGroup['id'] ?? '') ?>" data-group-name="<?= e($colour['name']) ?>" data-group-colour="<?= e($colour['hex']) ?>"><svg class="group-picker-swatch" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="<?= e($colour['hex']) ?>" stroke="#5f6e80" stroke-width="1.5"></circle></svg><?= e($colour['name']) ?></button>
            <?php endforeach; ?>
            <button class="group-picker-choice group-picker-none" type="button" data-rink-group-choice data-unassign="1" data-group-id="" data-group-name="No group" data-group-colour="#ffffff"><svg class="group-picker-swatch" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="#ffffff" stroke="#5f6e80" stroke-width="1.5"></circle><path d="M4 16 16 4" stroke="#697789" stroke-width="2" stroke-linecap="round"></path></svg>No group</button>
        </div>
        <div class="form-message" data-rink-group-message hidden></div>
    </dialog>
    <dialog class="rink-detail-dialog rink-chat-expiry-dialog" data-rink-chat-expiry-dialog aria-labelledby="rink-chat-expiry-title">
        <form data-rink-chat-expiry-form>
            <span class="eyebrow">Message expiry</span>
            <h2 id="rink-chat-expiry-title">Set deletion date and time</h2>
            <p>Override the normal <?= e($chatRetentionLabel) ?> message retention time and choose when this message will be removed from the session chat.</p>
            <label>Delete message on<input type="datetime-local" data-rink-chat-expiry-input required></label>
            <div class="rink-chat-expiry-actions"><button class="button button-ghost" type="button" data-rink-chat-expiry-cancel>Cancel</button><button class="button button-primary" type="submit">Save expiry</button></div>
        </form>
    </dialog>
    <dialog class="app-confirm-dialog" data-app-confirm-dialog aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
        <div class="app-confirm-icon" aria-hidden="true">!</div>
        <span class="eyebrow" data-app-confirm-eyebrow>Please confirm</span>
        <h2 id="app-confirm-title" data-app-confirm-title>Are you sure?</h2>
        <p id="app-confirm-message" data-app-confirm-message>Please confirm that you want to continue.</p>
        <div class="app-confirm-actions">
            <button class="button button-ghost" type="button" data-app-confirm-cancel>Cancel</button>
            <button class="button button-primary" type="button" data-app-confirm-accept>Continue</button>
        </div>
    </dialog>
    <div class="skill-toast" data-rink-toast hidden><span data-rink-toast-message></span><button class="skill-toast-close" type="button" data-rink-toast-close aria-label="Close message" hidden>×</button></div>
    <script src="<?= e(asset('common-dialog.js')) ?>"></script>
</body>
</html>
