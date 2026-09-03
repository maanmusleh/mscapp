(() => {
    'use strict';

    try {
        const params = new URLSearchParams(window.location.search);
        const scope = document.querySelector('meta[name="rink-state-scope"]')?.content || 'session';
        const scopeKey = 'rink-state-scope';
        if (window.sessionStorage.getItem(scopeKey) !== scope) {
            Object.keys(window.sessionStorage).forEach((key) => {
                if (key.startsWith('rink-activity-scroll:') || key.startsWith('rink-view-state:')) {
                    window.sessionStorage.removeItem(key);
                }
            });
            window.sessionStorage.setItem(scopeKey, scope);
        }
        const key = `rink-activity-scroll:${scope}:${Number(params.get('season_id') || 0)}:${Number(params.get('session_id') || 0)}`;
        if (window.sessionStorage.getItem(key)) {
            document.documentElement.classList.add('rink-restoring-activity');
        }
    } catch (error) {
        // The page remains usable when session storage is unavailable.
    }
})();
