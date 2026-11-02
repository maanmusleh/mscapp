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

    let pendingOrderSaves = 0;
    document.querySelectorAll('[data-order-list]').forEach((list) => {
        const resource = list.dataset.orderList;
        const status = document.querySelector(`[data-order-status="${resource}"]`);
        const rows = () => Array.from(list.querySelectorAll(':scope > [data-order-item]'));
        const order = () => rows().map((row) => row.dataset.orderItem);
        let saved = order();
        let busy = false;
        let drag = null;
        let scrollFrame = 0;
        let suppressClickUntil = 0;
        const handles = () => list.querySelectorAll('[data-order-handle]');
        const restore = () => saved.forEach((id) => {
            const row = rows().find((item) => item.dataset.orderItem === id);
            if (row) list.append(row);
        });
        const announce = (message, error = false) => {
            status.textContent = message;
            status.classList.toggle('is-error', error);
        };
        const syncMenus = (ids) => {
            // Reorder existing options without replacing them or changing selections.
            const selector = resource === 'seasons' ? 'select[name="season_id"]'
                : resource === 'rinks' ? 'select[name="location"]' : null;
            if (!selector) return;
            document.querySelectorAll(selector).forEach((select) => {
                const selected = select.value;
                ids.forEach((id) => {
                    const option = Array.from(select.options).find((item) => item.value === id);
                    if (option) select.append(option);
                });
                select.value = selected;
            });
        };
        const persist = async () => {
            const next = order();
            if (JSON.stringify(next) === JSON.stringify(saved)) return;
            const focusedHandle = document.activeElement?.matches('[data-order-handle]')
                && list.contains(document.activeElement) ? document.activeElement : null;
            busy = true;
            pendingOrderSaves++;
            handles().forEach((handle) => { handle.disabled = true; });
            list.setAttribute('aria-busy', 'true');
            announce('Saving order…');
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 15000);
            try {
                const response = await fetch(list.dataset.orderUrl, {
                    method: 'POST', credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': list.dataset.orderToken},
                    body: JSON.stringify({resource, order: next, expected: saved, season_id: list.dataset.seasonId || null}),
                });
                const result = await response.json().catch(() => ({error: 'Your session may have expired. Refresh and try again.'}));
                if (!response.ok || typeof result.message !== 'string') throw new Error(result.error || 'Unable to save order. Refresh and try again.');
                saved = next;
                syncMenus(saved);
                announce('Order saved');
            } catch (error) {
                restore();
                announce(error instanceof TypeError || error.name === 'AbortError'
                    ? 'Could not confirm the saved order. Refresh before trying again.'
                    : error.message || 'Could not confirm the saved order. Refresh before trying again.', true);
            } finally {
                window.clearTimeout(timeout);
                busy = false;
                pendingOrderSaves--;
                handles().forEach((handle) => { handle.disabled = false; });
                list.removeAttribute('aria-busy');
                if (focusedHandle && document.activeElement === document.body) focusedHandle.focus({preventScroll: true});
            }
        };
        // Preserve row edits while moving the existing form nodes.
        const move = (row, destination) => {
            const items = rows();
            const index = items.indexOf(row);
            const target = Math.max(0, Math.min(items.length - 1, destination));
            if (target === index) return;
            if (target < index) list.insertBefore(row, items[target]);
            else list.insertBefore(row, items[target].nextSibling);
            row.querySelector('[data-order-handle]').focus({preventScroll: true});
            void persist();
        };
        const menu = document.createElement('div');
        menu.className = 'schedule-order-actions';
        menu.setAttribute('popover', 'auto');
        menu.innerHTML = '<button type="button" data-move="-1">Move up</button><button type="button" data-move="1">Move down</button>';
        document.body.append(menu);
        let menuRow = null;
        menu.addEventListener('click', (event) => {
            const button = event.target.closest('[data-move]');
            if (!button || !menuRow || busy) return;
            menu.hidePopover();
            move(menuRow, rows().indexOf(menuRow) + Number(button.dataset.move));
        });
        const openMenu = (handle) => {
            menuRow = handle.closest('[data-order-item]');
            const index = rows().indexOf(menuRow);
            menu.querySelector('[data-move="-1"]').disabled = index === 0;
            menu.querySelector('[data-move="1"]').disabled = index === rows().length - 1;
            const rect = handle.getBoundingClientRect();
            menu.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 148))}px`;
            menu.style.top = `${Math.min(rect.bottom + 4, window.innerHeight - 100)}px`;
            menu.showPopover();
        };
        list.addEventListener('click', (event) => {
            const handle = event.target.closest('[data-order-handle]');
            if (handle && !busy && performance.now() >= suppressClickUntil) openMenu(handle);
        });
        list.addEventListener('keydown', (event) => {
            const handle = event.target.closest('[data-order-handle]');
            if (!handle || busy || drag) return;
            const row = handle.closest('[data-order-item]');
            const index = rows().indexOf(row);
            const destinations = {ArrowUp: index - 1, ArrowDown: index + 1, Home: 0, End: rows().length - 1};
            if (Object.hasOwn(destinations, event.key)) {
                event.preventDefault();
                move(row, destinations[event.key]);
            }
        });
        const placeDraggedRow = () => {
            const before = rows().find((row) => row !== drag.row && drag.y < row.getBoundingClientRect().top + row.getBoundingClientRect().height / 2);
            if (before) list.insertBefore(drag.row, before);
            else list.append(drag.row);
        };
        const autoScroll = () => {
            if (!drag?.started) return;
            const edge = 70;
            const delta = drag.y < edge ? -12 : drag.y > window.innerHeight - edge ? 12 : 0;
            if (delta) {
                window.scrollBy(0, delta);
                placeDraggedRow();
            }
            scrollFrame = requestAnimationFrame(autoScroll);
        };
        const finishDrag = (cancel = false) => {
            if (!drag) return;
            const {row, pointerId, started} = drag;
            drag = null;
            cancelAnimationFrame(scrollFrame);
            row.classList.remove('is-dragging');
            if (list.hasPointerCapture(pointerId)) list.releasePointerCapture(pointerId);
            if (!started) return;
            suppressClickUntil = performance.now() + 300;
            row.querySelector('[data-order-handle]').focus({preventScroll: true});
            if (cancel) {
                restore();
                row.querySelector('[data-order-handle]').focus({preventScroll: true});
                announce('Reorder cancelled');
            } else void persist();
        };
        list.addEventListener('pointerdown', (event) => {
            const handle = event.target.closest('[data-order-handle]');
            if (!handle || busy || drag || !event.isPrimary || event.button !== 0) return;
            drag = {row: handle.closest('[data-order-item]'), pointerId: event.pointerId, startY: event.clientY, y: event.clientY, started: false};
            handle.setPointerCapture(event.pointerId);
        });
        list.addEventListener('pointermove', (event) => {
            if (!drag || drag.pointerId !== event.pointerId) return;
            drag.y = event.clientY;
            if (!drag.started && Math.abs(event.clientY - drag.startY) < 5) return;
            event.preventDefault();
            if (!drag.started) {
                drag.started = true;
                list.setPointerCapture(event.pointerId);
                drag.row.classList.add('is-dragging');
                announce('Moving item…');
                autoScroll();
            }
            placeDraggedRow();
        });
        list.addEventListener('pointerup', () => finishDrag());
        list.addEventListener('pointercancel', () => finishDrag(true));
        list.addEventListener('lostpointercapture', (event) => {
            if (event.target === list) finishDrag(true);
        });
        document.addEventListener('keydown', (event) => {
            if (drag && event.key === 'Escape') { event.preventDefault(); finishDrag(true); }
        });
        list.closest('.schedule-admin').addEventListener('submit', (event) => {
            if (busy || drag) {
                event.preventDefault();
                announce('Wait for the order to finish saving before submitting changes.');
            }
        }, true);
    });
    window.addEventListener('beforeunload', (event) => {
        if (!pendingOrderSaves) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.querySelectorAll('[data-group-coaches-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.groupCoachesOpen || '');
            if (dialog instanceof HTMLDialogElement) dialog.showModal();
        });
    });

    document.querySelectorAll('[data-group-coaches-close]').forEach((button) => {
        button.addEventListener('click', () => {
            button.closest('dialog')?.close();
        });
    });

    document.querySelectorAll('.group-coaches-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
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
