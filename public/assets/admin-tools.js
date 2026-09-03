(() => {
    const restoreForm = document.querySelector('.database-restore-form');
    if (!restoreForm) return;

    const submitButton = restoreForm.querySelector('[data-database-restore-submit]');
    const progress = document.querySelector('[data-database-restore-progress]');
    let restoring = false;

    restoreForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (restoring) return;
        restoring = true;

        restoreForm.classList.add('is-restoring');
        restoreForm.setAttribute('aria-busy', 'true');
        document.title = 'Restoring database… · CAT';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Restoring…';
        }
        if (progress) progress.hidden = false;

        // Give the browser a chance to paint the busy state before the upload
        // and synchronous server-side restore begin.
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => HTMLFormElement.prototype.submit.call(restoreForm));
        });
    });
})();
