<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Sign in · CAT</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="login-page">
<?php $totpRequired = $totpRequired ?? false; ?>
    <main class="login-shell">
        <section class="login-brand" aria-labelledby="brand-title">
            <div class="login-brand-copy">
                <span class="eyebrow">CanSkate Achievement Tracker</span>
                <h1 id="brand-title">Every skater’s progress, clear at a glance.</h1>
                <p>Keep registrations, attendance, groups, and CanSkate achievements together in one secure club workspace.</p>
            </div>
            <img class="login-mascot" src="<?= e(asset('cat-logo.png')) ?>" alt="CAT skating cat logo">
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-card">
                <div class="mobile-logo">
                    <img src="<?= e(asset('cat-logo.png')) ?>" alt="">
                    <span>CanSkate Achievement Tracker</span>
                </div>
                <span class="eyebrow">Club administration</span>
                <h2 id="login-title"><?= $totpRequired ? 'Enter your code' : 'Welcome back' ?></h2>
                <p class="muted"><?= $totpRequired ? 'Enter the current six-digit code from your authenticator app.' : 'Sign in to your CAT workspace.' ?></p>

                <?php foreach ($flashes as $flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endforeach; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if ($totpRequired): ?>
                <form method="post" action="<?= e(url('login/totp')) ?>" class="stacked-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <label>
                        <span>Authenticator code</span>
                        <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                    </label>
                    <button type="submit" class="button button-primary button-block">Verify code</button>
                </form>
                <form method="post" action="<?= e(url('login/totp/cancel')) ?>" class="login-totp-cancel"><button type="submit">Use a different account</button></form>
                <?php else: ?>
                <form method="post" action="<?= e(url('login')) ?>" class="stacked-form">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

                    <label>
                        <span>Email address / user ID</span>
                        <input
                            type="text"
                            name="identity"
                            value="<?= e($old['identity']) ?>"
                            placeholder="you@example.ca"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </label>

                    <div class="login-password-control">
                        <label for="login-password">Password</label>
                        <span class="login-password-field">
                            <input
                                id="login-password"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                required
                                data-login-password
                            >
                            <button type="button" data-login-password-toggle aria-label="Show password" aria-pressed="false" title="Show password">
                                <svg class="login-password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.7"></circle></svg>
                                <svg class="login-password-eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3 21 21"></path><path d="M10.6 6.1A10 10 0 0 1 12 6c6 0 9.5 6 9.5 6a15 15 0 0 1-2.6 3.2M6.2 6.2C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a9.7 9.7 0 0 0 3.1-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>
                            </button>
                        </span>
                    </div>

                    <button type="submit" class="button button-primary button-block">Sign in to CAT</button>
                </form>
                <?php endif; ?>

                <p class="login-help">Need access? Contact your club administrator.</p>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/partials/site-footer.php'; ?>
    <script src="<?= e(asset('login.js')) ?>"></script>
</body>
</html>
