(() => {
    'use strict';

    const dialog = document.querySelector('[data-app-confirm-dialog]');
    if (!dialog) return;

    const eyebrow = dialog.querySelector('[data-app-confirm-eyebrow]');
    const title = dialog.querySelector('[data-app-confirm-title]');
    const message = dialog.querySelector('[data-app-confirm-message]');
    const cancelButton = dialog.querySelector('[data-app-confirm-cancel]');
    const confirmButton = dialog.querySelector('[data-app-confirm-accept]');
    let resolveConfirmation = null;

    const settle = (confirmed, closeDialog = true) => {
        if (!resolveConfirmation) return;
        const resolve = resolveConfirmation;
        resolveConfirmation = null;
        if (closeDialog && dialog.open) dialog.close();
        resolve(confirmed);
    };

    window.CAT = window.CAT || {};
    window.CAT.confirm = (options = {}) => new Promise((resolve) => {
        if (resolveConfirmation) settle(false);
        const tone = options.tone === 'danger' ? 'danger' : 'default';
        eyebrow.textContent = options.eyebrow || 'Please confirm';
        title.textContent = options.title || 'Are you sure?';
        message.textContent = options.message || 'Please confirm that you want to continue.';
        cancelButton.textContent = options.cancelLabel || 'Cancel';
        confirmButton.textContent = options.confirmLabel || 'Continue';
        confirmButton.classList.toggle('button-danger', tone === 'danger');
        confirmButton.classList.toggle('button-primary', tone !== 'danger');
        dialog.dataset.tone = tone;
        resolveConfirmation = resolve;
        dialog.showModal();
        window.requestAnimationFrame(() => cancelButton.focus());
    });

    cancelButton.addEventListener('click', () => settle(false));
    confirmButton.addEventListener('click', () => settle(true));
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        settle(false);
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) settle(false);
    });
    dialog.addEventListener('close', () => {
        if (!dialog.open) settle(false, false);
    });
})();
