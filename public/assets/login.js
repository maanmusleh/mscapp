(() => {
    'use strict';

    const password = document.querySelector('[data-login-password]');
    const toggle = document.querySelector('[data-login-password-toggle]');
    if (!password || !toggle) return;

    toggle.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        toggle.title = visible ? 'Hide password' : 'Show password';
        toggle.classList.toggle('is-visible', visible);
        password.focus({preventScroll: true});
    });
})();
