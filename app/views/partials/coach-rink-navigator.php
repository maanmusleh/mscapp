<?php
$coachNavigatorSeasons = $coachNavigator['seasons'] ?? [];
$coachNavigatorSessions = $coachNavigator['sessions'] ?? [];
$coachNavigatorSeasonId = $coachNavigator['season_id'] ?? null;
$coachNavigatorSessionId = $coachNavigator['session_id'] ?? null;
$coachNavigatorReady = $coachNavigatorSeasonId !== null && $coachNavigatorSessionId !== null;
?>
<section class="coach-rink-navigator" aria-label="Season and session navigator" data-coach-rink-navigator>
    <form method="get" action="<?= e(url()) ?>">
        <input type="hidden" name="route" value="rink-app">
        <label><span>Season</span><select name="season_id" data-rink-season required>
            <option value="">Choose a season</option>
            <?php foreach ($coachNavigatorSeasons as $season): ?>
                <option value="<?= e($season['id']) ?>" <?= $coachNavigatorSeasonId === $season['id'] ? 'selected' : '' ?>><?= e($season['name']) ?><?= date('Y-m-d') >= $season['start_date'] && date('Y-m-d') <= $season['end_date'] ? ' · Current' : '' ?></option>
            <?php endforeach; ?>
        </select></label>
        <label><span>Session</span><select name="session_id" data-rink-session required>
            <option value="">Choose a session</option>
            <?php foreach ($coachNavigatorSessions as $session): ?>
                <?php $sessionAvailable = $coachNavigatorSeasonId !== null && $session['season_id'] === $coachNavigatorSeasonId; ?>
                <option value="<?= e($session['id']) ?>" data-season-id="<?= e($session['season_id']) ?>" data-session-day="<?= e($session['day_of_week'] ?? '') ?>" data-session-start="<?= e($session['start_time'] ?? '') ?>" data-session-end="<?= e($session['end_time'] ?? '') ?>" data-session-location="<?= e($session['location'] ?? '') ?>" <?= $coachNavigatorSessionId === $session['id'] ? 'selected' : '' ?> <?= $sessionAvailable ? '' : 'hidden disabled' ?>><?= e($session['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="coach-rink-go" type="submit" data-coach-rink-go <?= $coachNavigatorReady ? '' : 'disabled' ?>>Go</button>
        <span class="coach-rink-session-summary" data-coach-rink-session-summary hidden></span>
    </form>
</section>
