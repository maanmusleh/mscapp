(() => {
    'use strict';

    document.querySelectorAll('[data-coach-rink-navigator]').forEach((navigator) => {
        const form = navigator.querySelector('form');
        const season = navigator.querySelector('[data-rink-season]');
        const session = navigator.querySelector('[data-rink-session]');
        const go = navigator.querySelector('[data-coach-rink-go]');
        const summary = navigator.querySelector('[data-coach-rink-session-summary]');
        if (!form || !season || !session || !go || !summary) return;

        const sessionOptions = Array.from(session.options).filter((option) => option.dataset.seasonId);
        const days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        const formatTime = (value) => {
            const match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
            if (!match) return '';
            const hour = Number(match[1]);
            return `${hour % 12 || 12}:${match[2]} ${hour >= 12 ? 'pm' : 'am'}`;
        };
        const updateSessionSummary = () => {
            const option = session.selectedOptions[0];
            if (!session.value || !option?.dataset.seasonId) {
                summary.hidden = true;
                summary.textContent = '';
                return;
            }
            const day = days[Number(option.dataset.sessionDay || 0)] || '';
            const start = formatTime(option.dataset.sessionStart);
            const end = formatTime(option.dataset.sessionEnd);
            const time = start && end ? `${start}–${end}` : (start || end);
            const location = String(option.dataset.sessionLocation || '').trim();
            summary.textContent = [day, time, location].filter(Boolean).join(' · ');
            summary.hidden = !summary.textContent;
        };
        const updateWorkspaceContext = () => {
            if (!document.body.classList.contains('rink-app-page')) return;
            const pending = !season.value
                || !session.value
                || season.value !== String(document.body.dataset.seasonId || '')
                || session.value !== String(document.body.dataset.sessionId || '');
            document.body.classList.toggle('is-coach-context-pending', pending);
            const main = document.querySelector('.rink-main');
            if (main) main.inert = pending;
        };
        const updateGoState = () => {
            go.disabled = !(season.value && session.value);
            updateSessionSummary();
            updateWorkspaceContext();
        };
        const filterSessions = (resetSelection = false) => {
            const seasonId = String(season.value || '');
            sessionOptions.forEach((option) => {
                const available = Boolean(seasonId) && option.dataset.seasonId === seasonId;
                option.hidden = !available;
                option.disabled = !available;
            });
            const selectedOption = session.selectedOptions[0];
            if (resetSelection || (selectedOption?.dataset.seasonId && selectedOption.dataset.seasonId !== seasonId)) {
                session.value = '';
            }
            updateGoState();
        };

        season.addEventListener('change', () => filterSessions(true));
        session.addEventListener('change', updateGoState);
        form.addEventListener('submit', (event) => {
            updateGoState();
            if (go.disabled) event.preventDefault();
        });
        filterSessions(false);
    });
})();
