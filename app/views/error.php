<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="message-page">
    <main class="message-card">
        <img src="<?= e(asset('cat-logo.png')) ?>" alt="CAT logo">
        <span class="eyebrow">CanSkate Achievement Tracker</span>
        <h1><?= e($title) ?></h1>
        <p><?= e($message) ?></p>
        <a class="button button-primary" href="<?= e($user ? url('dashboard') : url('login')) ?>">Return to CAT</a>
        <?php require __DIR__ . '/partials/site-footer.php'; ?>
    </main>
</body>
</html>
