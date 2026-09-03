<?php
$stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
$validationFailed = ($result['status'] ?? '') === 'validation_failed';
$imported = ($result['status'] ?? '') === 'imported';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Historical data import · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="legacy-import-page">
    <main class="legacy-import-shell">
        <header class="legacy-import-header">
            <a href="<?= e(url('admin-tools#database-management')) ?>" class="legacy-import-brand" aria-label="Return to System Administration">
                <img src="<?= e(asset('cat-logo.png')) ?>" alt="">
                <span>CanSkate Achievement Tracker</span>
            </a>
            <a class="button button-ghost" href="<?= e(url('admin-tools#database-management')) ?>">Return to System Administration</a>
        </header>

        <section class="legacy-import-card">
            <span class="eyebrow">One-time setup</span>
            <h1>Historical data import</h1>
            <p class="legacy-import-intro">
                Import the skaters, season registrations, guardian phone numbers, and Stage 1–5 achievement dates from the historical CanSkate workbook.
            </p>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> legacy-import-alert" role="alert"><?= nl2br(e($flash['message'])) ?></div>
            <?php endforeach; ?>

            <div class="legacy-import-backup-warning" role="note">
                <div>
                    <strong>Back up the database before importing</strong>
                    <p>Save a current SQL backup in a secure location. The import is atomic, but a backup gives you a restore point if the source workbook contains unintended historical data.</p>
                </div>
                <form method="post" action="<?= e(url('admin-tools/backup')) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <button class="button button-secondary" type="submit">Download SQL backup</button>
                </form>
            </div>

            <div class="legacy-import-note">
                <strong>How the import works</strong>
                <ul>
                    <li>The first worksheet must use the template’s exact A–Z layout. Row 1 contains headers; data begins on row 2.</li>
                    <li><code>LAST</code>, <code>FIRST</code>, <code>DOB</code>, and <code>SESSION</code> are required. <code>M/F</code> accepts M, F, or blank. <code>PHONE #</code> is optional.</li>
                    <li>Achievement cells accept a date, <code>NP</code>, or blank. NP and blank both mean not achieved. The date represents only its year and month, so CAT always imports it as the final day of that month—for example, <code>2026-03-26</code> becomes <code>2026-03-31</code>.</li>
                    <li>A normalized month-end ribbon date checks every skill in that ribbon using the same date and records the ribbon award. A <code>_PASSED</code> value records the stage badge at month-end.</li>
                    <li>Every skater is enrolled in the selected season. A missing SESSION SKU is created in that season with a placeholder schedule that can be edited later.</li>
                    <li>The complete workbook and relevant CAT records are validated first. If any error is found, nothing is imported.</li>
                </ul>
            </div>

            <div class="legacy-import-template-row">
                <div><strong>Start from the supported workbook</strong><span>It includes the exact headers, date formatting, and Stage 1–5 column colours.</span></div>
                <a class="button button-secondary" href="<?= e(url('legacy-achievement-import/template')) ?>">Download Excel template</a>
            </div>

            <?php if ($seasons === []): ?>
                <div class="alert alert-error legacy-import-alert">Create a season before using this import.</div>
            <?php else: ?>
                <form method="post" action="<?= e(url('legacy-achievement-import')) ?>" enctype="multipart/form-data" class="stacked-form legacy-import-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        <span>Import every record into</span>
                        <select name="season_id" required>
                            <option value="">Select a season</option>
                            <?php foreach ($seasons as $season): ?>
                                <option value="<?= e($season['id']) ?>" <?= (int) $season['id'] === (int) $selectedSeasonId ? 'selected' : '' ?>>
                                    <?= e($season['name']) ?> · <?= e($season['start_date']) ?> to <?= e($season['end_date']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="file-drop">
                        <span>Choose the completed historical workbook</span>
                        <small>Excel .xlsx · maximum 10 MB · up to 10,000 data rows</small>
                        <input type="file" name="legacy_workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    <button class="button button-primary" type="submit">Validate and import</button>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($result !== null): ?>
            <section class="legacy-import-card legacy-import-results" aria-labelledby="legacy-import-results-title">
                <span class="eyebrow"><?= $validationFailed ? 'Import stopped' : 'Import complete' ?></span>
                <h2 id="legacy-import-results-title"><?= e($result['filename'] ?? 'Workbook') ?></h2>
                <p class="legacy-import-result-context">Selected season: <strong><?= e($result['season_name'] ?? '') ?></strong></p>

                <?php if ($validationFailed): ?>
                    <div class="alert alert-error legacy-import-alert" role="alert">
                        Validation found <?= e(number_format((int) ($result['error_count'] ?? 0))) ?> error<?= (int) ($result['error_count'] ?? 0) === 1 ? '' : 's' ?>. Nothing was imported. Correct every error in the workbook or CAT data, then submit the file again.
                    </div>
                    <div class="legacy-import-issues is-error">
                        <h3>Errors to correct</h3>
                        <ol><?php foreach (($result['errors'] ?? []) as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ol>
                    </div>
                <?php elseif ($imported): ?>
                    <div class="alert alert-success legacy-import-alert">All <?= e(number_format((int) ($stats['rows_imported'] ?? 0))) ?> workbook rows were imported successfully.</div>
                    <div class="legacy-import-stats">
                        <div><strong><?= e(number_format((int) ($stats['skaters_created'] ?? 0))) ?></strong><span>Skaters created</span></div>
                        <div><strong><?= e(number_format((int) ($stats['skaters_updated'] ?? 0))) ?></strong><span>Skaters reused</span></div>
                        <div><strong><?= e(number_format((int) ($stats['sessions_created'] ?? 0))) ?></strong><span>Sessions created</span></div>
                        <div><strong><?= e(number_format((int) ($stats['enrollments_recorded'] ?? 0))) ?></strong><span>Enrollments recorded</span></div>
                        <div><strong><?= e(number_format((int) ($stats['skills_added'] ?? 0))) ?></strong><span>Skills added</span></div>
                        <div><strong><?= e(number_format((int) ($stats['skill_dates_updated'] ?? 0))) ?></strong><span>Earlier skill dates applied</span></div>
                        <div><strong><?= e(number_format((int) ($stats['ribbons_recorded'] ?? 0))) ?></strong><span>Ribbon dates recorded</span></div>
                        <div><strong><?= e(number_format((int) ($stats['badges_recorded'] ?? 0))) ?></strong><span>Badge dates recorded</span></div>
                    </div>
                    <?php if ((int) ($stats['sessions_created'] ?? 0) > 0 || (int) ($stats['sessions_restored'] ?? 0) > 0): ?>
                        <p class="legacy-import-follow-up">Review newly created or restored sessions on the Registration page and replace their placeholder schedules.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <?php require __DIR__ . '/partials/site-footer.php'; ?>
    </main>
</body>
</html>
