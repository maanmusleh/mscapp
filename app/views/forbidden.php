<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard unavailable · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="message-page">
    <main class="message-card">
        <img src="<?= e(asset('cat-logo.png')) ?>" alt="CAT logo">
        <span class="eyebrow"><?= e($user['role_name']) ?> account</span>
        <h1>Your dashboard is coming later.</h1>
        <p>This workspace is restricted to administrators, editors, and read-only users.</p>
        <form method="post" action="<?= e(url('logout')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <button class="button button-primary" type="submit">Sign out</button>
        </form>
        <?php require __DIR__ . '/partials/site-footer.php'; ?>
    </main>
</body>
</html>
