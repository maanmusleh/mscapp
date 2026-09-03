(() => {
    'use strict';

    const sidebar = document.querySelector('.sidebar');
    const navigation = sidebar?.querySelector('.primary-nav');
    const brand = sidebar?.querySelector('.sidebar-brand');
    if (!sidebar || !navigation || !brand) return;

    const menuId = navigation.id || 'site-navigation';
    navigation.id = menuId;

    const toggle = document.createElement('button');
    toggle.className = 'nav-toggle';
    toggle.type = 'button';
    toggle.setAttribute('aria-controls', menuId);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open main menu');
    toggle.innerHTML = '<span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>';
    brand.insertAdjacentElement('afterend', toggle);
    sidebar.classList.add('has-collapsible-nav');

    const setOpen = (open) => {
        sidebar.classList.toggle('is-nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close main menu' : 'Open main menu');
    };

    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    navigation.addEventListener('click', (event) => {
        if (event.target.closest('a')) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebar.classList.contains('is-nav-open')) {
            setOpen(false);
            toggle.focus();
        }
    });

    window.matchMedia('(min-width: 641px)').addEventListener('change', (event) => {
        if (event.matches) setOpen(false);
    });
})();
