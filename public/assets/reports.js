(() => {
    const form = document.querySelector('[data-achievement-export-form]');
    if (!form) return;

    const season = form.querySelector('[data-export-season]');
    const session = form.querySelector('[data-export-session]');
    const group = form.querySelector('[data-export-group]');
    if (!season || !session || !group) return;

    const updateGroups = () => {
        const seasonId = season.value;
        const sessionId = session.value;
        let selectedGroupAvailable = group.value === '';
        Array.from(group.options).forEach((option) => {
            if (option.value === '') return;
            const available = option.dataset.seasonId === seasonId
                && (sessionId === '' || option.dataset.sessionId === sessionId);
            option.hidden = !available;
            option.disabled = !available;
            if (available && option.selected) selectedGroupAvailable = true;
        });
        if (!selectedGroupAvailable) group.value = '';
    };

    const updateSessions = () => {
        const seasonId = season.value;
        let selectedSessionAvailable = session.value === '';
        Array.from(session.options).forEach((option) => {
            if (option.value === '') return;
            const available = option.dataset.seasonId === seasonId;
            option.hidden = !available;
            option.disabled = !available;
            if (available && option.selected) selectedSessionAvailable = true;
        });
        if (!selectedSessionAvailable) session.value = '';
        updateGroups();
    };

    season.addEventListener('change', updateSessions);
    session.addEventListener('change', updateGroups);
    updateSessions();
})();
