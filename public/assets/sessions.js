(() => {
    'use strict';

    const scrollKey = `cat:sessions-scroll:${window.location.pathname}`;
    const detailsKey = `cat:sessions-details:${window.location.pathname}`;
    const sessionDraftKey = `cat:sessions-drafts:${window.location.pathname}${window.location.search}`;

    const sessionForms = Array.from(document.querySelectorAll(
        'form.session-row[data-preserve-scroll]'
    ));

    const editableControls = (form) => Array.from(form.querySelectorAll(
        'input[name]:not([type="hidden"]), select[name]'
    ));

    const controlValue = (control) => control.type === 'checkbox'
        ? control.checked
        : control.value;

    const formKey = (form) => {
        const id = form.querySelector('input[name="id"]')?.value;
        return id ? `session:${id}` : 'new-session';
    };

    const snapshot = (form) => Object.fromEntries(editableControls(form).map((control) => [
        control.name,
        controlValue(control),
    ]));

    const sameValue = (left, right) => String(left) === String(right);

    let sessionDrafts = {};
    try {
        const storedDrafts = window.sessionStorage.getItem(sessionDraftKey);
        sessionDrafts = storedDrafts ? JSON.parse(storedDrafts) : {};
    } catch (error) {
        sessionDrafts = {};
    }
    if (!sessionDrafts || typeof sessionDrafts !== 'object') {
        sessionDrafts = {};
    }

    const baselines = new Map();

    const saveDrafts = () => {
        if (Object.keys(sessionDrafts).length === 0) {
            window.sessionStorage.removeItem(sessionDraftKey);
            return;
        }
        window.sessionStorage.setItem(sessionDraftKey, JSON.stringify(sessionDrafts));
    };

    const updateDraft = (form) => {
        const baseline = baselines.get(form) || {};
        const values = snapshot(form);
        let changed = false;

        editableControls(form).forEach((control) => {
            const isChanged = !sameValue(values[control.name], baseline[control.name]);
            control.classList.toggle('is-unsaved', isChanged);
            changed = changed || isChanged;
        });

        const key = formKey(form);
        if (changed) {
            sessionDrafts[key] = values;
        } else {
            delete sessionDrafts[key];
        }
        saveDrafts();
    };

    sessionForms.forEach((form) => {
        const baseline = snapshot(form);
        baselines.set(form, baseline);

        const draft = sessionDrafts[formKey(form)];
        if (draft && typeof draft === 'object') {
            editableControls(form).forEach((control) => {
                if (!Object.prototype.hasOwnProperty.call(draft, control.name)) {
                    return;
                }
                if (control.type === 'checkbox') {
                    control.checked = Boolean(draft[control.name]);
                } else {
                    control.value = String(draft[control.name]);
                }
            });
        }

        updateDraft(form);
        form.addEventListener('input', () => updateDraft(form));
        form.addEventListener('change', () => updateDraft(form));
        form.addEventListener('submit', () => {
            sessionForms.forEach(updateDraft);
            if (formKey(form) === 'new-session') {
                delete sessionDrafts['new-session'];
                saveDrafts();
            }
        });
    });

    document.querySelectorAll('[data-preserve-scroll]').forEach((form) => {
        form.addEventListener('submit', () => {
            window.sessionStorage.setItem(scrollKey, String(window.scrollY));
            const details = form.closest('details[data-preserve-details]');
            if (details) {
                window.sessionStorage.setItem(detailsKey, details.dataset.preserveDetails);
            } else {
                window.sessionStorage.removeItem(detailsKey);
            }
        });
    });

    const savedDetails = window.sessionStorage.getItem(detailsKey);
    if (savedDetails !== null) {
        window.sessionStorage.removeItem(detailsKey);
        document.querySelector(`details[data-preserve-details="${savedDetails}"]`)?.setAttribute('open', '');
    }

    const savedScrollPosition = window.sessionStorage.getItem(scrollKey);
    if (savedScrollPosition !== null) {
        window.sessionStorage.removeItem(scrollKey);
        const scrollPosition = Number(savedScrollPosition);
        if (Number.isFinite(scrollPosition)) {
            window.requestAnimationFrame(() => {
                window.requestAnimationFrame(() => window.scrollTo(0, scrollPosition));
            });
        }
    }

    document.querySelectorAll('[data-season-session-select]').forEach((select) => {
        select.addEventListener('change', () => {
            window.sessionStorage.setItem(scrollKey, String(window.scrollY));
            const details = select.closest('details[data-preserve-details]');
            if (details) {
                window.sessionStorage.setItem(detailsKey, details.dataset.preserveDetails);
            }
            select.form?.requestSubmit();
        });
    });

    document.querySelectorAll('.file-drop input[type="file"]').forEach((input) => {
        input.addEventListener('change', () => {
            const label = input.closest('.file-drop');
            const title = label?.querySelector('span');
            if (title && input.files?.[0]) {
                title.textContent = input.files[0].name;
            }
        });
    });

})();
