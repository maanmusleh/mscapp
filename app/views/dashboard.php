<?php
$defaultGroupColours = $groupColours;
$displayName = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')) ?: $user['username'];
$userInitials = trim(mb_substr(trim((string) ($user['first_name'] ?? '')), 0, 1)
    . mb_substr(trim((string) ($user['last_name'] ?? '')), 0, 1));
$userInitials = strtoupper($userInitials !== '' ? $userInitials : mb_substr($displayName, 0, 2));
$paginationQuery = $_GET;
unset($paginationQuery['route']);
$paginationQuery['season_id'] = !empty($filters['unregistered']) ? 'unregistered' : $filters['season_id'];
$paginationQuery['session_id'] = !empty($filters['unregistered_session']) ? 'unregistered' : ($filters['session_id'] ?? null);
$paginationQuery['page_size'] = $pagination['page_size'];
$paginationUrl = static function (int $page) use ($paginationQuery): string {
    $query = $paginationQuery;
    $query['page'] = $page;

    return url('dashboard?' . http_build_query($query));
};
$firstShown = $pagination['total'] > 0
    ? (($pagination['page'] - 1) * $pagination['page_size']) + 1
    : 0;
$lastShown = min(
    $pagination['total'],
    $pagination['page'] * $pagination['page_size']
);
$groupFilterOptions = [];
foreach ($filterOptions['groups'] as $group) {
    $groupName = trim((string) $group['name']);
    $groupKey = (string) $group['season_id'] . "\0" . mb_strtolower($groupName);
    if (!isset($groupFilterOptions[$groupKey])) {
        $group['group_ids'] = [];
        $group['session_ids'] = [];
        $groupFilterOptions[$groupKey] = $group;
    }
    $groupFilterOptions[$groupKey]['group_ids'][] = (int) $group['id'];
    $groupFilterOptions[$groupKey]['session_ids'][] = (int) $group['program_session_id'];
}
$skateCanadaBadgeAssets = skate_canada_badge_assets();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title>Skaters · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('dashboard-layout.css')) ?>">
    <script src="<?= e(asset('pagination-navigation.js')) ?>" defer></script>
    <script src="<?= e(asset('badge-icons.js')) ?>" defer></script>
    <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?><script src="<?= e(asset('site-nav.js')) ?>" defer></script><?php endif; ?>
    <script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body
    class="app-page"
    data-api-base="<?= e(url('api/skaters')) ?>"
    data-user-id="<?= e($user['id']) ?>"
    data-club-id="<?= e($user['club_id']) ?>"
    data-can-override-awards="<?= $canEdit ? 'true' : 'false' ?>"
    data-group-colours="<?= e(json_encode($groupColours, JSON_THROW_ON_ERROR)) ?>"
    data-genders="<?= e(json_encode($genders, JSON_THROW_ON_ERROR)) ?>"
    data-sessions="<?= e(json_encode($filterOptions['sessions'], JSON_THROW_ON_ERROR)) ?>"
    data-skate-canada-emblem="<?= e($skateCanadaBadgeAssets['emblem']) ?>"
    data-skate-canada-emblem-outline="<?= e($skateCanadaBadgeAssets['emblem_outline']) ?>"
    data-skate-canada-badge-shape="<?= e($skateCanadaBadgeAssets['shape']) ?>"
    data-skate-canada-badge-outline="<?= e($skateCanadaBadgeAssets['outline']) ?>"
>
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
            <a class="nav-item active" href="<?= e(url('dashboard')) ?>">
                Skaters
            </a>
            <?php if (in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true)): ?>
                <a class="nav-item" href="<?= e(url('sessions')) ?>">Registration</a>
            <?php else: ?>
                <button class="nav-item nav-item-disabled" type="button" title="Coming in a later phase">Registration</button>
            <?php endif; ?>
            <a class="nav-item" href="<?= e(url('reports')) ?>">Reports</a>
            <a class="nav-item nav-item-rink" href="<?= e(url('rink-app')) ?>">Coach App</a>
            <?php if ($user['role_code'] === 'ADMINISTRATOR'): ?>
                <a class="nav-item nav-item-icon-only" href="<?= e(url('admin-tools')) ?>" aria-label="Admin Settings" title="Admin Settings"><span class="nav-icon nav-gear-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path><circle cx="12" cy="12" r="3.1"></circle></svg></span></a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="avatar account-avatar nav-account-avatar" href="<?= e(url('account')) ?>" title="Manage account for <?= e($displayName) ?>"><?= e($userInitials) ?></a>
            <form method="post" action="<?= e(url('logout')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="sign-out" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <main class="app-main dashboard-main">
        <header class="topbar">
            <div>
                <h1>Skater Dashboard</h1>
                <p>Manage skaters and their CanSkate achievements.</p>
            </div>
            <div class="topbar-actions">
                <button
                    class="icon-button settings-gear-button"
                    type="button"
                    data-open-progress-settings
                    aria-label="Open Skater progress settings"
                    title="Skater progress settings"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M9.8 2.8h4.4l.7 2.3c.5.2 1 .5 1.5.9l2.3-.5 2.2 3.8-1.6 1.8v1.8l1.6 1.8-2.2 3.8-2.3-.5c-.5.4-1 .7-1.5.9l-.7 2.3H9.8l-.7-2.3c-.5-.2-1-.5-1.5-.9l-2.3.5-2.2-3.8 1.6-1.8v-1.8L3.1 9.3l2.2-3.8 2.3.5c.5-.4 1-.7 1.5-.9l.7-2.3Z"></path>
                        <circle cx="12" cy="12" r="3.1"></circle>
                    </svg>
                </button>
            </div>
        </header>

        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?> dashboard-alert"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>

        <section class="workspace-card" aria-label="Skater roster">
            <div class="workspace-toolbar">
                <form class="filter-form" method="get" action="<?= e(url()) ?>">
                    <input type="hidden" name="route" value="dashboard">
                    <div class="filter-controls-row">
                        <label class="filter-menu-field">
                            <span class="filter-menu-label">Season</span>
                            <select class="season-filter" name="season_id" data-season-filter required>
                                <?php if ($filterOptions['seasons'] === []): ?>
                                    <option value="" selected disabled>No seasons available</option>
                                <?php endif; ?>
                                <?php foreach ($filterOptions['seasons'] as $season): ?>
                                    <option
                                        value="<?= e($season['id']) ?>"
                                        <?= $filters['season_id'] === $season['id'] ? 'selected' : '' ?>
                                    ><?= e($season['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="unregistered" <?= !empty($filters['unregistered']) ? 'selected' : '' ?>>Unassigned</option>
                            </select>
                        </label>
                        <label class="filter-menu-field">
                            <span class="filter-menu-label">Session</span>
                            <select class="session-filter" name="session_id" data-session-filter>
                                <option value="">All sessions</option>
                                <?php foreach ($filterOptions['sessions'] as $session): ?>
                                    <option
                                        value="<?= e($session['id']) ?>"
                                        data-season-id="<?= e($session['season_id']) ?>"
                                        <?= $filters['session_id'] === $session['id'] ? 'selected' : '' ?>
                                    ><?= e($session['name']) ?></option>
                                <?php endforeach; ?>
                                <option value="unregistered" <?= !empty($filters['unregistered_session']) ? 'selected' : '' ?>>Unregistered</option>
                            </select>
                        </label>
                        <label class="filter-menu-field">
                            <span class="filter-menu-label">Group</span>
                            <select class="group-filter" name="group_id" data-group-filter>
                                <option value="">All groups</option>
                                <?php foreach ($groupFilterOptions as $group): ?>
                                    <option
                                        value="<?= e($group['id']) ?>"
                                        data-session-id="<?= e($group['program_session_id']) ?>"
                                        data-session-ids="<?= e(implode(',', array_unique($group['session_ids']))) ?>"
                                        data-season-id="<?= e($group['season_id']) ?>"
                                        data-group-ids="<?= e(implode(',', $group['group_ids'])) ?>"
                                        <?= in_array($filters['group_id'], $group['group_ids'], true) ? 'selected' : '' ?>
                                    ><?= e($group['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="filter-menu-field">
                            <span class="filter-menu-label">Age</span>
                            <select class="age-filter" name="age" data-age-filter>
                                <option value="">All ages</option>
                                <?php foreach ($availableAges as $age): ?>
                                    <option value="<?= e($age) ?>" <?= $filters['age'] === $age ? 'selected' : '' ?>><?= e($age) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="filter-menu-field">
                            <span class="filter-menu-label">Highest badge</span>
                            <select class="highest-badge-filter" name="highest_badge" data-highest-badge-filter>
                                <option value="">All badges</option>
                                <option value="0" <?= $filters['highest_badge'] === 0 ? 'selected' : '' ?>>No badge</option>
                                <?php foreach ($stages as $stage): ?>
                                    <?php if (empty($stage['has_badge'])) { continue; } ?>
                                    <option value="<?= e($stage['number']) ?>" <?= $filters['highest_badge'] === $stage['number'] ? 'selected' : '' ?>>Stage <?= e($stage['number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button
                            class="button button-ghost"
                            type="button"
                            data-reset-roster-filters
                            <?= $filters['session_id'] === null
                                && $filters['group_id'] === null
                                && $filters['age'] === null
                                && $filters['highest_badge'] === null
                                && trim((string) ($filters['search'] ?? '')) === '' ? 'hidden' : '' ?>
                        >Reset filters</button>
                    </div>
                    <div class="filter-search-row">
                        <label class="search-field">
                            <span class="sr-only">Search skaters</span>
                            <input
                                type="search"
                                placeholder="Search name or Skate Canada no."
                                autocomplete="off"
                                data-skater-search
                                value="<?= e($filters['search'] ?? '') ?>"
                            >
                        </label>
                    </div>
                </form>
            </div>

            <div class="sheet-scroll sheet-scroll-top" data-sheet-scroll-top tabindex="0" aria-label="Horizontal scrollbar for the skater achievement table" hidden>
                <div class="sheet-scroll-top-track" data-sheet-scroll-top-track aria-hidden="true"></div>
            </div>
            <div class="sheet-scroll" data-sheet-scroll-main tabindex="0" aria-label="Scrollable skater achievement table">
                <table class="skater-sheet">
                    <thead>
                        <tr class="stage-control-row">
                            <th class="sticky-col sticky-select" rowspan="2">
                                <?php if ($canEdit): ?>
                                    <div class="roster-header-actions">
                                        <button class="icon-button skater-action-button skater-add-button" type="button" data-open-add-skater aria-label="Add skater" title="Add skater"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5v15M4.5 12h15"></path></svg></button>
                                        <button class="icon-button skater-action-button skater-group-button" type="button" data-open-group-assignment aria-label="Assign selected skaters to a group" title="Assign selected skaters to a group" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path class="rainbow-red" d="M3.5 18a8.5 8.5 0 0 1 17 0"></path><path class="rainbow-yellow" d="M6.5 18a5.5 5.5 0 0 1 11 0"></path><path class="rainbow-blue" d="M9.5 18a2.5 2.5 0 0 1 5 0"></path></svg></button>
                                        <button class="icon-button skater-action-button skater-delete-button" type="button" data-open-delete-skaters aria-label="Delete selected skater(s)" title="Delete selected skater(s)" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path class="trash-fill" fill-rule="evenodd" clip-rule="evenodd" d="M9.75 3a2.25 2.25 0 0 0-2.121 1.5H4.5a.75.75 0 0 0 0 1.5h.75v13.125A1.875 1.875 0 0 0 7.125 21h9.75a1.875 1.875 0 0 0 1.875-1.875V6h.75a.75.75 0 0 0 0-1.5h-3.129A2.25 2.25 0 0 0 14.25 3h-4.5Zm0 1.5h4.5a.75.75 0 0 1 .75.75V6h-6v-.75a.75.75 0 0 1 .75-.75ZM9 9a.75.75 0 0 1 1.5 0v9a.75.75 0 0 1-1.5 0V9Zm4.5-.75a.75.75 0 0 0-.75.75v9a.75.75 0 0 0 1.5 0V9a.75.75 0 0 0-.75-.75Z"></path></svg></button>
                                    </div>
                                <?php endif; ?>
                                <input
                                    class="row-selection-checkbox"
                                    type="checkbox"
                                    data-select-all-skaters
                                    aria-label="Select all displayed skaters"
                                >
                            </th>
                            <th class="sticky-col sticky-first-name" data-sort-header="first-name" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="first-name" data-sort-direction="asc" aria-label="Sort skaters by first name ascending">
                                    <span>FIRST</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                                <span class="column-resizer" data-column-resizer="first-name" role="separator" aria-label="Resize First Name column" tabindex="0"></span>
                            </th>
                            <th class="sticky-col sticky-last-name" data-sort-header="last-name" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="last-name" data-sort-direction="asc" aria-label="Sort skaters by last name ascending">
                                    <span>LAST</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                                <span class="column-resizer" data-column-resizer="last-name" role="separator" aria-label="Resize Last Name column" tabindex="0"></span>
                            </th>
                            <th class="sticky-col sticky-number" data-sort-header="number" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="number" data-sort-direction="asc" aria-label="Sort by Skate Canada number ascending">
                                    <span>SKATE CAN #</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th class="optional-data-col dob-col is-settings-hidden" data-optional-column="dob" data-sort-header="dob" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="dob" data-sort-direction="asc" aria-label="Sort by date of birth ascending">
                                    <span>DOB</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th class="optional-data-col age-col is-settings-hidden" data-optional-column="age" data-sort-header="age" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="age" data-sort-direction="asc" aria-label="Sort by age ascending">
                                    <span>AGE</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th class="optional-data-col gender-col is-settings-hidden" data-optional-column="gender" data-sort-header="gender" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="gender" data-sort-direction="asc" aria-label="Sort by gender ascending">
                                    <span>GEN</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th
                                class="optional-data-col attendance-col is-settings-hidden"
                                data-optional-column="attendance"
                                data-sort-header="attendance"
                                aria-sort="none"
                                rowspan="2"
                            >
                                <button class="sortable-header" type="button" data-sort-key="attendance" data-sort-direction="asc" aria-label="Sort by attended days ascending">
                                    <span>ATN</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th class="optional-data-col groups-col is-settings-hidden" data-optional-column="groups" data-sort-header="group" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="group" data-sort-direction="asc" aria-label="Sort by group ascending">
                                    <span>GRP</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <th class="optional-data-col notes-col is-settings-hidden" data-optional-column="notes" data-sort-header="notes" aria-sort="none" rowspan="2">
                                <button class="sortable-header" type="button" data-sort-key="notes" data-sort-direction="asc" aria-label="Sort by notes ascending">
                                    <span>NOTE</span>
                                    <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                </button>
                            </th>
                            <?php foreach ($stages as $stage): ?>
                                <th
                                    class="stage-summary-col stage-control-header <?= (int) $stage['number'] === 0 ? 'stage-pre-canskate' : '' ?>"
                                    data-stage-column="<?= e($stage['id']) ?>"
                                    data-sort-header="stage-<?= e($stage['id']) ?>"
                                    aria-sort="none"
                                    rowspan="2"
                                >
                                    <span class="header-stage-controls">
                                        <?php if (!empty($stage['has_badge'])): ?>
                                        <button
                                            class="stage-badge-button header-stage-badge stage-badge-stage-<?= e($stage['number']) ?>"
                                            type="button"
                                            data-header-toggle-stage="<?= e($stage['id']) ?>"
                                            data-stage-name="<?= e($stage['name']) ?>"
                                            aria-expanded="false"
                                            aria-label="<?= e('Expand all ' . $stage['name'] . ' skill columns') ?>"
                                            title="<?= e('Show all ' . $stage['name'] . ' skills') ?>"
                                        >
                                            <span class="header-stage-badge-envelope">
                                                <?= skate_canada_badge_icon() ?>
                                            </span>
                                        </button>
                                        <?php endif; ?>
                                    </span>
                                    <span class="stage-sort-row">
                                        <button class="sortable-header stage-sortable-header" type="button" data-sort-key="stage-<?= e($stage['id']) ?>" data-sort-direction="asc" aria-label="<?= e('Sort by ' . $stage['name'] . ' skills ascending') ?>">
                                            <span class="header-stage-label"><?= e($stage['number'] > 0 ? 'STAGE ' . $stage['number'] : 'PRE-CS') ?></span>
                                            <span class="sort-direction-indicator" data-sort-indicator aria-hidden="true">↕</span>
                                        </button>
                                        <button
                                            class="stage-expand-box"
                                            type="button"
                                            data-header-toggle-stage="<?= e($stage['id']) ?>"
                                            data-stage-name="<?= e($stage['name']) ?>"
                                            aria-expanded="false"
                                            aria-label="<?= e('Expand all ' . $stage['name'] . ' skill columns') ?>"
                                            title="<?= e('Show all ' . $stage['name'] . ' skills') ?>"
                                        ><span data-stage-toggle-symbol aria-hidden="true">+</span></button>
                                    </span>
                                </th>
                                <?php foreach ($stage['ribbons'] as $ribbon): ?>
                                    <?php
                                    $ribbonClass = ribbon_class((string) $ribbon['name']);
                                    ?>
                                    <th
                                        class="ribbon-group-header <?= e($ribbonClass) ?> hidden-column"
                                        colspan="<?= count($ribbon['skills']) ?>"
                                        data-stage-detail="<?= e($stage['id']) ?>"
                                        data-ribbon-skill="<?= e($ribbon['id']) ?>"
                                        title="<?= e($ribbon['required_count'] . ' of ' . $ribbon['skill_count'] . ' skills required for the ' . $stage['name'] . ' ' . $ribbon['name'] . ' ribbon') ?>"
                                    >
                                        <span><?= e($stage['number'] > 0 ? 'S' . $stage['number'] . ' · ' : '') ?><?= e($ribbon['name']) ?></span>
                                        <small class="ribbon-requirement"><?= e($ribbon['required_count']) ?>/<?= e($ribbon['skill_count']) ?> required</small>
                                    </th>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="skill-label-row">
                            <?php foreach ($stages as $stage): ?>
                                <?php foreach ($stage['ribbons'] as $ribbon): ?>
                                    <?php
                                    $ribbonClass = ribbon_class((string) $ribbon['name']);
                                    ?>
                                    <?php foreach ($ribbon['skills'] as $skill): ?>
                                        <th
                                            class="skill-col <?= e($ribbonClass) ?> hidden-column"
                                            data-stage-detail="<?= e($stage['id']) ?>"
                                            data-ribbon-skill="<?= e($ribbon['id']) ?>"
                                            title="<?= e($skill['name']) ?>"
                                        >
                                            <span class="skill-name-frame">
                                                <span class="skill-name"><?= e($skill['name']) ?></span>
                                            </span>
                                        </th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($skaters === []): ?>
                        <tr>
                            <td colspan="<?= 9 + count($stages) ?>" class="empty-state">
                                <strong>No skaters match this view.</strong>
                                <span>Try changing the season, session, or group filter.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($skaters !== []): ?>
                        <tr data-live-search-empty hidden>
                            <td colspan="<?= 9 + count($stages) ?>" class="empty-state">
                                <strong>No skaters match this search.</strong>
                                <span>Try a different name or Skate Canada number.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($skaters as $skaterIndex => $skater): ?>
                        <?php
                        $rosterFilterMap = [];
                        foreach ($skater['season_views'] as $viewSeasonId => $seasonView) {
                            $rosterFilterMap[(string) $viewSeasonId] = array_map(
                                static fn (array $session): array => [
                                    'session_id' => $session['session_id'],
                                    'group_id' => $session['group_id'],
                                ],
                                $seasonView['filter_registrations']
                            );
                        }
                        $skaterAge = $skater['filter_age'];
                        $highestBadgeEarned = $skater['highest_badge'];
                        ?>
                        <tr
                            class="<?= (int) $skater['active'] === 1 ? '' : 'inactive-row' ?> <?= $skaterIndex % 2 === 1 ? 'is-alternate-row' : '' ?>"
                            data-skater-row
                            data-roster-filter-map="<?= e(json_encode($rosterFilterMap, JSON_THROW_ON_ERROR)) ?>"
                            data-sort-name="<?= e($skater['last_name'] . ', ' . $skater['first_name']) ?>"
                            data-sort-first-name="<?= e($skater['first_name']) ?>"
                            data-sort-last-name="<?= e($skater['last_name']) ?>"
                            data-sort-number="<?= e($skater['skate_canada_number'] ?? '') ?>"
                            data-filter-age="<?= e($skaterAge ?? '') ?>"
                            data-highest-badge="<?= e($highestBadgeEarned) ?>"
                            data-search-text="<?= e(
                                $skater['first_name']
                                . ' '
                                . $skater['last_name']
                                . ' '
                                . $skater['last_name']
                                . ', '
                                . $skater['first_name']
                                . ' '
                                . ($skater['skate_canada_number'] ?: '[unassigned]')
                            ) ?>"
                            <?= $skater['initially_visible'] ? '' : 'hidden' ?>
                        >
                            <td class="sticky-col sticky-select">
                                <input
                                    class="row-selection-checkbox"
                                    type="checkbox"
                                    value="<?= e($skater['public_id']) ?>"
                                    data-select-skater
                                    aria-label="<?= e('Select ' . $skater['first_name'] . ' ' . $skater['last_name']) ?>"
                                >
                            </td>
                            <td class="sticky-col sticky-first-name">
                                <strong><?= e($skater['first_name']) ?></strong>
                                <?php if ((int) $skater['active'] !== 1): ?><small>Inactive</small><?php endif; ?>
                            </td>
                            <td class="sticky-col sticky-last-name">
                                <strong><?= e($skater['last_name']) ?></strong>
                                <?php if ((int) $skater['active'] !== 1): ?><small>Inactive</small><?php endif; ?>
                            </td>
                            <td class="sticky-col sticky-number">
                                <button
                                    class="skater-number-link"
                                    type="button"
                                    data-skater-id="<?= e($skater['public_id']) ?>"
                                    title="Open <?= e($skater['first_name'] . ' ' . $skater['last_name']) ?>’s record"
                                    aria-label="Open skater record for <?= e($skater['first_name'] . ' ' . $skater['last_name']) ?>"
                                ><?= e($skater['skate_canada_number'] ?: '[unassigned]') ?></button>
                            </td>
                            <td
                                class="optional-data-col dob-col is-settings-hidden"
                                data-optional-column="dob"
                                data-sort-value="<?= e($skater['date_of_birth'] ?? '') ?>"
                            ><?= e($skater['date_of_birth'] ?: '—') ?></td>
                            <td
                                class="optional-data-col age-col is-settings-hidden"
                                data-optional-column="age"
                                data-sort-value="<?= e($skaterAge ?? '') ?>"
                            ><?= e($skaterAge ?? '—') ?></td>
                            <td
                                class="optional-data-col gender-col is-settings-hidden"
                                data-optional-column="gender"
                                data-sort-value="<?= e($skater['gender_name'] ?? '') ?>"
                            ><?= e($skater['gender_name'] ?: '—') ?></td>
                            <td
                                class="optional-data-col attendance-col is-settings-hidden"
                                data-optional-column="attendance"
                                title="Attendance recorded for this skater"
                            >
                                <?php foreach ($skater['season_views'] as $viewSeasonId => $seasonView): ?>
                                    <strong
                                        class="attendance-fraction"
                                        data-row-season-content="<?= e($viewSeasonId) ?>"
                                        data-sort-value="<?= e($seasonView["attendance"]["present"]) ?>"
                                        <?= $viewSeasonId === $filters['season_id'] ? '' : 'hidden' ?>
                                    >
                                        <?= e($seasonView['attendance']['present']) ?>/<?= e($seasonView['attendance']['recorded']) ?>
                                    </strong>
                                <?php endforeach; ?>
                            </td>
                            <td
                                class="optional-data-col groups-col is-settings-hidden"
                                data-optional-column="groups"
                            >
                                <?php foreach ($skater['season_views'] as $viewSeasonId => $seasonView): ?>
                                    <span
                                        class="group-dot-row"
                                        data-row-season-content="<?= e($viewSeasonId) ?>"
                                        <?= $viewSeasonId === $filters['season_id'] ? '' : 'hidden' ?>
                                    >
                                        <?php if ($seasonView['current_sessions'] !== []): ?>
                                            <?php foreach ($seasonView['current_sessions'] as $session): ?>
                                                <?php
                                                $groupLabel = $session['group_name'] ?: 'Group unassigned';
                                                $sessionTooltip = $session['session_name'];
                                                ?>
                                                <svg
                                                    class="group-status-dot <?= $session['group_id'] === null ? 'is-unassigned' : '' ?>"
                                                    data-group-dot
                                                    data-session-id="<?= e($session['session_id']) ?>"
                                                    data-group-id="<?= e($session['group_id'] ?? '') ?>"
                                                    data-group-name="<?= e($groupLabel) ?>"
                                                    data-session-info="<?= e($sessionTooltip) ?>"
                                                    viewBox="0 0 16 16"
                                                    aria-label="<?= e($groupLabel . ': ' . $sessionTooltip) ?>"
                                                    aria-expanded="false"
                                                    role="button"
                                                    tabindex="0"
                                                >
                                                    <circle
                                                        cx="8"
                                                        cy="8"
                                                        r="6"
                                                        fill="<?= e($session['group_colour'] ?: 'transparent') ?>"
                                                    ></circle>
                                                    <path class="group-unassigned-mark" d="M4 4 12 12"></path>
                                                </svg>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="optional-empty" aria-label="No current or upcoming registered groups">—</span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </td>
                            <td
                                class="optional-data-col notes-col is-settings-hidden"
                                data-optional-column="notes"
                                data-sort-value="<?= (trim((string) ($skater['general_notes'] ?? '')) !== '' || trim((string) ($skater['medical_notes'] ?? '')) !== '') ? '1' : '0' ?>"
                            >
                                <?php
                                $hasGeneralNotes = trim((string) ($skater['general_notes'] ?? '')) !== '';
                                $hasMedicalNotes = trim((string) ($skater['medical_notes'] ?? '')) !== '';
                                ?>
                                <?php if ($hasGeneralNotes || $hasMedicalNotes): ?>
                                    <span class="dashboard-note-icons">
                                        <?php if ($hasGeneralNotes): ?>
                                            <svg class="dashboard-general-note-icon" viewBox="0 0 20 20" role="img" aria-label="General note" title="General note">
                                                <path d="M3 2.5h10.8L17 5.7v11.8H3z"></path>
                                                <path d="M13.8 2.5v3.3H17"></path>
                                                <path d="M6 9h8M6 12h6" class="dashboard-general-note-lines"></path>
                                            </svg>
                                        <?php endif; ?>
                                        <?php if ($hasMedicalNotes): ?>
                                            <img
                                                class="dashboard-medical-icon"
                                                src="<?= e(asset('medical-note-icon.png')) ?>"
                                                alt="Medical or accommodation note"
                                                title="Medical or accommodation note"
                                            >
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="optional-empty" aria-label="No notes">—</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($stages as $stage): ?>
                                <?php
                                $stageAchieved = 0;
                                $stageRibbonAwardedCount = 0;
                                $stageRibbonProgress = [];
                                foreach ($stage['ribbons'] as $stageRibbon) {
                                    $stageRibbonAchieved = 0;
                                    foreach ($stageRibbon['skills'] as $stageSkill) {
                                        if (isset($skater['skills'][$stageSkill['id']])) {
                                            if (!empty($stageRibbon['is_participation_ribbon'])
                                                ? !empty($stageSkill['is_participation'])
                                                : empty($stageSkill['is_participation'])) {
                                                $stageAchieved++;
                                                $stageRibbonAchieved++;
                                            }
                                        }
                                    }
                                    $stageRibbonTotal = !empty($stageRibbon['is_participation_ribbon'])
                                        ? 1
                                        : count($stageRibbon['skills']);
                                    $stageRibbonRequired = (int) ($stageRibbon['required_count'] ?? $stageRibbonTotal);
                                    $stageRibbonProgress[$stageRibbon['id']] = [
                                        'achieved' => $stageRibbonAchieved,
                                        'total' => $stageRibbonTotal,
                                        'required' => $stageRibbonRequired,
                                        'complete' => $stageRibbonRequired > 0 && $stageRibbonAchieved >= $stageRibbonRequired,
                                    ];
                                    if (isset($skater['ribbons'][$stageRibbon['id']])) {
                                        $stageRibbonAwardedCount++;
                                    }
                                }
                                $stageRequiredRibbonCount = (int) ($stage['required_ribbon_count'] ?? count($stage['ribbons']));
                                $stageComplete = $stageRequiredRibbonCount > 0
                                    && $stageRibbonAwardedCount >= $stageRequiredRibbonCount;
                                $stageBadgeAwardedAt = $skater['badges'][$stage['id']] ?? null;
                                $stageBadgeAwarded = $stageBadgeAwardedAt !== null;
                                $stageBadgeName = $stage['name'] . ' badge';
                                $stageBadgeTitle = $stageBadgeAwarded
                                    ? $stageBadgeName . ' given to ' . $skater['first_name'] . ' ' . $skater['last_name'] . '. Select to remove.'
                                    : ($stageComplete
                                        ? 'Record ' . $stageBadgeName . ' as given to ' . $skater['first_name'] . ' ' . $skater['last_name'] . '.'
                                        : 'Award all ' . $stageRequiredRibbonCount . ' fundamental-area ribbons before recording this badge.');
                                ?>
                                <td
                                    class="progress-cell stage-summary-col <?= (int) $stage['number'] === 0 ? 'stage-pre-canskate' : '' ?>"
                                    data-stage-summary="<?= e($stage['id']) ?>"
                                    data-stage-column="<?= e($stage['id']) ?>"
                                                    data-sort-value="<?= e($stageRibbonAwardedCount) ?>"
                                >
                                    <span class="stage-total">
                                        <span class="stage-detail-controls">
                                            <?php if (!empty($stage['has_badge'])): ?>
                                            <span class="stage-badge-stack">
                                                <button
                                                    class="stage-badge-button stage-award-control stage-badge-stage-<?= e($stage['number']) ?> <?= $stageComplete ? 'is-eligible' : '' ?> <?= $stageBadgeAwarded ? 'is-awarded' : '' ?>"
                                                    type="button"
                                                    data-award-control
                                                    data-award-type="badge"
                                                    data-award-prerequisite="ribbons"
                                                    data-award-id="<?= e($stage['id']) ?>"
                                                    data-stage-number="<?= e($stage['number']) ?>"
                                                    data-award-name="<?= e($stageBadgeName) ?>"
                                                    data-award-skater-id="<?= e($skater['public_id']) ?>"
                                                    data-award-skater-name="<?= e($skater['first_name'] . ' ' . $skater['last_name']) ?>"
                                                    data-awarded="<?= $stageBadgeAwarded ? 'true' : 'false' ?>"
                                                    data-awarded-at="<?= e($stageBadgeAwardedAt ?? '') ?>"
                                                    data-eligible="<?= $stageComplete ? 'true' : 'false' ?>"
                                                    data-can-edit="<?= $canEdit ? 'true' : 'false' ?>"
                                                    data-can-override="<?= $canEdit ? 'true' : 'false' ?>"
                                                    aria-label="<?= e($stageBadgeTitle) ?>"
                                                    title="<?= e($stageBadgeTitle) ?>"
                                                    <?= !$canEdit ? 'disabled' : '' ?>
                                                >
                                                    <?= skate_canada_badge_icon() ?>
                                                </button>
                                                <span class="progress-value" data-stage-progress="ribbon-awards"><?= e($stageRibbonAwardedCount) ?>/<?= e($stageRequiredRibbonCount) ?></span>
                                            </span>
                                            <?php endif; ?>
                                            <?php foreach ($stage['ribbons'] as $stageRibbon): ?>
                                                <?php
                                                $ribbonProgress = $stageRibbonProgress[$stageRibbon['id']];
                                                $ribbonLetter = strtoupper(substr((string) $stageRibbon['name'], 0, 1));
                                                $stageRibbonClass = ribbon_class((string) $stageRibbon['name']);
                                                $ribbonAwardedAt =
                                                    $skater['ribbons'][$stageRibbon['id']] ?? null;
                                                $ribbonAwarded = $ribbonAwardedAt !== null;
                                                $ribbonAwardName = $stage['name'] . ' ' . $stageRibbon['name'] . ' ribbon';
                                                $ribbonTitle = $ribbonAwarded
                                                    ? $ribbonAwardName . ' given to ' . $skater['first_name'] . ' ' . $skater['last_name'] . '. Select to remove.'
                                                    : ($ribbonProgress['complete']
                                                        ? 'Record ' . $ribbonAwardName . ' as given to ' . $skater['first_name'] . ' ' . $skater['last_name'] . '.'
                                                        : 'Complete ' . $ribbonProgress['required'] . ' of ' . $ribbonProgress['total'] . ' ' . $stageRibbon['name'] . ' skills before recording this ribbon.');
                                                ?>
                                                <button
                                                    class="ribbon-detail-button stage-ribbon-detail ribbon-award-control <?= e($stageRibbonClass) ?> <?= $ribbonProgress['complete'] ? 'is-eligible' : '' ?> <?= $ribbonAwarded ? 'is-awarded' : '' ?>"
                                                    type="button"
                                                    data-ribbon-indicator="<?= e($stageRibbon['id']) ?>"
                                                    data-award-control
                                                    data-award-type="ribbon"
                                                    data-award-prerequisite="skills"
                                                    data-award-id="<?= e($stageRibbon['id']) ?>"
                                                    data-award-name="<?= e($ribbonAwardName) ?>"
                                                    data-award-skater-id="<?= e($skater['public_id']) ?>"
                                                    data-award-skater-name="<?= e($skater['first_name'] . ' ' . $skater['last_name']) ?>"
                                                    data-awarded="<?= $ribbonAwarded ? 'true' : 'false' ?>"
                                                    data-awarded-at="<?= e($ribbonAwardedAt ?? '') ?>"
                                                    data-eligible="<?= $ribbonProgress['complete'] ? 'true' : 'false' ?>"
                                                    data-can-edit="<?= $canEdit ? 'true' : 'false' ?>"
                                                    data-can-override="<?= $canEdit ? 'true' : 'false' ?>"
                                                    aria-label="<?= e($ribbonTitle) ?>"
                                                    title="<?= e($ribbonTitle) ?>"
                                                    <?= !$canEdit ? 'disabled' : '' ?>
                                                >
                                                    <svg viewBox="0 0 24 28" aria-hidden="true">
                                                        <circle class="ribbon-icon-shape ribbon-icon-centre" cx="12" cy="8" r="7"></circle>
                                                        <path class="ribbon-icon-shape" d="M8 13.2 5.5 26 12 22.2 18.5 26 16 13.2"></path>
                                                        <text class="ribbon-icon-letter" x="12" y="10.7"><?= e($ribbonLetter) ?></text>
                                                    </svg>
                                                    <span class="ribbon-progress-value" data-required="<?= e($ribbonProgress['required']) ?>" aria-hidden="true">
                                                        <?= e($ribbonProgress['achieved']) ?>/<?= e($ribbonProgress['total']) ?>
                                                    </span>
                                                </button>
                                            <?php endforeach; ?>
                                        </span>
                                    </span>
                                </td>
                                <?php foreach ($stage['ribbons'] as $ribbon): ?>
                                    <?php
                                    $ribbonClass = ribbon_class((string) $ribbon['name']);
                                    ?>
                                    <?php foreach ($ribbon['skills'] as $skill): ?>
                                        <?php $achieved = isset($skater['skills'][$skill['id']]); ?>
                                        <td
                                            class="skill-status <?= e($ribbonClass) ?> <?= !empty($skill['is_participation']) ? 'is-participation' : '' ?> <?= $achieved ? 'is-achieved' : '' ?> hidden-column"
                                            data-stage-detail="<?= e($stage['id']) ?>"
                                            data-ribbon-skill="<?= e($ribbon['id']) ?>"
                                            title="<?= e($skill['name']) ?>: <?= $achieved ? 'Achieved' : 'Not yet achieved' ?>"
                                        >
                                            <?php if ($canEdit): ?>
                                                <button
                                                    class="skill-check-button"
                                                    type="button"
                                                    data-edit-skill
                                                    data-assessment-skater-id="<?= e($skater['public_id']) ?>"
                                                    data-skater-name="<?= e($skater['first_name'] . ' ' . $skater['last_name']) ?>"
                                                    data-skill-id="<?= e($skill['id']) ?>"
                                                    data-skill-name="<?= e($skill['name']) ?>"
                                                    data-achieved="<?= $achieved ? 'true' : 'false' ?>"
                                                    aria-label="<?= e(
                                                        ($achieved ? 'Remove achievement for ' : 'Mark achieved: ')
                                                        . $skill['name']
                                                        . ' — '
                                                        . $skater['first_name']
                                                        . ' '
                                                        . $skater['last_name']
                                                    ) ?>"
                                                ><span><?= $achieved ? '✓' : '' ?></span></button>
                                            <?php else: ?>
                                                <span><?= $achieved ? '✓' : '' ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="roster-pagination" aria-label="Skater list pagination">
                <label class="page-size-control">
                    <span>Skaters per page</span>
                    <select data-page-size aria-label="Skaters per page">
                        <?php foreach ($pagination['page_sizes'] as $pageSizeOption): ?>
                            <option value="<?= e($pageSizeOption) ?>" <?= $pagination['page_size'] === $pageSizeOption ? 'selected' : '' ?>><?= e($pageSizeOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="pagination-summary">
                    <?= e($firstShown) ?>–<?= e($lastShown) ?> of <?= e($pagination['total']) ?> skaters
                </span>
                <?php if ($pagination['page_count'] > 1): ?>
                    <nav id="skater-pagination" class="pagination-controls" aria-label="Skater list pages">
                        <?php if ($pagination['page'] > 1): ?>
                            <a class="button button-ghost" data-pagination-link href="<?= e($paginationUrl($pagination['page'] - 1)) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="pagination-page">Page <?= e($pagination['page']) ?> of <?= e($pagination['page_count']) ?></span>
                        <?php if ($pagination['page'] < $pagination['page_count']): ?>
                            <a class="button button-ghost" data-pagination-link href="<?= e($paginationUrl($pagination['page'] + 1)) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>

    <div class="drawer-backdrop" data-close-drawer hidden></div>
    <aside class="detail-drawer" id="skater-drawer" aria-labelledby="drawer-title" hidden>
        <div class="drawer-head">
            <div><h2 id="drawer-title">Skater record</h2></div>
            <button class="icon-button" type="button" data-close-drawer aria-label="Close details">×</button>
        </div>
        <div class="drawer-body" data-drawer-content>
            <div class="loading-state"><span></span><p>Loading skater details…</p></div>
        </div>
    </aside>

    <div class="drawer-backdrop settings-drawer-backdrop" data-close-progress-settings hidden></div>
    <aside
        class="detail-drawer settings-drawer"
        id="progress-settings-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="progress-settings-title"
        hidden
    >
        <div class="drawer-head">
            <div>
                <span class="eyebrow">Display preferences</span>
                <h2 id="progress-settings-title">Skater progress settings</h2>
            </div>
            <button
                class="icon-button"
                type="button"
                data-close-progress-settings
                aria-label="Close Skater progress settings"
            >×</button>
        </div>
        <div class="drawer-body settings-drawer-body">
            <p class="settings-intro">
                Choose which information appears in the skater grid. These preferences are saved for your account in this browser.
            </p>

            <fieldset class="settings-section">
                <legend>Additional columns</legend>
                <label class="setting-toggle">
                    <span>
                        <strong>Date of birth</strong>
                        <small>Show each skater’s stored date of birth.</small>
                    </span>
                    <input type="checkbox" data-setting-dob>
                </label>
                <label class="setting-toggle">
                    <span>
                        <strong>Age</strong>
                        <small>Calculate age from the skater’s date of birth and today’s date.</small>
                    </span>
                    <input type="checkbox" data-setting-age>
                </label>
                <label class="setting-toggle">
                    <span>
                        <strong>Gender</strong>
                        <small>Show each skater’s recorded gender.</small>
                    </span>
                    <input type="checkbox" data-setting-gender>
                </label>
                <label class="setting-toggle">
                    <span>
                        <strong>Attendance</strong>
                        <small>Show attended sessions over sessions with recorded attendance.</small>
                    </span>
                    <input type="checkbox" data-setting-attendance>
                </label>
                <label class="setting-toggle">
                    <span>
                        <strong>Colour groups</strong>
                        <small>Show group dots for sessions that are in progress or have not yet ended.</small>
                    </span>
                    <input type="checkbox" data-setting-groups>
                </label>
                <label class="setting-toggle">
                    <span>
                        <strong>Notes</strong>
                        <small>Show indicators for general and medical/accommodation notes.</small>
                    </span>
                    <input type="checkbox" data-setting-notes>
                </label>
            </fieldset>

            <button class="button button-ghost settings-reset-button" type="button" data-reset-progress-settings>
                Reset display defaults
            </button>
        </div>
    </aside>

    <?php if ($canEdit): ?>
        <dialog class="import-dialog group-assignment-dialog" id="group-assignment-dialog" data-assignment-url="<?= e(url('api/group-assignments')) ?>" aria-labelledby="group-assignment-title">
            <form method="dialog" class="dialog-close-form"><button class="icon-button" aria-label="Close group assignment">×</button></form>
            <span class="eyebrow">Colour group</span>
            <h2 id="group-assignment-title">Assign to group</h2>
            <p data-group-assignment-description>Select a colour group for the selected skaters.</p>
            <p class="group-assignment-session-warning" data-group-assignment-session-warning hidden>Select a single session first, then choose a colour group.</p>
            <div class="group-picker" data-group-picker>
                <?php foreach ($defaultGroupColours as $groupColour): ?>
                    <button class="group-picker-choice" type="button" data-assign-group-name="<?= e($groupColour['name']) ?>" data-assign-group-colour="<?= e($groupColour['hex']) ?>"><svg class="group-picker-swatch" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="<?= e($groupColour['hex']) ?>" stroke="#75849A" stroke-width="1.5"></circle></svg><?= e($groupColour['name']) ?></button>
                <?php endforeach; ?>
                <button class="group-picker-choice group-picker-none" type="button" data-assign-group-none="true"><svg class="group-picker-swatch" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="#ffffff" stroke="#75849A" stroke-width="1.5"></circle><path d="M4 16 16 4" stroke="#697789" stroke-width="2" stroke-linecap="round"></path></svg>No group</button>
            </div>
            <div class="form-message" data-group-assignment-message hidden></div>
        </dialog>
        <dialog class="import-dialog" id="skater-delete-dialog">
            <form method="dialog" class="dialog-close-form">
                <button class="icon-button" aria-label="Close deletion confirmation">×</button>
            </form>
            <h2 data-delete-dialog-title>Delete skaters</h2>
            <p data-delete-dialog-copy>These skater records will be removed from the live roster.</p>
            <form class="stacked-form" data-delete-skaters-form>
                <div class="removal-warning">
                    <strong>This cannot be undone from the application.</strong>
                </div>
                <label>
                    <span>Type <strong>delete</strong> to confirm</span>
                    <input name="confirmation" autocomplete="off" data-delete-confirmation>
                </label>
                <div class="form-message" data-delete-message hidden></div>
                <button class="button button-danger button-block" type="submit" data-confirm-delete-skaters disabled>Delete skaters</button>
            </form>
        </dialog>

        <dialog class="achievement-dialog achievement-editor-dialog" id="achievement-editor-dialog">
            <form method="dialog" class="dialog-close-form">
                <button class="icon-button" type="button" data-close-achievement-editor aria-label="Close achievements editor">×</button>
            </form>
            <h2 data-achievement-editor-title>Skater achievements</h2>
            <p class="achievement-editor-modal-copy">Select skills, ribbons, and badges. Changes save automatically.</p>
            <form class="achievement-editor-modal-form" data-achievement-editor-form>
                <div class="form-message achievement-editor-save-status" data-achievement-editor-message aria-live="polite" hidden></div>
                <div data-achievement-editor-content><div class="loading-state"><span></span><p>Loading achievements…</p></div></div>
                <div class="achievement-editor-modal-actions">
                    <button class="button" type="button" data-close-achievement-editor>Close</button>
                </div>
            </form>
        </dialog>

    <?php endif; ?>
</body>
</html>
