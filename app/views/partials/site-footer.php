<footer class="site-footer">
    <span>CanSkate Achievement Tracker. © <?= e(date('Y')) ?> P. E. Mallet. Provided as-is, without warranty; use at your own risk.</span>
    <span>Please verify your data and keep backups secure. CanSkate is a trademark of Skate Canada.</span>
</footer>
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
<script src="<?= e(asset('common-dialog.js')) ?>"></script>
