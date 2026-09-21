(() => {
    'use strict';

    const applicationGeneration = (Number(window.__catApplicationGeneration) || 0) + 1;
    window.__catApplicationGeneration = applicationGeneration;
    window.__catApplicationEventController?.abort();
    const applicationEventController = new AbortController();
    window.__catApplicationEventController = applicationEventController;
    const isCurrentApplicationGeneration = () => (
        window.__catApplicationGeneration === applicationGeneration
    );

    const mainSheetScroll = document.querySelector('[data-sheet-scroll-main]');
    const topSheetScroll = document.querySelector('[data-sheet-scroll-top]');
    const topSheetScrollTrack = document.querySelector('[data-sheet-scroll-top-track]');
    const skaterSheet = mainSheetScroll?.querySelector('.skater-sheet');
    let syncingSheetScroll = false;

    const syncTopSheetScrollbar = () => {
        if (!mainSheetScroll || !topSheetScroll || !topSheetScrollTrack || !skaterSheet) return;
        const hasHorizontalOverflow = mainSheetScroll.scrollWidth > mainSheetScroll.clientWidth;
        topSheetScroll.hidden = !hasHorizontalOverflow;
        if (!hasHorizontalOverflow) return;
        topSheetScrollTrack.style.width = `${mainSheetScroll.scrollWidth}px`;
        topSheetScroll.scrollLeft = mainSheetScroll.scrollLeft;
    };

    const scheduleTopSheetScrollbarSync = () => requestAnimationFrame(syncTopSheetScrollbar);

    if (mainSheetScroll && topSheetScroll) {
        mainSheetScroll.addEventListener('scroll', () => {
            if (syncingSheetScroll) return;
            syncingSheetScroll = true;
            topSheetScroll.scrollLeft = mainSheetScroll.scrollLeft;
            syncingSheetScroll = false;
        }, { signal: applicationEventController.signal });
        topSheetScroll.addEventListener('scroll', () => {
            if (syncingSheetScroll) return;
            syncingSheetScroll = true;
            mainSheetScroll.scrollLeft = topSheetScroll.scrollLeft;
            syncingSheetScroll = false;
        }, { signal: applicationEventController.signal });
        new ResizeObserver(scheduleTopSheetScrollbarSync).observe(mainSheetScroll);
        if (skaterSheet) new ResizeObserver(scheduleTopSheetScrollbarSync).observe(skaterSheet);
        scheduleTopSheetScrollbarSync();
    }

    const stageRibbonIds = (stageId) => Array.from(new Set(
        Array.from(document.querySelectorAll(
            `.stage-control-row [data-stage-detail="${stageId}"][data-ribbon-skill]`
        )).map((cell) => cell.dataset.ribbonSkill)
    ));

    const setRibbonState = (stageId, ribbonId, expanded) => {
        document.querySelectorAll(`[data-ribbon-skill="${ribbonId}"]`).forEach((cell) => {
            cell.classList.toggle('hidden-column', !expanded);
        });
    };

    const syncStageState = (stageId) => {
        const skillHeaders = Array.from(
            document.querySelectorAll(`.stage-control-row [data-stage-detail="${stageId}"][data-ribbon-skill]`)
        );
        const fullyExpanded = skillHeaders.length > 0
            && skillHeaders.every((cell) => !cell.classList.contains('hidden-column'));
        document.querySelectorAll(`[data-header-toggle-stage="${stageId}"]`).forEach((button) => {
            button.setAttribute('aria-expanded', String(fullyExpanded));
            button.classList.toggle('is-expanded', fullyExpanded);
            button.title = `${fullyExpanded ? 'Hide' : 'Show'} all ${button.dataset.stageName} skills`;
            const toggleSymbol = button.querySelector('[data-stage-toggle-symbol]');
            if (toggleSymbol) toggleSymbol.textContent = fullyExpanded ? '−' : '+';
            button.setAttribute(
                'aria-label',
                button.getAttribute('aria-label').replace(/^(Expand|Collapse)/, fullyExpanded ? 'Collapse' : 'Expand')
            );
        });
    };

    const setEntireStageExpanded = (stageId, expanded) => {
        stageRibbonIds(stageId).forEach((ribbonId) => {
            setRibbonState(stageId, ribbonId, expanded);
        });
        syncStageState(stageId);
        scheduleTopSheetScrollbarSync();
    };

    document.querySelectorAll('[data-header-toggle-stage]').forEach((button) => {
        button.addEventListener('click', () => {
            const willExpand = button.getAttribute('aria-expanded') !== 'true';
            setEntireStageExpanded(button.dataset.headerToggleStage, willExpand);
        });
    });

    const importDialog = document.querySelector('#import-dialog');
    document.querySelector('[data-open-import]')?.addEventListener('click', () => {
        importDialog?.showModal();
    });
    importDialog?.addEventListener('click', (event) => {
        if (event.target === importDialog) {
            importDialog.close();
        }
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

    const drawer = document.querySelector('#skater-drawer');
    const drawerContent = document.querySelector('[data-drawer-content]');
    const drawerTitle = document.querySelector('#drawer-title');
    const backdrop = document.querySelector('.drawer-backdrop');
    const progressSettingsDrawer = document.querySelector('#progress-settings-drawer');
    const progressSettingsBackdrop = document.querySelector('.settings-drawer-backdrop');
    const dobSettingInput = document.querySelector('[data-setting-dob]');
    const ageSettingInput = document.querySelector('[data-setting-age]');
    const genderSettingInput = document.querySelector('[data-setting-gender]');
    const attendanceSettingInput = document.querySelector('[data-setting-attendance]');
    const groupsSettingInput = document.querySelector('[data-setting-groups]');
    const notesSettingInput = document.querySelector('[data-setting-notes]');
    const groupColourSettingsList = document.querySelector('[data-group-colour-settings-list]');
    const groupColourNameInput = document.querySelector('[data-group-colour-name]');
    const groupColourHexInput = document.querySelector('[data-group-colour-hex]');
    const groupColourSettingsMessage = document.querySelector(
        '[data-group-colour-settings-message]'
    );
    const progressSettingsKey =
        `cat-progress-settings:${document.body.dataset.clubId}:${document.body.dataset.userId}`;
    const defaultGroupColours = (() => {
        try {
            const colours = JSON.parse(document.body.dataset.groupColours || '[]');
            return Array.isArray(colours) ? colours : [];
        } catch {
            return [];
        }
    })();
    const availableGenders = (() => {
        try {
            const genders = JSON.parse(document.body.dataset.genders || '[]');
            return Array.isArray(genders) ? genders : [];
        } catch {
            return [];
        }
    })();
    const availableSessions = (() => {
        try {
            const sessions = JSON.parse(document.body.dataset.sessions || '[]');
            return Array.isArray(sessions) ? sessions : [];
        } catch {
            return [];
        }
    })();
    let activeSkaterId = null;

    const defaultProgressSettings = () => ({
        dob: false,
        age: false,
        gender: false,
        attendance: false,
        groups: false,
        notes: false,
        groupColours: defaultGroupColours.map((colour) => ({ ...colour })),
    });

    const normalizeProgressSettings = (value) => {
        if (!value || typeof value !== 'object') return defaultProgressSettings();
        const seenGroupColourNames = new Set();
        const groupColours = (Array.isArray(value.groupColours)
            ? value.groupColours
            : defaultGroupColours
        ).reduce((colours, colour) => {
            const name = String(colour?.name ?? '').trim();
            const hex = String(colour?.hex ?? '').trim().toUpperCase();
            const normalizedName = name.toLocaleLowerCase();
            if (
                name === ''
                || name.length > 100
                || !/^#[0-9A-F]{6}$/.test(hex)
                || seenGroupColourNames.has(normalizedName)
            ) {
                return colours;
            }
            seenGroupColourNames.add(normalizedName);
            colours.push({ name, hex });
            return colours;
        }, []);
        return {
            dob: value.dob === true,
            age: value.age === true,
            gender: value.gender === true,
            attendance: value.attendance === true,
            groups: value.groups === true,
            notes: value.notes === true,
            groupColours,
        };
    };

    const loadProgressSettings = () => {
        try {
            const stored = window.localStorage.getItem(progressSettingsKey);
            return stored
                ? normalizeProgressSettings(JSON.parse(stored))
                : defaultProgressSettings();
        } catch {
            return defaultProgressSettings();
        }
    };

    let progressSettings = loadProgressSettings();

    const renderAssignmentGroupOptions = (resetInvalidSelection = false) => {
        const select = document.querySelector('[data-assignment-group]');
        if (!select) return;
        const selectedValue = select.value;
        select.replaceChildren();

        const noneOption = document.createElement('option');
        noneOption.value = '';
        noneOption.textContent = 'None';
        select.append(noneOption);

        defaultGroupColours.forEach((colour) => {
            const option = document.createElement('option');
            option.value = colour.name;
            option.textContent = colour.name;
            option.dataset.groupColour = colour.hex;
            select.append(option);
        });

        const selectionStillExists = Array.from(select.options).some(
            (option) => option.value === selectedValue
        );
        if (selectionStillExists && (!resetInvalidSelection || selectedValue !== '')) {
            select.value = selectedValue;
        } else {
            select.value = '';
        }
    };

    const renderGroupColourSettings = () => {
        if (!groupColourSettingsList) return;
        groupColourSettingsList.replaceChildren();
        if (progressSettings.groupColours.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'group-colour-settings-empty';
            empty.textContent = 'No group colours are available.';
            groupColourSettingsList.append(empty);
            return;
        }

        progressSettings.groupColours.forEach((colour) => {
            const row = document.createElement('div');
            row.className = 'group-colour-setting';

            const swatch = document.createElement('span');
            swatch.className = 'group-colour-setting-swatch';
            swatch.style.backgroundColor = colour.hex;
            swatch.setAttribute('aria-hidden', 'true');

            const name = document.createElement('strong');
            name.textContent = colour.name;

            const hex = document.createElement('small');
            hex.textContent = colour.hex;

            const remove = document.createElement('button');
            remove.className = 'button button-ghost group-colour-remove';
            remove.type = 'button';
            remove.dataset.removeGroupColour = colour.name;
            remove.textContent = 'Remove';
            remove.setAttribute('aria-label', `Remove ${colour.name} group colour`);

            row.append(swatch, name, hex, remove);
            groupColourSettingsList.append(row);
        });
    };

    const applyProgressSettings = () => {
        document.querySelectorAll('[data-optional-column="attendance"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.attendance);
        });
        document.querySelectorAll('[data-optional-column="dob"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.dob);
        });
        document.querySelectorAll('[data-optional-column="age"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.age);
        });
        document.querySelectorAll('[data-optional-column="gender"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.gender);
        });
        document.querySelectorAll('[data-optional-column="groups"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.groups);
        });
        document.querySelectorAll('[data-optional-column="notes"]').forEach((cell) => {
            cell.classList.toggle('is-settings-hidden', !progressSettings.notes);
        });

        if (dobSettingInput) dobSettingInput.checked = progressSettings.dob;
        if (ageSettingInput) ageSettingInput.checked = progressSettings.age;
        if (genderSettingInput) genderSettingInput.checked = progressSettings.gender;
        if (attendanceSettingInput) attendanceSettingInput.checked = progressSettings.attendance;
        if (groupsSettingInput) groupsSettingInput.checked = progressSettings.groups;
        if (notesSettingInput) notesSettingInput.checked = progressSettings.notes;
        renderGroupColourSettings();
        renderAssignmentGroupOptions();
        scheduleTopSheetScrollbarSync();
    };

    const saveProgressSettings = () => {
        try {
            window.localStorage.setItem(progressSettingsKey, JSON.stringify(progressSettings));
        } catch {
            // The settings still apply for this page if browser storage is unavailable.
        }
        applyProgressSettings();
    };

    const closeProgressSettings = () => {
        if (!progressSettingsDrawer || !progressSettingsBackdrop) return;
        progressSettingsDrawer.hidden = true;
        progressSettingsBackdrop.hidden = true;
        if (!drawer || drawer.hidden) document.body.classList.remove('drawer-open');
    };

    document.querySelector('[data-open-progress-settings]')?.addEventListener('click', () => {
        if (!progressSettingsDrawer || !progressSettingsBackdrop) return;
        if (drawer && !drawer.hidden) {
            drawer.hidden = true;
            if (backdrop) backdrop.hidden = true;
            activeSkaterId = null;
        }
        progressSettingsDrawer.hidden = false;
        progressSettingsBackdrop.hidden = false;
        document.body.classList.add('drawer-open');
        progressSettingsDrawer.querySelector('input')?.focus();
    });

    document.querySelectorAll('[data-close-progress-settings]').forEach((control) => {
        control.addEventListener('click', closeProgressSettings);
    });

    attendanceSettingInput?.addEventListener('change', () => {
        progressSettings.attendance = attendanceSettingInput.checked;
        saveProgressSettings();
    });

    dobSettingInput?.addEventListener('change', () => {
        progressSettings.dob = dobSettingInput.checked;
        saveProgressSettings();
    });

    ageSettingInput?.addEventListener('change', () => {
        progressSettings.age = ageSettingInput.checked;
        saveProgressSettings();
    });

    genderSettingInput?.addEventListener('change', () => {
        progressSettings.gender = genderSettingInput.checked;
        saveProgressSettings();
    });

    groupsSettingInput?.addEventListener('change', () => {
        progressSettings.groups = groupsSettingInput.checked;
        saveProgressSettings();
    });

    notesSettingInput?.addEventListener('change', () => {
        progressSettings.notes = notesSettingInput.checked;
        saveProgressSettings();
    });

    document.querySelector('[data-add-group-colour]')?.addEventListener('click', () => {
        const name = groupColourNameInput?.value.trim() || '';
        const hex = groupColourHexInput?.value.toUpperCase() || '';
        const duplicate = progressSettings.groupColours.some(
            (colour) => colour.name.toLocaleLowerCase() === name.toLocaleLowerCase()
        );
        if (name === '' || !/^#[0-9A-F]{6}$/.test(hex)) {
            if (groupColourSettingsMessage) {
                groupColourSettingsMessage.textContent =
                    'Enter a colour name and choose a valid colour.';
                groupColourSettingsMessage.hidden = false;
            }
            return;
        }
        if (duplicate) {
            if (groupColourSettingsMessage) {
                groupColourSettingsMessage.textContent =
                    `${name} is already in the group-colour list.`;
                groupColourSettingsMessage.hidden = false;
            }
            return;
        }

        progressSettings.groupColours.push({ name, hex });
        if (groupColourNameInput) groupColourNameInput.value = '';
        if (groupColourSettingsMessage) groupColourSettingsMessage.hidden = true;
        saveProgressSettings();
        groupColourNameInput?.focus();
    });

    groupColourNameInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        document.querySelector('[data-add-group-colour]')?.click();
    });

    groupColourSettingsList?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-group-colour]');
        if (!removeButton) return;
        const name = removeButton.dataset.removeGroupColour;
        progressSettings.groupColours = progressSettings.groupColours.filter(
            (colour) => colour.name !== name
        );
        if (groupColourSettingsMessage) groupColourSettingsMessage.hidden = true;
        saveProgressSettings();
    });

    document.querySelector('[data-reset-progress-settings]')?.addEventListener('click', () => {
        progressSettings = defaultProgressSettings();
        saveProgressSettings();
    });

    applyProgressSettings();

    const seasonFilter = document.querySelector('[data-season-filter]'); // Dashboard filters
    const sessionFilter = document.querySelector('[data-session-filter]');
    const groupFilter = document.querySelector('[data-group-filter]');
    const ageFilter = document.querySelector('[data-age-filter]');
    const highestBadgeFilter = document.querySelector('[data-highest-badge-filter]');
    const pageSizeSelect = document.querySelector('[data-page-size]');
    const rosterFilterForm = seasonFilter?.closest('form');
    const resetRosterFiltersButton = document.querySelector('[data-reset-roster-filters]');
    const skaterSearchInput = document.querySelector('[data-skater-search]');
    let skaterRows = Array.from(document.querySelectorAll('[data-skater-row]'));
    const selectAllSkatersInput = document.querySelector('[data-select-all-skaters]');
    let skaterSelectionInputs = Array.from(document.querySelectorAll('[data-select-skater]'));
    const groupAssignmentButton = document.querySelector('[data-open-group-assignment]');
    const groupAssignmentDialog = document.querySelector('#group-assignment-dialog');
    const groupPickerButtons = Array.from(document.querySelectorAll('[data-assign-group-name], [data-assign-group-none]'));
    const groupAssignmentMessage = document.querySelector('[data-group-assignment-message]');
    const groupAssignmentDescription = document.querySelector('[data-group-assignment-description]');
    const groupAssignmentSessionWarning = document.querySelector('[data-group-assignment-session-warning]');
    const addSkaterButton = document.querySelector('[data-open-add-skater]');
    const deleteSkatersButton = document.querySelector('[data-open-delete-skaters]');
    const deleteSkatersDialog = document.querySelector('#skater-delete-dialog');
    const deleteSkatersForm = document.querySelector('[data-delete-skaters-form]');
    const deleteConfirmationInput = document.querySelector('[data-delete-confirmation]');
    const confirmDeleteSkatersButton = document.querySelector('[data-confirm-delete-skaters]');
    const deleteDialogTitle = document.querySelector('[data-delete-dialog-title]');
    const deleteDialogCopy = document.querySelector('[data-delete-dialog-copy]');
    const deleteMessage = document.querySelector('[data-delete-message]');
    let pendingDeletionSkaterIds = [];
    const rosterBody = document.querySelector('.skater-sheet tbody');
    const columnResizeHandles = Array.from(document.querySelectorAll('[data-column-resizer]'));
    const rosterColumnWidthsKey =
        `cat-roster-column-widths:${document.body.dataset.clubId}:${document.body.dataset.userId}`;
    const columnWidthProperties = {
        'first-name': '--first-name-col-width',
        'last-name': '--last-name-col-width',
    };
    const columnWidthMinimum = 96;
    const columnWidthMaximum = 360;

    const applyRosterColumnWidth = (column, width) => {
        const property = columnWidthProperties[column];
        if (!property) return;
        const normalizedWidth = Math.max(
            columnWidthMinimum,
            Math.min(columnWidthMaximum, Math.round(Number(width) || columnWidthMinimum))
        );
        document.documentElement.style.setProperty(property, `${normalizedWidth}px`);
        columnResizeHandles
            .filter((handle) => handle.dataset.columnResizer === column)
            .forEach((handle) => {
                handle.setAttribute('aria-valuemin', String(columnWidthMinimum));
                handle.setAttribute('aria-valuemax', String(columnWidthMaximum));
                handle.setAttribute('aria-valuenow', String(normalizedWidth));
            });
    };

    const currentRosterColumnWidth = (column) => {
        const property = columnWidthProperties[column];
        return property
            ? Number.parseFloat(getComputedStyle(document.documentElement).getPropertyValue(property))
            : columnWidthMinimum;
    };

    const saveRosterColumnWidths = () => {
        try {
            const widths = Object.fromEntries(
                Object.keys(columnWidthProperties).map((column) => [column, currentRosterColumnWidth(column)])
            );
            window.localStorage.setItem(rosterColumnWidthsKey, JSON.stringify(widths));
        } catch {
            // Keeping the resized columns for this page is sufficient when storage is unavailable.
        }
    };

    try {
        const savedWidths = JSON.parse(window.localStorage.getItem(rosterColumnWidthsKey) || '{}');
        Object.entries(savedWidths).forEach(([column, width]) => applyRosterColumnWidth(column, width));
    } catch {
        // Use the stylesheet defaults when a previous setting cannot be read.
    }

    columnResizeHandles.forEach((handle) => {
        const column = handle.dataset.columnResizer;
        applyRosterColumnWidth(column, currentRosterColumnWidth(column));

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            const startX = event.clientX;
            const startWidth = currentRosterColumnWidth(column);
            document.body.classList.add('is-resizing-column');
            handle.setPointerCapture?.(event.pointerId);

            const resize = (moveEvent) => {
                applyRosterColumnWidth(column, startWidth + moveEvent.clientX - startX);
            };
            const finish = () => {
                document.body.classList.remove('is-resizing-column');
                saveRosterColumnWidths();
                document.removeEventListener('pointermove', resize);
                document.removeEventListener('pointerup', finish);
                document.removeEventListener('pointercancel', finish);
            };
            document.addEventListener('pointermove', resize);
            document.addEventListener('pointerup', finish);
            document.addEventListener('pointercancel', finish);
        });

        handle.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const width = event.key === 'Home'
                ? columnWidthMinimum
                : event.key === 'End'
                    ? columnWidthMaximum
                    : currentRosterColumnWidth(column) + (event.key === 'ArrowRight' ? 8 : -8);
            applyRosterColumnWidth(column, width);
            saveRosterColumnWidths();
        });
    });
    const sortButtons = Array.from(document.querySelectorAll('[data-sort-key][data-sort-direction]'));
    const liveSearchEmptyRow = document.querySelector('[data-live-search-empty]');
    const sortCollator = new Intl.Collator(undefined, {
        numeric: true,
        sensitivity: 'base',
    });
    // The server supplies the roster in last-name, then first-name order.
    // Keep the controls in sync with that initial order so the first click reverses it.
    let activeSort = {key: 'last-name', direction: 'asc'};
    let skaterSelectionAnchor = null;
    const selectedSkaterIds = () => skaterSelectionInputs
        .filter((input) => input.checked)
        .map((input) => input.value);

    const syncBulkGroupAssignmentState = () => {
        const hasSelection = skaterSelectionInputs.some((input) => input.checked);
        const hasSingleSession = Boolean(sessionFilter?.value);
        if (groupAssignmentButton) {
            groupAssignmentButton.disabled = !hasSelection || !hasSingleSession;
            groupAssignmentButton.title = hasSingleSession
                ? 'Assign selected skaters to a group'
                : 'Choose a single session before assigning skaters to a group';
        }
        if (deleteSkatersButton) deleteSkatersButton.disabled = !hasSelection;
    };

    const visibleSkaterSelectionInputs = () => skaterSelectionInputs.filter(
        (input) => !input.closest('[data-skater-row]')?.hidden
    );

    const syncSelectAllSkatersState = () => {
        if (!selectAllSkatersInput) return;
        const visibleInputs = visibleSkaterSelectionInputs();
        const selectedCount = visibleInputs.filter((input) => input.checked).length;
        selectAllSkatersInput.checked = visibleInputs.length > 0
            && selectedCount === visibleInputs.length;
        selectAllSkatersInput.indeterminate = selectedCount > 0
            && selectedCount < visibleInputs.length;
        selectAllSkatersInput.disabled = visibleInputs.length === 0;
        selectAllSkatersInput.setAttribute(
            'aria-label',
            selectAllSkatersInput.checked
                ? 'Clear all displayed skaters'
                : 'Select all displayed skaters'
        );
        syncBulkGroupAssignmentState();
    };

    selectAllSkatersInput?.addEventListener('change', () => {
        visibleSkaterSelectionInputs().forEach((input) => {
            input.checked = selectAllSkatersInput.checked;
        });
        skaterSelectionAnchor = null;
        syncSelectAllSkatersState();
    });

    skaterSelectionInputs.forEach((input) => {
        input.addEventListener('click', (event) => {
            const visibleInputsInRowOrder = Array.from(
                rosterBody?.querySelectorAll('[data-select-skater]') || []
            ).filter((candidate) => !candidate.closest('[data-skater-row]')?.hidden);

            if (event.shiftKey && skaterSelectionAnchor) {
                const anchorIndex = visibleInputsInRowOrder.indexOf(skaterSelectionAnchor);
                const currentIndex = visibleInputsInRowOrder.indexOf(input);
                if (anchorIndex !== -1 && currentIndex !== -1) {
                    const rangeStart = Math.min(anchorIndex, currentIndex);
                    const rangeEnd = Math.max(anchorIndex, currentIndex);
                    visibleInputsInRowOrder
                        .slice(rangeStart, rangeEnd + 1)
                        .forEach((rangeInput) => {
                            rangeInput.checked = input.checked;
                        });
                } else {
                    skaterSelectionAnchor = input;
                }
            } else {
                skaterSelectionAnchor = input;
            }

            syncSelectAllSkatersState();
        });
        input.addEventListener('change', syncSelectAllSkatersState);
    });

    skaterRows.forEach((row) => {
        row.querySelectorAll('.sticky-first-name, .sticky-last-name').forEach((cell) => {
            cell.addEventListener('click', () => {
                row.querySelector('[data-select-skater]')?.click();
            });
        });
    });

    const rosterSortValue = (row, key) => {
        if (key === 'first-name') return row.dataset.sortFirstName || '';
        if (key === 'last-name') return row.dataset.sortLastName || '';
        if (key === 'number') return row.dataset.sortNumber || '';
        if (key === 'dob') {
            return row.querySelector('[data-optional-column="dob"]')?.dataset.sortValue || '';
        }
        if (key === 'age') {
            return row.querySelector('[data-optional-column="age"]')?.dataset.sortValue || '';
        }
        if (key === 'gender') {
            return row.querySelector('[data-optional-column="gender"]')?.dataset.sortValue || '';
        }
        if (key === 'attendance') {
            return row.querySelector(
                '[data-optional-column="attendance"] [data-row-season-content]:not([hidden])'
            )?.dataset.sortValue || '';
        }
        if (key === 'group') {
            return Array.from(row.querySelectorAll(
                '[data-group-dot]:not(.is-filtered-out):not(.is-unassigned)'
            ))
                .filter((dot) => !dot.closest('[data-row-season-content]')?.hidden)
                .map((dot) => dot.dataset.groupName || '')
                .join(' | ');
        }
        if (key === 'notes') {
            return row.querySelector('[data-optional-column="notes"]')?.dataset.sortValue || '';
        }
        if (key.startsWith('stage-')) {
            const stageId = key.substring('stage-'.length);
            return row.querySelector(`[data-stage-summary="${stageId}"]`)?.dataset.sortValue || '';
        }
        return '';
    };

    const isNumericSort = (key) => (
        key === 'age' || key === 'attendance' || key === 'notes' || key.startsWith('stage-')
    );

    const compareRosterRows = (firstRow, secondRow, key, direction) => {
        const firstValue = rosterSortValue(firstRow, key);
        const secondValue = rosterSortValue(secondRow, key);
        const firstIsBlank = firstValue === '';
        const secondIsBlank = secondValue === '';

        if (firstIsBlank !== secondIsBlank) return firstIsBlank ? 1 : -1;

        let comparison = 0;
        if (!firstIsBlank) {
            comparison = isNumericSort(key)
                ? Number(firstValue) - Number(secondValue)
                : sortCollator.compare(firstValue, secondValue);
        }
        if (comparison === 0) {
            comparison = sortCollator.compare(
                firstRow.dataset.sortName || '',
                secondRow.dataset.sortName || ''
            );
        }

        return direction === 'desc' ? -comparison : comparison;
    };

    const updateSortIndicators = () => {
        document.querySelectorAll('[data-sort-header]').forEach((header) => {
            const isActive = activeSort?.key === header.dataset.sortHeader;
            header.setAttribute(
                'aria-sort',
                isActive
                    ? (activeSort.direction === 'asc' ? 'ascending' : 'descending')
                    : 'none'
            );
        });
        sortButtons.forEach((button) => {
            const isActive = activeSort?.key === button.dataset.sortKey;
            const direction = isActive ? activeSort.direction : 'asc';
            button.dataset.sortDirection = direction;
            const indicator = button.querySelector('[data-sort-indicator]');
            if (indicator) indicator.textContent = isActive
                ? (direction === 'asc' ? '↑' : '↓')
                : '↕';
            button.setAttribute(
                'aria-label',
                button.getAttribute('aria-label').replace(
                    /\s+(ascending|descending)$/,
                    ` ${direction === 'asc' ? 'ascending' : 'descending'}`
                )
            );
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });
    };

    const sortRosterRows = () => {
        if (!activeSort || !rosterBody) return;
        const sortedRows = [...skaterRows].sort((firstRow, secondRow) => compareRosterRows(
            firstRow,
            secondRow,
            activeSort.key,
            activeSort.direction
        ));
        sortedRows.forEach((row) => rosterBody.append(row));
        updateSortIndicators();
    };

    const refreshAlternatingRows = () => {
        let visibleIndex = 0;
        Array.from(rosterBody?.querySelectorAll('[data-skater-row]') || []).forEach((row) => {
            row.classList.toggle('is-alternate-row', !row.hidden && visibleIndex % 2 === 1);
            if (!row.hidden) visibleIndex++;
        });
    };

    sortButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const direction = activeSort?.key === button.dataset.sortKey
                ? (activeSort.direction === 'asc' ? 'desc' : 'asc')
                : (button.dataset.sortDirection || 'asc');
            activeSort = {
                key: button.dataset.sortKey,
                direction,
            };
            sortRosterRows();
            refreshAlternatingRows();
        });
    });

    const rosterFilterMaps = new Map(skaterRows.map((row) => {
        try {
            return [row, JSON.parse(row.dataset.rosterFilterMap || '{}')];
        } catch {
            return [row, {}];
        }
    }));

    const syncSessionFilterOptions = (resetInvalidSelection = false) => {
        if (!seasonFilter || !sessionFilter) return;
        const selectedSeasonId = seasonFilter.value;
        let selectionIsAvailable = sessionFilter.value === '';

        Array.from(sessionFilter.options).forEach((option) => {
            if (option.value === '') return;
            const available = option.value === 'unregistered'
                || option.dataset.seasonId === selectedSeasonId;
            option.disabled = !available;
            option.hidden = !available;
            if (available && option.selected) selectionIsAvailable = true;
        });

        if (resetInvalidSelection && !selectionIsAvailable) {
            sessionFilter.value = '';
        }
    };

    const syncGroupFilterOptions = (resetInvalidSelection = false) => {
        if (!seasonFilter || !sessionFilter || !groupFilter) return;
        const selectedSeasonId = seasonFilter.value;
        const selectedSessionId = sessionFilter.value;
        let selectionIsAvailable = groupFilter.value === '';

        Array.from(groupFilter.options).forEach((option) => {
            if (option.value === '') return;
            const sessionIds = (option.dataset.sessionIds || option.dataset.sessionId || '')
                .split(',')
                .filter(Boolean);
            const available = option.dataset.seasonId === selectedSeasonId
                && (
                    selectedSessionId === ''
                    || sessionIds.includes(selectedSessionId)
                );
            option.disabled = !available;
            option.hidden = !available;
            if (available && option.selected) selectionIsAvailable = true;
        });

        if (resetInvalidSelection && !selectionIsAvailable) {
            groupFilter.value = '';
        }
    };

    const syncAssignmentGroupOptions = (resetInvalidSelection = false) => {
        renderAssignmentGroupOptions(resetInvalidSelection);
    };

    const normalizedSearchValue = (value) => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase()
        .trim();

    const updateRosterFilterUrl = () => {
        if (!seasonFilter) return;
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('season_id', seasonFilter.value);
        if (sessionFilter?.value) {
            nextUrl.searchParams.set('session_id', sessionFilter.value);
        } else {
            nextUrl.searchParams.delete('session_id');
        }
        if (groupFilter?.value) {
            nextUrl.searchParams.set('group_id', groupFilter.value);
        } else {
            nextUrl.searchParams.delete('group_id');
        }
        if (ageFilter?.value) {
            nextUrl.searchParams.set('age', ageFilter.value);
        } else {
            nextUrl.searchParams.delete('age');
        }
        if (highestBadgeFilter?.value !== '') {
            nextUrl.searchParams.set('highest_badge', highestBadgeFilter.value);
        } else {
            nextUrl.searchParams.delete('highest_badge');
        }
        const searchValue = skaterSearchInput?.value.trim() || '';
        if (searchValue !== '') {
            nextUrl.searchParams.set('search', searchValue);
        } else {
            nextUrl.searchParams.delete('search');
        }
        window.history.replaceState({}, '', nextUrl);
    };

    const reloadRosterForFilterChange = () => {
        updateRosterFilterUrl();
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.delete('page');
        window.location.assign(nextUrl.toString());
    };

    const applyRosterFilters = () => {
        const query = normalizedSearchValue(skaterSearchInput?.value);
        const selectedSeasonId = seasonFilter?.value || '';
        const selectedSessionId = sessionFilter?.value || '';
        const selectedGroupId = groupFilter?.value || '';
        const selectedGroupIds = selectedGroupId === ''
            ? []
            : (groupFilter?.selectedOptions[0]?.dataset.groupIds || selectedGroupId)
                .split(',')
                .filter(Boolean);
        const selectedAge = ageFilter?.value || '';
        const selectedHighestBadge = highestBadgeFilter?.value ?? '';
        const isUnassignedSeason = selectedSeasonId === 'unregistered';
        const isUnregisteredSession = selectedSessionId === 'unregistered';
        let shown = 0;

        skaterRows.forEach((row) => {
            const seasonRegistrations = rosterFilterMaps.get(row)?.[selectedSeasonId] || [];
            const matchesSeason = isUnassignedSeason
                ? isUnregisteredSession
                : isUnregisteredSession || seasonRegistrations.length > 0;
            const matchesSession = selectedSessionId === ''
                || isUnregisteredSession
                || seasonRegistrations.some(
                    (registration) => String(registration.session_id) === selectedSessionId
                );
            const matchesGroup = selectedGroupId === ''
                || seasonRegistrations.some(
                    (registration) =>
                        selectedGroupIds.includes(String(registration.group_id))
                        && (
                            selectedSessionId === ''
                            || String(registration.session_id) === selectedSessionId
                        )
                );
            const matchesAge = selectedAge === ''
                || row.dataset.filterAge === selectedAge;
            const matchesHighestBadge = selectedHighestBadge === ''
                || row.dataset.highestBadge === selectedHighestBadge;
            const matchesSearch = query === ''
                || normalizedSearchValue(row.dataset.searchText).includes(query);
            const matches = matchesSeason
                && matchesSession
                && matchesGroup
                && matchesAge
                && matchesHighestBadge
                && matchesSearch;

            row.hidden = !matches;
            const contentSeasonId = isUnassignedSeason ? '0' : selectedSeasonId;
            row.querySelectorAll('[data-row-season-content]').forEach((content) => {
                content.hidden = content.dataset.rowSeasonContent !== contentSeasonId;
            });
            row.querySelectorAll('[data-group-dot]').forEach((dot) => {
                const belongsToSelectedSession = selectedSessionId === ''
                    || dot.dataset.sessionId === selectedSessionId;
                const belongsToSelectedGroup = selectedGroupId === ''
                    || selectedGroupIds.includes(dot.dataset.groupId);
                dot.classList.toggle(
                    'is-filtered-out',
                    !belongsToSelectedSession || !belongsToSelectedGroup
                );
            });
            if (matches) shown++;
        });

        sortRosterRows();
        refreshAlternatingRows();
        syncSelectAllSkatersState();
        if (liveSearchEmptyRow) liveSearchEmptyRow.hidden = shown !== 0;
        if (resetRosterFiltersButton) {
            resetRosterFiltersButton.hidden = selectedSessionId === ''
                && selectedGroupId === ''
                && selectedAge === ''
                && selectedHighestBadge === ''
                && query === '';
        }

        updateRosterFilterUrl();
    };

    seasonFilter?.addEventListener('change', () => {
        syncSessionFilterOptions(true);
        syncGroupFilterOptions(true);
        syncAssignmentGroupOptions(true);
        reloadRosterForFilterChange();
    });
    sessionFilter?.addEventListener('change', () => {
        syncGroupFilterOptions(true);
        syncAssignmentGroupOptions(true);
        reloadRosterForFilterChange();
    });
    groupFilter?.addEventListener('change', reloadRosterForFilterChange);
    ageFilter?.addEventListener('change', reloadRosterForFilterChange);
    highestBadgeFilter?.addEventListener('change', reloadRosterForFilterChange);
    rosterFilterForm?.addEventListener('submit', (event) => event.preventDefault());
    let rosterSearchReloadTimer = null;
    resetRosterFiltersButton?.addEventListener('click', () => {
        if (sessionFilter) sessionFilter.value = '';
        if (groupFilter) groupFilter.value = '';
        if (ageFilter) ageFilter.value = '';
        if (highestBadgeFilter) highestBadgeFilter.value = '';
        if (skaterSearchInput) skaterSearchInput.value = '';
        window.clearTimeout(rosterSearchReloadTimer);
        syncGroupFilterOptions();
        syncAssignmentGroupOptions(true);
        reloadRosterForFilterChange();
    });

    skaterSearchInput?.addEventListener('input', () => {
        applyRosterFilters();
        window.clearTimeout(rosterSearchReloadTimer);
        rosterSearchReloadTimer = window.setTimeout(() => {
            reloadRosterForFilterChange();
        }, 250);
    });
    skaterSearchInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });
    const initialRosterFilterUrl = new URL(window.location.href);
    const requestedAge = initialRosterFilterUrl.searchParams.get('age');
    const requestedHighestBadge = initialRosterFilterUrl.searchParams.get('highest_badge');
    if (
        ageFilter
        && requestedAge !== null
        && Array.from(ageFilter.options).some((option) => option.value === requestedAge)
    ) {
        ageFilter.value = requestedAge;
    }
    if (
        highestBadgeFilter
        && requestedHighestBadge !== null
        && Array.from(highestBadgeFilter.options).some(
            (option) => option.value === requestedHighestBadge
        )
    ) {
        highestBadgeFilter.value = requestedHighestBadge;
    }
    syncSessionFilterOptions();
    syncGroupFilterOptions();
    syncAssignmentGroupOptions();
    applyRosterFilters();

    pageSizeSelect?.addEventListener('change', () => {
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('page_size', pageSizeSelect.value);
        nextUrl.searchParams.set('page', '1');
        if (window.CATDashboardPagination?.navigate) {
            window.CATDashboardPagination.navigate(nextUrl.toString());
        } else {
            window.location.assign(nextUrl.toString());
        }
    });

    const applyGroupAssignmentUpdates = (updates, selectedGroup) => {
        const selectedSeasonId = seasonFilter?.value || '';

        updates.forEach((update) => {
            const assignedGroupId = update.group_id === null
                ? null
                : Number(update.group_id);
            const assignedGroupName = assignedGroupId === null
                ? 'Group unassigned'
                : update.group_name
                    || selectedGroup?.name
                    || 'Group';
            const assignedGroupColour = assignedGroupId === null
                ? 'transparent'
                : update.group_colour
                    || selectedGroup?.colour
                    || 'transparent';
            const selectionInput = skaterSelectionInputs.find(
                (input) => input.value === update.skater_id
            );
            const row = selectionInput?.closest('[data-skater-row]');
            if (!row) return;

            const filterMap = rosterFilterMaps.get(row) || {};
            const seasonRegistrations = filterMap[selectedSeasonId] || [];
            const registration = seasonRegistrations.find(
                (item) => Number(item.session_id) === Number(update.session_id)
            );
            if (registration) registration.group_id = assignedGroupId;
            rosterFilterMaps.set(row, filterMap);
            row.dataset.rosterFilterMap = JSON.stringify(filterMap);

            if (
                assignedGroupId !== null
                && groupFilter
                && !Array.from(groupFilter.options).some(
                    (option) => Number(option.value) === assignedGroupId
                )
            ) {
                const groupOption = document.createElement('option');
                groupOption.value = String(assignedGroupId);
                groupOption.textContent = assignedGroupName;
                groupOption.dataset.sessionId = String(update.session_id);
                groupOption.dataset.seasonId = selectedSeasonId;
                groupFilter.append(groupOption);
            }

            const dot = row.querySelector(
                `[data-row-season-content="${selectedSeasonId}"] `
                + `[data-group-dot][data-session-id="${update.session_id}"]`
            );
            if (!dot) return;
            dot.dataset.groupId = assignedGroupId === null ? '' : String(assignedGroupId);
            dot.dataset.groupName = assignedGroupName;
            dot.setAttribute('aria-label', assignedGroupName);
            dot.classList.toggle('is-unassigned', assignedGroupId === null);
            dot.querySelector('circle')?.setAttribute(
                'fill', assignedGroupId === null ? 'transparent' : assignedGroupColour
            );
        });
    };

    const showGroupAssignmentMessage = (message, type = 'error') => {
        if (!groupAssignmentMessage) return;
        groupAssignmentMessage.textContent = message;
        groupAssignmentMessage.className = `form-message ${type}`;
        groupAssignmentMessage.hidden = false;
    };

    const openGroupAssignmentDialog = () => {
        if (!groupAssignmentDialog || groupAssignmentButton?.disabled) return;
        const hasSingleSession = Boolean(sessionFilter?.value);
        const selectedCount = selectedSkaterIds().length;
        if (groupAssignmentDescription) {
            groupAssignmentDescription.textContent = `Choose a colour group for ${selectedCount} selected ${selectedCount === 1 ? 'skater' : 'skaters'}.`;
        }
        if (groupAssignmentSessionWarning) groupAssignmentSessionWarning.hidden = hasSingleSession;
        groupPickerButtons.forEach((button) => {
            button.disabled = !hasSingleSession;
        });
        if (groupAssignmentMessage) groupAssignmentMessage.hidden = true;
        groupAssignmentDialog.showModal();
    };

    groupAssignmentButton?.addEventListener('click', openGroupAssignmentDialog);
    groupAssignmentDialog?.addEventListener('click', (event) => {
        if (event.target === groupAssignmentDialog) groupAssignmentDialog.close();
    });

    groupPickerButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            if (!sessionFilter?.value || !seasonFilter?.value) return;
            const skaterIds = selectedSkaterIds();
            if (skaterIds.length === 0) return;
            const selectedGroup = button.dataset.assignGroupNone === 'true'
                ? {name: null, colour: null}
                : {
                    name: button.dataset.assignGroupName || '',
                    colour: button.dataset.assignGroupColour || '',
                };
            groupPickerButtons.forEach((choice) => { choice.disabled = true; });
            try {
                const response = await fetch(groupAssignmentDialog.dataset.assignmentUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        skater_ids: skaterIds,
                        season_id: Number(seasonFilter.value),
                        session_id: Number(sessionFilter.value),
                        group_id: null,
                        group_name: selectedGroup.name,
                        group_colour: selectedGroup.colour,
                    }),
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'The group assignment could not be saved.');
                applyGroupAssignmentUpdates(result.result?.updates || [], selectedGroup);
                skaterSelectionInputs.forEach((input) => { input.checked = false; });
                skaterSelectionAnchor = null;
                groupAssignmentDialog.close();
                applyRosterFilters();
                showSkillToast(result.message);
            } catch (error) {
                showGroupAssignmentMessage(error.message);
                groupPickerButtons.forEach((choice) => { choice.disabled = false; });
            } finally {
                syncBulkGroupAssignmentState();
            }
        });
    });

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const formatDate = (value) => {
        if (!value) return '—';
        const date = new Date(`${value.substring(0, 10)}T12:00:00`);
        return new Intl.DateTimeFormat('en-CA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(date);
    };

    const formatTime = (value) => {
        if (!value) return '';
        const [hours, minutes] = value.split(':').map(Number);
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    };

    const formatDateTime = (value) => {
        if (!value) return '—';
        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return value;
        return new Intl.DateTimeFormat('en-CA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(date);
    };

    const achievementDialog = document.querySelector('#achievement-dialog');
    const achievementForm = achievementDialog?.querySelector('[data-achievement-form]');
    const achievementMessage = achievementDialog?.querySelector('[data-achievement-message]');
    const achievementSubmit = achievementDialog?.querySelector('[data-achievement-submit]');
    let achievementTarget = null;
    const awardRemovalDialog = document.querySelector('#award-removal-dialog');
    const awardRemovalForm = awardRemovalDialog?.querySelector('[data-award-removal-form]');
    const awardRemovalMessage = awardRemovalDialog?.querySelector('[data-award-removal-message]');
    const awardRemovalSubmit = awardRemovalDialog?.querySelector('[data-award-removal-submit]');
    let awardRemovalTarget = null;
    const awardOverrideDialog = document.querySelector('#award-override-dialog');
    const awardOverrideForm = awardOverrideDialog?.querySelector('[data-award-override-form]');
    const awardOverrideMessage = awardOverrideDialog?.querySelector('[data-award-override-message]');
    const awardOverrideSubmit = awardOverrideDialog?.querySelector('[data-award-override-submit]');
    const awardOverrideSuppress = awardOverrideDialog?.querySelector('[data-award-override-suppress]');
    let awardOverrideTarget = null;
    let awardOverrideAction = null;
    const awardOverrideStorageKey = `cat-award-override-confirmed:${document.body.dataset.userId || 'unknown'}`;
    const canOverrideAwards = document.body.dataset.canOverrideAwards === 'true';

    const localCalendarDay = () => {
        const today = new Date();
        const offset = today.getTimezoneOffset() * 60000;
        return new Date(today.getTime() - offset).toISOString().slice(0, 10);
    };

    const overrideConfirmationSuppressedToday = () => {
        try {
            return window.localStorage.getItem(awardOverrideStorageKey) === localCalendarDay();
        } catch (_) {
            return false;
        }
    };

    const showSkillToast = (message, type = 'success') => {
        let toast = document.querySelector('[data-skill-toast]');
        if (!toast) {
            toast = document.createElement('div');
            toast.dataset.skillToast = '';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.append(toast);
        }
        toast.textContent = message;
        toast.className = `skill-toast ${type}`;
        toast.hidden = false;
        window.clearTimeout(showSkillToast.timeoutId);
        showSkillToast.timeoutId = window.setTimeout(() => {
            toast.hidden = true;
        }, 3200);
    };

    let sessionInfoPopover = null;
    let sessionInfoSource = null;

    const hideSessionInfo = () => {
        if (sessionInfoPopover) sessionInfoPopover.hidden = true;
        if (sessionInfoSource) sessionInfoSource.setAttribute('aria-expanded', 'false');
        sessionInfoSource = null;
    };

    const showSessionInfo = (dot) => {
        if (!dot?.dataset.sessionInfo) return;
        if (sessionInfoSource === dot && sessionInfoPopover && !sessionInfoPopover.hidden) {
            hideSessionInfo();
            return;
        }
        if (!sessionInfoPopover) {
            sessionInfoPopover = document.createElement('div');
            sessionInfoPopover.className = 'group-session-popover';
            sessionInfoPopover.setAttribute('role', 'status');
            sessionInfoPopover.setAttribute('aria-live', 'polite');
            document.body.append(sessionInfoPopover);
        }
        if (sessionInfoSource) sessionInfoSource.setAttribute('aria-expanded', 'false');
        sessionInfoSource = dot;
        sessionInfoPopover.textContent = dot.dataset.sessionInfo;
        sessionInfoPopover.hidden = false;
        dot.setAttribute('aria-expanded', 'true');
        const dotRect = dot.getBoundingClientRect();
        const popoverWidth = sessionInfoPopover.offsetWidth;
        const popoverHeight = sessionInfoPopover.offsetHeight;
        const left = Math.min(
            window.innerWidth - popoverWidth - 12,
            Math.max(12, dotRect.left + (dotRect.width / 2) - (popoverWidth / 2))
        );
        const top = dotRect.bottom + popoverHeight + 12 > window.innerHeight
            ? Math.max(12, dotRect.top - popoverHeight - 8)
            : dotRect.bottom + 8;
        sessionInfoPopover.style.left = `${left}px`;
        sessionInfoPopover.style.top = `${top}px`;
    };

    document.addEventListener('click', (event) => {
        if (!isCurrentApplicationGeneration()) return;
        const dot = event.target.closest?.('[data-group-dot]');
        if (dot) {
            showSessionInfo(dot);
            return;
        }
        hideSessionInfo();
    }, { signal: applicationEventController.signal });

    document.addEventListener('keydown', (event) => {
        if (!isCurrentApplicationGeneration()) return;
        const dot = event.target.closest?.('[data-group-dot]');
        if (dot && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            showSessionInfo(dot);
        }
        if (event.key === 'Escape') hideSessionInfo();
    }, { signal: applicationEventController.signal });

    const setAchievementMessage = (message, type = 'error') => {
        if (!achievementMessage) return;
        achievementMessage.textContent = message;
        achievementMessage.className = `form-message ${type}`;
        achievementMessage.hidden = false;
    };

    const syncAwardControl = (control) => {
        if (!control) return;
        const awarded = control.dataset.awarded === 'true';
        const eligible = control.dataset.eligible === 'true';
        const canEdit = control.dataset.canEdit === 'true';
        const canOverride = control.dataset.canOverride === 'true';
        const awardName = control.dataset.awardName;
        const skaterName = control.dataset.awardSkaterName;
        const prerequisite = control.dataset.awardPrerequisite === 'ribbons'
            ? 'Award all fundamental-area ribbons before recording this badge.'
            : 'Complete the required skills before recording this award.';

        control.classList.toggle('is-awarded', awarded);
        control.classList.toggle('is-eligible', eligible);
        control.disabled = !canEdit || (!eligible && !awarded && !canOverride);
        control.classList.toggle('is-override-available', !eligible && !awarded && canOverride);

        let description;
        if (awarded) {
            description = `${awardName} given to ${skaterName}. Select to remove.`;
        } else if (eligible) {
            description = `Record ${awardName} as given to ${skaterName}.`;
        } else {
            description = canOverride
                ? `${awardName} is not yet qualified. Select to award it and mark its skills achieved.`
                : prerequisite;
        }
        control.title = description;
        control.setAttribute('aria-label', description);
    };

    const setAwardEligibility = (control, eligible) => {
        if (!control) return;
        control.dataset.eligible = String(eligible);
        syncAwardControl(control);
    };

    const updateProgressCounter = (element, difference) => {
        if (!element) return;
        const match = element.textContent.trim().match(/^(\d+)\/(\d+)$/);
        if (!match) return;
        const total = Number(match[2]);
        const achieved = Math.max(0, Math.min(total, Number(match[1]) + difference));
        element.textContent = `${achieved}/${total}`;
        if (element.classList.contains('progress-value') && element.dataset.stageProgress !== 'ribbon-awards') {
            const stageSummary = element.closest('[data-stage-summary]');
            if (stageSummary) stageSummary.dataset.sortValue = String(achieved);
            setAwardEligibility(
                element.closest('.stage-badge-stack')?.querySelector('[data-award-control]'),
                achieved >= Number(element.dataset.required || total)
            );
        }
        if (element.classList.contains('ribbon-progress-value')) {
            setAwardEligibility(
                element.closest('.stage-ribbon-detail'),
                achieved >= Number(element.dataset.required || total)
            );
        }
    };

    const updateStageRibbonAwardProgress = (control, difference) => {
        if (control.dataset.awardType !== 'ribbon') return;
        const row = control.closest('[data-skater-row]');
        const stageCell = control.closest('.stage-summary-col');
        const counter = stageCell?.querySelector('[data-stage-progress="ribbon-awards"]');
        if (!row || !stageCell || !counter) return;
        const match = counter.textContent.trim().match(/^(\d+)\/(\d+)$/);
        if (!match) return;
        const required = Number(match[2]);
        const awarded = Math.max(0, Math.min(required, Number(match[1]) + difference));
        counter.textContent = `${awarded}/${required}`;
        stageCell.dataset.sortValue = String(awarded);
        setAwardEligibility(
            stageCell.querySelector('[data-award-control][data-award-type="badge"]'),
            required > 0 && awarded >= required
        );
        if (activeSort?.key === `stage-${stageCell.dataset.stageSummary}`) {
            sortRosterRows();
            refreshAlternatingRows();
        }
    };

    const updateSkillCell = (button, achieved) => {
        const cell = button.closest('.skill-status');
        const row = button.closest('tr');
        const difference = achieved ? 1 : -1;
        cell?.classList.toggle('is-achieved', achieved);
        if (achieved && cell && button.matches(':hover')) {
            cell.classList.add('is-removal-hover-suppressed');
            button.addEventListener('pointerleave', () => {
                cell.classList.remove('is-removal-hover-suppressed');
            }, {once: true});
        } else if (!achieved) {
            cell?.classList.remove('is-removal-hover-suppressed');
        }
        button.dataset.achieved = String(achieved);
        button.querySelector('span').textContent = achieved ? '✓' : '';
        button.setAttribute(
            'aria-label',
            `${achieved ? 'Remove achievement for ' : 'Mark achieved: '}${button.dataset.skillName} — ${button.dataset.skaterName}`
        );
        if (cell) {
            cell.title = `${button.dataset.skillName}: ${achieved ? 'Achieved' : 'Not yet achieved'}`;
            updateProgressCounter(
                row?.querySelector(
                    `[data-ribbon-indicator="${cell.dataset.ribbonSkill}"] .ribbon-progress-value`
                ),
                difference
            );
            if (activeSort?.key === `stage-${cell.dataset.stageDetail}`) {
                sortRosterRows();
                refreshAlternatingRows();
            }
        }
    };

    const markSkillAchieved = async (button) => {
        if (button.disabled) return;
        const publicId = encodeURIComponent(button.dataset.assessmentSkaterId);
        const skillId = encodeURIComponent(button.dataset.skillId);
        const status = button.querySelector('span');
        button.disabled = true;
        button.classList.add('is-saving');
        status.textContent = '…';

        try {
            const response = await fetch(
                `${document.body.dataset.apiBase}/${publicId}/skills/${skillId}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: '{}',
                }
            );
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The achievement could not be saved.');
            updateSkillCell(button, true);
            (result.automatically_completed_skill_ids || []).forEach((automaticallyCompletedSkillId) => {
                const participationControl = Array.from(document.querySelectorAll('[data-edit-skill]')).find(
                    (control) => control.dataset.assessmentSkaterId === button.dataset.assessmentSkaterId
                        && Number(control.dataset.skillId) === Number(automaticallyCompletedSkillId)
                );
                if (participationControl) updateSkillCell(participationControl, true);
            });
            showSkillToast(`${button.dataset.skillName} marked achieved for ${button.dataset.skaterName}.`);
        } catch (error) {
            status.textContent = '';
            showSkillToast(error.message, 'error');
        } finally {
            button.disabled = false;
            button.classList.remove('is-saving');
        }
    };

    const removeSkillAchievement = async (button) => {
        if (button.disabled) return;
        const publicId = encodeURIComponent(button.dataset.assessmentSkaterId);
        const skillId = encodeURIComponent(button.dataset.skillId);
        const status = button.querySelector('span');
        button.disabled = true;
        button.classList.add('is-saving');
        status.textContent = '…';

        try {
            const response = await fetch(
                `${document.body.dataset.apiBase}/${publicId}/skills/${skillId}`,
                {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: '{}',
                }
            );
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The achievement could not be removed.');
            updateSkillCell(button, false);
            showSkillToast(result.message);
        } catch (error) {
            status.textContent = '✓';
            showSkillToast(error.message, 'error');
        } finally {
            button.disabled = false;
            button.classList.remove('is-saving');
        }
    };

    const openRemovalDialog = (button) => {
        if (!achievementDialog || !achievementForm || !achievementSubmit) return;
        achievementTarget = button;
        if (achievementMessage) achievementMessage.hidden = true;
        achievementDialog.querySelector('[data-achievement-skater]').textContent = button.dataset.skaterName;
        achievementDialog.querySelector('[data-achievement-skill]').textContent = button.dataset.skillName;
        achievementSubmit.disabled = false;
        achievementSubmit.textContent = 'Yes, remove achievement';
        achievementDialog.showModal();
    };

    achievementForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!achievementTarget || !achievementSubmit) return;

        const publicId = encodeURIComponent(achievementTarget.dataset.assessmentSkaterId);
        const skillId = encodeURIComponent(achievementTarget.dataset.skillId);
        achievementSubmit.disabled = true;
        achievementSubmit.textContent = 'Removing…';
        if (achievementMessage) achievementMessage.hidden = true;

        try {
            const response = await fetch(
                `${document.body.dataset.apiBase}/${publicId}/skills/${skillId}`,
                {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: '{}',
                }
            );
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The assessment could not be updated.');

            updateSkillCell(achievementTarget, false);
            achievementDialog.close();
            showSkillToast(result.message);
        } catch (error) {
            setAchievementMessage(error.message);
            achievementSubmit.disabled = false;
            achievementSubmit.textContent = 'Yes, remove achievement';
        }
    });

    document.querySelectorAll('[data-edit-skill]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.achieved === 'true') {
                removeSkillAchievement(button);
            } else {
                markSkillAchieved(button);
            }
        });
    });

    achievementDialog?.addEventListener('click', (event) => {
        if (event.target === achievementDialog) achievementDialog.close();
    });

    const awardEndpoint = (control) => {
        const publicId = encodeURIComponent(control.dataset.awardSkaterId);
        const awardId = encodeURIComponent(control.dataset.awardId);
        const collection = control.dataset.awardType === 'badge' ? 'badges' : 'ribbons';
        return `${document.body.dataset.apiBase}/${publicId}/${collection}/${awardId}`;
    };

    const syncHighestBadgeForRow = (control) => {
        if (control.dataset.awardType !== 'badge') return;
        const row = control.closest('[data-skater-row]');
        if (!row) return;
        const highestBadge = Array.from(
            row.querySelectorAll('[data-award-type="badge"][data-awarded="true"]')
        ).reduce(
            (highest, badge) => Math.max(highest, Number(badge.dataset.stageNumber) || 0),
            0
        );
        row.dataset.highestBadge = String(highestBadge);
        applyRosterFilters();
    };

    const applyOverriddenSkills = (control, skillIds) => {
        if (!Array.isArray(skillIds) || skillIds.length === 0) return;
        const publicId = control.dataset.awardSkaterId;
        skillIds.forEach((skillId) => {
            const skillControl = Array.from(document.querySelectorAll('[data-edit-skill]')).find(
                (button) => button.dataset.assessmentSkaterId === publicId
                    && Number(button.dataset.skillId) === Number(skillId)
            );
            if (skillControl && skillControl.dataset.achieved !== 'true') {
                updateSkillCell(skillControl, true);
            }
        });
    };

    const recordAward = async (control, overrideIneligible = false, bubbleErrors = false) => {
        if (control.disabled || (!overrideIneligible && control.dataset.eligible !== 'true')) return;
        control.disabled = true;
        control.classList.add('is-saving');

        try {
            const response = await fetch(awardEndpoint(control), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({override_ineligible: overrideIneligible}),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The award could not be recorded.');

            control.dataset.awarded = 'true';
            control.dataset.awardedAt = result.awarded_at || new Date().toISOString();
            applyOverriddenSkills(control, result.completed_skill_ids);
            updateStageRibbonAwardProgress(control, 1);
            syncAwardControl(control);
            syncHighestBadgeForRow(control);
            showSkillToast(
                `${control.dataset.awardName} recorded as given to ${control.dataset.awardSkaterName}.`
            );
        } catch (error) {
            showSkillToast(error.message, 'error');
            if (bubbleErrors) throw error;
        } finally {
            control.classList.remove('is-saving');
            syncAwardControl(control);
        }
    };

    const openAwardOverrideDialog = (control, action) => {
        if (overrideConfirmationSuppressedToday()) {
            void action().catch((error) => showSkillToast(error.message, 'error'));
            return;
        }
        if (!awardOverrideDialog || !awardOverrideForm || !awardOverrideSubmit) return;
        awardOverrideTarget = control;
        awardOverrideAction = action;
        if (awardOverrideMessage) awardOverrideMessage.hidden = true;
        if (awardOverrideSuppress) awardOverrideSuppress.checked = false;
        awardOverrideDialog.querySelector('[data-award-override-skater]').textContent =
            control.dataset.awardSkaterName;
        awardOverrideDialog.querySelector('[data-award-override-name]').textContent =
            control.dataset.awardName;
        awardOverrideSubmit.disabled = false;
        awardOverrideSubmit.textContent = 'Award and mark skills achieved';
        awardOverrideDialog.showModal();
    };

    awardOverrideForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!awardOverrideTarget || !awardOverrideAction || !awardOverrideSubmit) return;
        awardOverrideSubmit.disabled = true;
        awardOverrideSubmit.textContent = 'Recording…';
        if (awardOverrideMessage) awardOverrideMessage.hidden = true;
        try {
            if (awardOverrideSuppress?.checked) {
                try {
                    window.localStorage.setItem(awardOverrideStorageKey, localCalendarDay());
                } catch (_) {
                    // The confirmation still succeeds when browser storage is unavailable.
                }
            }
            await awardOverrideAction();
            awardOverrideDialog.close();
        } catch (error) {
            if (awardOverrideMessage) {
                awardOverrideMessage.textContent = error.message;
                awardOverrideMessage.className = 'form-message error';
                awardOverrideMessage.hidden = false;
            }
            awardOverrideSubmit.disabled = false;
            awardOverrideSubmit.textContent = 'Award and mark skills achieved';
        }
    });

    const openAwardRemovalDialog = (control) => {
        if (!awardRemovalDialog || !awardRemovalForm || !awardRemovalSubmit) return;
        awardRemovalTarget = control;
        if (awardRemovalMessage) awardRemovalMessage.hidden = true;
        awardRemovalDialog.querySelector('[data-award-removal-skater]').textContent =
            control.dataset.awardSkaterName;
        awardRemovalDialog.querySelector('[data-award-removal-name]').textContent =
            control.dataset.awardName;
        const awardedAtValue = control.dataset.awardedAt || '';
        const awardedAt = new Date(
            awardedAtValue.includes('T')
                ? awardedAtValue
                : `${awardedAtValue.replace(' ', 'T')}Z`
        );
        const awardAgeMilliseconds = Date.now() - awardedAt.getTime();
        const isRecentCorrection = Number.isFinite(awardAgeMilliseconds)
            && awardAgeMilliseconds >= 0
            && awardAgeMilliseconds <= 15 * 60 * 1000;
        const removalCopy = awardRemovalDialog.querySelector('[data-award-removal-copy]');
        if (removalCopy) {
            removalCopy.textContent = isRecentCorrection
                ? 'The award will no longer be shown as given to the skater.'
                : 'The award will no longer be shown as given to the skater. The award and this removal will remain in the skater’s assessment history.';
        }
        awardRemovalSubmit.disabled = false;
        awardRemovalSubmit.textContent = 'Yes, remove award';
        awardRemovalDialog.showModal();
    };

    awardRemovalForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!awardRemovalTarget || !awardRemovalSubmit) return;
        awardRemovalSubmit.disabled = true;
        awardRemovalSubmit.textContent = 'Removing…';
        if (awardRemovalMessage) awardRemovalMessage.hidden = true;

        try {
            const response = await fetch(awardEndpoint(awardRemovalTarget), {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: '{}',
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The award could not be removed.');

            awardRemovalTarget.dataset.awarded = 'false';
            updateStageRibbonAwardProgress(awardRemovalTarget, -1);
            syncAwardControl(awardRemovalTarget);
            syncHighestBadgeForRow(awardRemovalTarget);
            awardRemovalDialog.close();
            showSkillToast(result.message);
        } catch (error) {
            if (awardRemovalMessage) {
                awardRemovalMessage.textContent = error.message;
                awardRemovalMessage.className = 'form-message error';
                awardRemovalMessage.hidden = false;
            }
            awardRemovalSubmit.disabled = false;
            awardRemovalSubmit.textContent = 'Yes, remove award';
        }
    });

    const achievementEditorDialog = document.querySelector('#achievement-editor-dialog');
    const achievementEditorForm = achievementEditorDialog?.querySelector('[data-achievement-editor-form]');
    const achievementEditorContent = achievementEditorDialog?.querySelector('[data-achievement-editor-content]');
    const achievementEditorTitle = achievementEditorDialog?.querySelector('[data-achievement-editor-title]');
    const achievementEditorMessage = achievementEditorDialog?.querySelector('[data-achievement-editor-message]');
    let achievementEditorData = null;
    let achievementEditorOriginal = null;
    let achievementEditorSkaterId = null;
    let expandedAchievementStages = new Set();
    let achievementEditorFocusRibbonId = null;
    let achievementEditorSaving = false;
    let achievementEditorSavePromise = null;
    let achievementEditorSavedChanges = false;
    let achievementEditorClosing = false;

    const editorSkills = (stage) => stage.ribbons.flatMap((ribbon) => ribbon.skills);
    const editorRibbonSkillCount = (ribbon) => ribbon.is_participation_ribbon
        ? 1
        : ribbon.skills.filter((skill) => !skill.is_participation).length;
    const editorRibbonAchievedCount = (ribbon) => ribbon.skills.filter((skill) => (
        ribbon.is_participation_ribbon ? skill.is_participation : !skill.is_participation
    ) && skill.achieved).length;
    const editorRibbonRequiredCount = (ribbon) => Math.min(
        editorRibbonSkillCount(ribbon),
        Number(ribbon.required_count || editorRibbonSkillCount(ribbon))
    );
    const editorRibbonEligible = (ribbon) => (
        editorRibbonRequiredCount(ribbon) > 0
        && editorRibbonAchievedCount(ribbon) >= editorRibbonRequiredCount(ribbon)
    );
    const editorStageEligible = (stage) => (
        stage.ribbons.length > 0 && stage.ribbons.every((ribbon) => Boolean(ribbon.awarded_at))
    );
    const achievementEditorSnapshot = (stages) => JSON.stringify((stages || []).map((stage) => ({
        id: Number(stage.id),
        badge_awarded: Boolean(stage.badge_awarded_at),
        ribbons: stage.ribbons.map((ribbon) => ({
            id: Number(ribbon.id),
            awarded: Boolean(ribbon.awarded_at),
            skills: ribbon.skills.map((skill) => ({
                id: Number(skill.id),
                achieved: Boolean(skill.achieved),
            })),
        })),
    })));
    const achievementEditorHasUnsavedChanges = () => (
        achievementEditorOriginal !== null
        && achievementEditorData !== null
        && achievementEditorSnapshot(achievementEditorData) !== achievementEditorSnapshot(achievementEditorOriginal)
    );
    const closeAchievementEditor = async () => {
        if (!achievementEditorDialog?.open || achievementEditorClosing) return false;
        achievementEditorClosing = true;
        try {
            await queueAchievementEditorSave();
        } catch {
            achievementEditorClosing = false;
            return false;
        }
        achievementEditorDialog.close();
        if (achievementEditorSavedChanges) window.location.reload();
        return true;
    };
    const setAchievementEditorMessage = (message, type = 'error') => {
        if (!achievementEditorMessage) return;
        achievementEditorMessage.textContent = message;
        achievementEditorMessage.className = `form-message achievement-editor-save-status ${type}`;
        achievementEditorMessage.hidden = false;
    };
    const showParticipationWarning = (input) => {
        achievementEditorContent?.querySelectorAll('.achievement-editor-participation-warning')
            .forEach((warning) => warning.remove());
        const ribbonCard = input.closest('.achievement-editor-modal-ribbon');
        if (!ribbonCard) return;
        const warning = document.createElement('p');
        warning.className = 'form-message error achievement-editor-participation-warning';
        warning.setAttribute('role', 'alert');
        warning.textContent = 'Participation is automatically recorded while any Pre-CanSkate skill is achieved.';
        ribbonCard.insertAdjacentElement('beforebegin', warning);
    };
    const editorBadgeIcon = () => window.CAT.skateCanadaBadgeIcon();
    const editorRibbonIcon = (ribbon, eligible, awarded) => `
        <svg viewBox="0 0 24 28" aria-hidden="true">
            <circle class="ribbon-icon-shape ribbon-icon-centre" cx="12" cy="8" r="7"></circle>
            <path class="ribbon-icon-shape" d="M8 13.2 5.5 26 12 22.2 18.5 26 16 13.2"></path>
            <text class="ribbon-icon-letter" x="12" y="10.7">${escapeHtml(String(ribbon.name || '').charAt(0).toUpperCase())}</text>
        </svg>`;
    const editorRibbonClass = (ribbon) => `ribbon-${String(ribbon.name || '').toLowerCase()}`;

    const renderAchievementEditor = () => {
        if (!achievementEditorContent || !achievementEditorData) return;
        achievementEditorContent.innerHTML = achievementEditorData
            .map((stage) => {
            const stageEligible = editorStageEligible(stage);
            const stageAwarded = Boolean(stage.badge_awarded_at);
            const expanded = expandedAchievementStages.has(Number(stage.id));
            const stageLabel = stage.name || `Stage ${stage.number}`;
            return `
                <section class="achievement-editor-modal-stage stage-colour-${escapeHtml(stage.number)} ${expanded ? 'is-expanded' : ''}">
                    <div class="achievement-editor-modal-stage-row">
                        <button class="achievement-editor-modal-expand" type="button" data-editor-toggle-stage="${escapeHtml(stage.id)}" aria-expanded="${expanded ? 'true' : 'false'}" aria-label="${expanded ? 'Collapse' : 'Expand'} ${escapeHtml(stageLabel)}"><span>${expanded ? '−' : '+'}</span></button>
                        <button class="achievement-editor-modal-stage-label" type="button" data-editor-toggle-stage="${escapeHtml(stage.id)}" aria-expanded="${expanded ? 'true' : 'false'}" aria-label="${expanded ? 'Collapse' : 'Expand'} ${escapeHtml(stageLabel)}"><strong>${escapeHtml(stageLabel)}</strong><small>${stage.ribbons.filter((ribbon) => ribbon.awarded_at).length}/${stage.required_ribbon_count || stage.ribbons.length} ribbons awarded</small></button>
                        ${stage.has_badge ? `<label class="achievement-editor-modal-award-toggle achievement-editor-modal-stage-award"><input type="checkbox" data-editor-award-type="badge" data-editor-award-id="${escapeHtml(stage.id)}" ${stageAwarded ? 'checked' : ''} ${!stageAwarded && !stageEligible ? 'disabled' : ''}><span>Stage badge awarded</span></label><span class="stage-badge-button stage-award-control stage-badge-stage-${escapeHtml(stage.number)} ${stageEligible ? 'is-eligible' : ''} ${stageAwarded ? 'is-awarded' : ''}" aria-label="${escapeHtml(stageLabel)} badge status">${editorBadgeIcon(stage, stageEligible, stageAwarded)}</span>` : ''}
                        <div class="achievement-editor-modal-status-ribbons">
                            ${stage.ribbons.map((ribbon) => {
                                const eligible = editorRibbonEligible(ribbon);
                                const awarded = Boolean(ribbon.awarded_at);
                                const achieved = editorRibbonAchievedCount(ribbon);
                                return `<span class="ribbon-detail-button stage-ribbon-detail ${editorRibbonClass(ribbon)} ${eligible ? 'is-eligible' : ''} ${awarded ? 'is-awarded' : ''}" aria-label="${escapeHtml(`${ribbon.name}: ${achieved} of ${editorRibbonSkillCount(ribbon)} skills achieved; ${editorRibbonRequiredCount(ribbon)} required`)}">${editorRibbonIcon(ribbon, eligible, awarded)}</span>`;
                            }).join('')}
                        </div>
                    </div>
                    <div class="achievement-editor-modal-stage-card" ${expanded ? '' : 'hidden'}>
                        ${stage.ribbons.map((ribbon) => `
                            <section class="achievement-editor-modal-ribbon" data-editor-ribbon-section="${escapeHtml(ribbon.id)}">
                                <div class="achievement-editor-modal-ribbon-head"><h3>${escapeHtml(ribbon.name)} <small>${editorRibbonRequiredCount(ribbon)}/${editorRibbonSkillCount(ribbon)} required</small></h3><label class="achievement-editor-modal-award-toggle"><input type="checkbox" data-editor-award-type="ribbon" data-editor-award-id="${escapeHtml(ribbon.id)}" ${ribbon.awarded_at ? 'checked' : ''} ${!ribbon.awarded_at && !editorRibbonEligible(ribbon) ? 'disabled' : ''}><span>Ribbon awarded</span></label></div>
                                <div class="achievement-editor-modal-skills">
                                    ${ribbon.skills.map((skill) => `<label class="achievement-editor-modal-skill ${skill.is_participation ? 'is-participation' : ''} ${skill.achieved ? 'is-achieved' : ''}"><input type="checkbox" data-editor-skill-id="${escapeHtml(skill.id)}" ${skill.achieved ? 'checked' : ''}><span>${escapeHtml(skill.name)}</span></label>`).join('')}
                                </div>
                            </section>`).join('')}
                    </div>
                </section>`;
            }).join('');
        if (achievementEditorFocusRibbonId !== null) {
            const ribbonSection = achievementEditorContent.querySelector(
                `[data-editor-ribbon-section="${achievementEditorFocusRibbonId}"]`
            );
            ribbonSection?.scrollIntoView({block: 'nearest'});
            achievementEditorFocusRibbonId = null;
        }
    };

    const findEditorSkill = (skillId) => {
        for (const stage of achievementEditorData || []) {
            for (const ribbon of stage.ribbons) {
                const skill = ribbon.skills.find((candidate) => Number(candidate.id) === Number(skillId));
                if (skill) return skill;
            }
        }
        return null;
    };
    const findEditorAward = (type, id) => {
        for (const stage of achievementEditorData || []) {
            if (type === 'badge' && Number(stage.id) === Number(id)) return stage;
            if (type === 'ribbon') {
                const ribbon = stage.ribbons.find((candidate) => Number(candidate.id) === Number(id));
                if (ribbon) return ribbon;
            }
        }
        return null;
    };
    const setEditorAwarded = (type, award, awarded) => {
        if (type === 'badge') award.badge_awarded_at = awarded ? 'pending' : null;
        else award.awarded_at = awarded ? 'pending' : null;
    };

    const openAchievementEditor = async (control) => {
        if (!achievementEditorDialog || !achievementEditorContent) return;
        const publicId = control.dataset.awardSkaterId;
        achievementEditorSkaterId = publicId;
        achievementEditorData = null;
        achievementEditorOriginal = null;
        achievementEditorSaving = false;
        achievementEditorSavePromise = null;
        achievementEditorSavedChanges = false;
        achievementEditorClosing = false;
        expandedAchievementStages = new Set();
        achievementEditorFocusRibbonId = null;
        if (achievementEditorMessage) achievementEditorMessage.hidden = true;
        achievementEditorTitle.textContent = `${control.dataset.awardSkaterName} — Achievements`;
        achievementEditorContent.innerHTML = '<div class="loading-state"><span></span><p>Loading achievements…</p></div>';
        achievementEditorDialog.showModal();
        try {
            const response = await fetch(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}`, {
                headers: {Accept: 'application/json'},
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Achievements could not be loaded.');
            achievementEditorData = data.achievement_editor || [];
            achievementEditorOriginal = JSON.parse(JSON.stringify(achievementEditorData));
            if (control.dataset.awardType === 'badge') {
                expandedAchievementStages.add(Number(control.dataset.awardId));
            } else {
                const ribbonId = Number(control.dataset.awardId);
                const stage = achievementEditorData.find((candidate) => candidate.ribbons.some(
                    (ribbon) => Number(ribbon.id) === ribbonId
                ));
                if (stage) {
                    expandedAchievementStages.add(Number(stage.id));
                    achievementEditorFocusRibbonId = ribbonId;
                }
            }
            renderAchievementEditor();
        } catch (error) {
            achievementEditorContent.innerHTML = `<p class="empty-copy">${escapeHtml(error.message)}</p>`;
        }
    };

    achievementEditorContent?.addEventListener('click', (event) => {
        const expandButton = event.target.closest('[data-editor-toggle-stage], [data-editor-expand-stage]');
        if (!expandButton) return;
        const stageId = Number(expandButton.dataset.editorToggleStage || expandButton.dataset.editorExpandStage);
        if (expandedAchievementStages.has(stageId)) expandedAchievementStages.delete(stageId);
        else expandedAchievementStages.add(stageId);
        renderAchievementEditor();
    });

    achievementEditorContent?.addEventListener('change', (event) => {
        const skillInput = event.target.closest('[data-editor-skill-id]');
        if (skillInput) {
            const skill = findEditorSkill(skillInput.dataset.editorSkillId);
            if (skill) {
                const stage = achievementEditorData.find((candidate) => editorSkills(candidate).includes(skill));
                const participationRequired = skill.is_participation
                    && !skillInput.checked
                    && stage && editorSkills(stage).some((candidate) => !candidate.is_participation && candidate.achieved);
                if (participationRequired) {
                    skillInput.checked = true;
                    showParticipationWarning(skillInput);
                    return;
                }
                skill.achieved = skillInput.checked;
                if (skill.achieved && !skill.is_participation) {
                    const participation = stage && editorSkills(stage).find((candidate) => candidate.is_participation);
                    // The service records Participation automatically for Pre-CanSkate;
                    // mirror that rule immediately so the open editor stays in sync.
                    if (participation) participation.achieved = true;
                }
                if (!skill.achieved) {
                    achievementEditorData.forEach((stage) => {
                        const ribbon = stage.ribbons.find((candidate) => candidate.skills.includes(skill));
                        if (ribbon?.awarded_at === 'pending') ribbon.awarded_at = null;
                        if (editorSkills(stage).includes(skill) && stage.badge_awarded_at === 'pending') {
                            stage.badge_awarded_at = null;
                        }
                    });
                }
                renderAchievementEditor();
                void queueAchievementEditorSave().catch(() => {});
            }
            return;
        }
        const awardInput = event.target.closest('[data-editor-award-type]');
        if (!awardInput) return;
        const type = awardInput.dataset.editorAwardType;
        const award = findEditorAward(type, awardInput.dataset.editorAwardId);
        if (!award) return;
        setEditorAwarded(type, award, awardInput.checked);
        renderAchievementEditor();
        void queueAchievementEditorSave().catch(() => {});
    });

    const saveAchievementEditor = async (originalData, updatedData) => {
        const publicId = achievementEditorSkaterId;
        if (!publicId) throw new Error('The skater could not be identified.');
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const request = async (url, method, body = {}) => {
            const response = await fetch(url, {
                method,
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify(body),
            });
            const result = await response.json();
            if (!response.ok) {
                const message = result.diagnostic || result.error || 'The achievements could not be saved.';
                // A previous editor request may already have saved this achievement
                // before a later queued save is processed. The requested end state is
                // already present, so this response is safe to treat as completed.
                if (
                    method === 'POST'
                    && (
                        (/\/skills\//.test(url) && message === 'This skill is already marked achieved.')
                        || (/\/ribbons\//.test(url) && message === 'This ribbon is already marked awarded.')
                        || (/\/badges\//.test(url) && message === 'This stage badge is already marked awarded.')
                    )
                ) {
                    return;
                }
                throw new Error(message);
            }
        };
        const originalSkills = new Map(
            originalData.flatMap(editorSkills)
                .map((skill) => [Number(skill.id), skill.achieved])
        );
        for (const skill of updatedData.flatMap(editorSkills)) {
            if (originalSkills.get(Number(skill.id)) === skill.achieved) continue;
            await request(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}/skills/${encodeURIComponent(skill.id)}`, skill.achieved ? 'POST' : 'DELETE');
        }
        for (const stage of updatedData) {
            const originalStage = originalData.find((candidate) => Number(candidate.id) === Number(stage.id));
            for (const ribbon of stage.ribbons) {
                const originalRibbon = originalStage.ribbons.find((candidate) => Number(candidate.id) === Number(ribbon.id));
                if (Boolean(originalRibbon.awarded_at) && !Boolean(ribbon.awarded_at)) {
                    await request(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}/ribbons/${encodeURIComponent(ribbon.id)}`, 'DELETE');
                }
            }
            if (Boolean(originalStage.badge_awarded_at) && !Boolean(stage.badge_awarded_at)) {
                await request(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}/badges/${encodeURIComponent(stage.id)}`, 'DELETE');
            }
        }
        for (const stage of updatedData) {
            const originalStage = originalData.find((candidate) => Number(candidate.id) === Number(stage.id));
            for (const ribbon of stage.ribbons) {
                const originalRibbon = originalStage.ribbons.find((candidate) => Number(candidate.id) === Number(ribbon.id));
                if (!Boolean(originalRibbon.awarded_at) && Boolean(ribbon.awarded_at)) {
                    await request(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}/ribbons/${encodeURIComponent(ribbon.id)}`, 'POST');
                }
            }
            if (!Boolean(originalStage.badge_awarded_at) && Boolean(stage.badge_awarded_at)) {
                await request(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}/badges/${encodeURIComponent(stage.id)}`, 'POST');
            }
        }
    };

    const queueAchievementEditorSave = () => {
        if (!achievementEditorData || !achievementEditorOriginal) return Promise.resolve();
        if (achievementEditorSavePromise) return achievementEditorSavePromise;
        if (!achievementEditorHasUnsavedChanges()) return Promise.resolve();
        achievementEditorSavePromise = (async () => {
            achievementEditorSaving = true;
            while (achievementEditorHasUnsavedChanges()) {
                const originalData = JSON.parse(JSON.stringify(achievementEditorOriginal));
                const updatedData = JSON.parse(JSON.stringify(achievementEditorData));
                setAchievementEditorMessage('Saving changes…', 'info');
                await saveAchievementEditor(originalData, updatedData);
                achievementEditorOriginal = updatedData;
                achievementEditorSavedChanges = true;
            }
            setAchievementEditorMessage('All changes saved.', 'success');
        })().catch((error) => {
            setAchievementEditorMessage(`${error.message} Your changes remain in this window; try again.`, 'error');
            throw error;
        }).finally(() => {
            achievementEditorSaving = false;
            achievementEditorSavePromise = null;
        });
        return achievementEditorSavePromise;
    };
    achievementEditorDialog?.querySelectorAll('[data-close-achievement-editor]').forEach((control) => {
        control.addEventListener('click', (event) => {
            event.preventDefault();
            closeAchievementEditor();
        });
    });
    achievementEditorDialog?.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeAchievementEditor();
    });
    achievementEditorDialog?.addEventListener('click', (event) => {
        if (event.target === achievementEditorDialog) closeAchievementEditor();
    });
    window.addEventListener('beforeunload', (event) => {
        if (!achievementEditorDialog?.open || (!achievementEditorSaving && !achievementEditorHasUnsavedChanges())) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.querySelectorAll('[data-award-control]').forEach((control) => {
        syncAwardControl(control);
        control.addEventListener('click', () => openAchievementEditor(control));
    });

    const detailRow = (label, value, extraClass = '') => `
        <div class="detail-row ${extraClass}">
            <span>${escapeHtml(label)}</span>
            <strong>${value ? escapeHtml(value) : '—'}</strong>
        </div>
    `;

    const inputField = (name, label, value, type = 'text', attributes = '') => `
        <label>
            <span>${escapeHtml(label)}</span>
            <input type="${type}" name="${escapeHtml(name)}" value="${escapeHtml(value ?? '')}" ${attributes}>
        </label>
    `;

    const renderDetail = (data) => {
        const skater = data.skater;
        const permissions = data.permissions;
        const activeEnrollmentBySession = new Map(
            data.enrollments
                .filter((enrollment) => Number(enrollment.active) === 1)
                .map((enrollment) => [Number(enrollment.session_id), enrollment])
        );
        const registrationEditor = permissions.can_edit
            ? `
                <form class="registration-editor" data-session-registration-edit>
                    <p class="registration-editor-copy">Select the sessions this skater attends and choose a group colour for each one.</p>
                    <div class="registration-editor-list">
                        ${(data.registration_options || []).map((session) => {
                            const enrollment = activeEnrollmentBySession.get(Number(session.session_id));
                            const selectedGroupId = enrollment?.group_id === null || enrollment?.group_id === undefined
                                ? ''
                                : String(enrollment.group_id);
                            return `
                                <label class="registration-editor-row">
                                    <input
                                        type="checkbox"
                                        value="${escapeHtml(session.session_id)}"
                                        data-registration-session
                                        ${enrollment ? 'checked' : ''}
                                    >
                                    <span class="registration-session-copy">
                                        <strong>${escapeHtml(session.session_name)}</strong>
                                        <small>${escapeHtml(session.season_name)} · ${['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][Number(session.day_of_week)] ?? ''} · ${formatTime(session.start_time)}–${formatTime(session.end_time)}</small>
                                    </span>
                                    <span class="registration-group-select">
                                        <i data-registration-group-swatch></i>
                                        <select data-registration-group ${enrollment ? '' : 'disabled'} aria-label="Group colour for ${escapeHtml(session.session_name)}">
                                            <option value="" data-group-colour="#94a3b8">No group colour</option>
                                            ${(session.groups || []).map((group) => `
                                                <option
                                                    value="${escapeHtml(group.group_id)}"
                                                    data-group-colour="${escapeHtml(group.group_colour || '#94a3b8')}"
                                                    ${selectedGroupId === String(group.group_id) ? 'selected' : ''}
                                                >${escapeHtml(group.group_name)}</option>
                                            `).join('')}
                                        </select>
                                    </span>
                                </label>
                            `;
                        }).join('') || '<p class="empty-copy">No active sessions are available.</p>'}
                    </div>
                    <div class="form-message" data-registration-message hidden></div>
                    <button class="button button-primary" type="submit">Save sessions & groups</button>
                </form>
            `
            : '';
        const enrollments = data.enrollments.map((enrollment) => `
            <article class="session-card">
                <div class="session-card-head">
                    <div>
                        <strong>${escapeHtml(enrollment.session_name)}</strong>
                        <span>${escapeHtml(enrollment.season_name)}</span>
                    </div>
                    <span class="status-badge ${Number(enrollment.active) === 1 ? 'active' : ''}">
                        ${Number(enrollment.active) === 1 ? 'Registered' : 'Inactive'}
                    </span>
                </div>
                <div class="session-meta">
                    <span>${['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][Number(enrollment.day_of_week)] ?? ''} · ${formatTime(enrollment.start_time)}–${formatTime(enrollment.end_time)}</span>
                    <span>${escapeHtml(enrollment.location || 'Location not set')}</span>
                </div>
                <div class="group-line">
                    <i style="--group-colour: ${escapeHtml(enrollment.group_colour || '#94a3b8')}"></i>
                    Group: <strong>${escapeHtml(enrollment.group_name || 'Not assigned')}</strong>
                </div>
            </article>
        `).join('');

        const attendanceSummary = data.attendance.length
            ? data.attendance.map((row) => `
                <div class="attendance-summary-row">
                    <span>${escapeHtml(row.session_name)}</span>
                    <strong>${escapeHtml(row.present)}/${escapeHtml(row.recorded)}</strong>
                    <b>${row.rate === null ? '—' : `${escapeHtml(row.rate)}%`}</b>
                </div>
            `).join('')
            : '<p class="empty-copy">No attendance has been recorded yet.</p>';

        const recentAttendance = data.recent_attendance.length
            ? `
                <div class="attendance-list">
                    ${data.recent_attendance.map((row) => `
                        <div>
                            <span>${formatDate(row.session_date)}</span>
                            <strong>${escapeHtml(row.session_name)}</strong>
                            <em class="attendance-status status-${escapeHtml(String(row.status_code).toLowerCase())}">${escapeHtml(row.status_name)}</em>
                        </div>
                    `).join('')}
                </div>
            `
            : '';

        const assessmentHistory = (data.assessments || []).length
            ? `
                <div class="assessment-history">
                    ${data.assessments.map((assessment) => {
                        const wasRemoved = assessment.result_code === 'REVOKED'
                            || assessment.history_type === 'record'
                                && assessment.result_code === 'DELETED'
                            || String(assessment.notes || '').startsWith('[Achievement removed]');
                        const note = wasRemoved
                            ? String(assessment.notes || '').replace('[Achievement removed]', '').trim()
                            : String(assessment.notes || '').replace('[Manual administrator update]', '').trim();
                        const resultName = wasRemoved && assessment.history_type === 'skill'
                            ? 'Achievement removed'
                            : assessment.result_name;
                        const assessmentContext = assessment.session_name
                            ? `
                                ${escapeHtml(assessment.session_name)}
                                · lesson ${formatDate(assessment.session_date)}
                                ${assessment.coach_name ? ` · coach ${escapeHtml(assessment.coach_name)}` : ''}
                            `
                            : assessment.history_type === 'ribbon'
                                ? 'Manual ribbon update'
                                : assessment.history_type === 'badge'
                                    ? 'Manual stage badge update'
                                    : assessment.history_type === 'record'
                                        ? 'Skater record change'
                                    : 'Manual administrator update';
                        return `
                            <article class="assessment-history-row history-${escapeHtml(assessment.history_type)} ${wasRemoved ? 'is-removed' : ''}">
                                <span class="assessment-result">${escapeHtml(resultName)}</span>
                                <strong>${escapeHtml(assessment.skill_name)}</strong>
                                <small>
                                    ${formatDateTime(assessment.assessed_at)}
                                    · entered by <b>${escapeHtml(assessment.entered_by_username)}</b>
                                </small>
                                <small>
                                    ${assessmentContext}
                                </small>
                                ${note ? `<p>${escapeHtml(note)}</p>` : ''}
                            </article>
                        `;
                    }).join('')}
                </div>
            `
            : '<p class="empty-copy">No assessment, award, or record history has been recorded yet.</p>';

        const achievementEditor = '';
        /* Legacy drawer editor retained below for reference while achievement editing
           is handled by the staged modal opened from a badge or ribbon. */
        const legacyAchievementEditor = permissions.can_edit
            ? `
                <details class="drawer-section audit-details achievement-editor-details" data-achievement-editor>
                    <summary class="audit-summary">
                        <div><h3>ACHIEVEMENTS</h3></div>
                        <span class="audit-toggle" aria-hidden="true"></span>
                    </summary>
                    <div class="audit-details-content achievement-editor-content">
                        <p class="achievement-editor-copy">Select a skill to mark it achieved or remove it. Awards unlock when their skills are complete.</p>
                        ${(data.achievement_editor || []).map((stage) => `
                            <details class="achievement-editor-stage" data-achievement-editor-stage="${escapeHtml(stage.id)}">
                                <summary>
                                    <strong>Stage ${escapeHtml(stage.number)}</strong>
                                    <span>${escapeHtml(stage.achieved_count)}/${escapeHtml(stage.skill_count)}</span>
                                </summary>
                                <div class="achievement-editor-stage-content">
                                    <div class="achievement-editor-awards">
                                        <button
                                            class="achievement-editor-award ${stage.badge_awarded_at ? 'is-awarded' : ''}"
                                            type="button"
                                            data-drawer-award-control
                                            data-award-type="badge"
                                            data-award-id="${escapeHtml(stage.id)}"
                                            data-award-name="${escapeHtml(stage.name)} badge"
                                            data-award-skater-id="${escapeHtml(skater.public_id)}"
                                            data-award-skater-name="${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)}"
                                            data-awarded="${stage.badge_awarded_at ? 'true' : 'false'}"
                                            data-eligible="${stage.eligible ? 'true' : 'false'}"
                                            data-can-edit="${canOverrideAwards ? 'true' : 'false'}"
                                            data-can-override="${canOverrideAwards ? 'true' : 'false'}"
                                            title="${escapeHtml(stage.name)} badge"
                                            ${!canOverrideAwards ? 'disabled' : ''}
                                        >${stage.badge_awarded_at ? '✓ ' : ''}${escapeHtml(stage.name)} badge</button>
                                        ${stage.ribbons.map((ribbon) => `
                                            <button
                                                class="achievement-editor-award ${ribbon.awarded_at ? 'is-awarded' : ''}"
                                                type="button"
                                                data-drawer-award-control
                                                data-award-type="ribbon"
                                                data-award-id="${escapeHtml(ribbon.id)}"
                                                data-award-name="${escapeHtml(stage.name)} ${escapeHtml(ribbon.name)} ribbon"
                                                data-award-skater-id="${escapeHtml(skater.public_id)}"
                                                data-award-skater-name="${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)}"
                                                data-awarded="${ribbon.awarded_at ? 'true' : 'false'}"
                                                data-eligible="${ribbon.eligible ? 'true' : 'false'}"
                                                data-can-edit="${canOverrideAwards ? 'true' : 'false'}"
                                                data-can-override="${canOverrideAwards ? 'true' : 'false'}"
                                                title="${escapeHtml(ribbon.name)} ribbon: ${escapeHtml(ribbon.achieved_count)}/${escapeHtml(ribbon.skills.length)} skills"
                                                ${!canOverrideAwards ? 'disabled' : ''}
                                            >${ribbon.awarded_at ? '✓ ' : ''}${escapeHtml(ribbon.name)} ${escapeHtml(ribbon.achieved_count)}/${escapeHtml(ribbon.skills.length)}</button>
                                        `).join('')}
                                    </div>
                                    <div class="achievement-editor-skills">
                                        ${stage.ribbons.flatMap((ribbon) => ribbon.skills.map((skill) => `
                                            <button
                                                class="achievement-editor-skill ${skill.achieved ? 'is-achieved' : ''}"
                                                type="button"
                                                data-drawer-skill-control
                                                data-skill-id="${escapeHtml(skill.id)}"
                                                data-skill-name="${escapeHtml(skill.name)}"
                                                data-achieved="${skill.achieved ? 'true' : 'false'}"
                                                title="${skill.achieved ? 'Remove achievement for' : 'Mark achieved:'} ${escapeHtml(skill.name)}"
                                            ><b aria-hidden="true">${skill.achieved ? '✓' : ''}</b><span><em>${escapeHtml(String(ribbon.name || '').charAt(0).toUpperCase())}:</em> ${escapeHtml(skill.name)}</span></button>
                                        `)).join('')}
                                    </div>
                                </div>
                            </details>
                        `).join('') || '<p class="empty-copy">No active CanSkate skills are available.</p>'}
                        <div class="form-message" data-drawer-achievement-message hidden></div>
                    </div>
                </details>
            `
            : '';

        const contactContent = permissions.can_view_sensitive
            ? `
                ${detailRow('Guardian', skater.parent_guardian_name)}
                ${detailRow('Email', skater.parent_guardian_email)}
                ${detailRow('Phone', skater.parent_guardian_phone)}
                ${detailRow('General notes', skater.general_notes, 'detail-row-block')}
                ${detailRow('Accommodations/Medical notes', skater.medical_notes, 'detail-row-block')}
            `
            : '<p class="restricted-note">Guardian and medical information is restricted to administrators and editors.</p>';

        const readOnlyProfile = permissions.can_edit
            ? ''
            : `
                <section class="drawer-section">
                    <div class="section-heading"><div><span class="eyebrow">Profile</span><h3>Skater information</h3></div></div>
                    <div class="detail-grid">
                        ${detailRow('Date of birth', formatDate(skater.date_of_birth))}
                        ${detailRow('Gender', skater.gender_name)}
                    </div>
                </section>
            `;
        const readOnlyContact = permissions.can_edit
            ? ''
            : `
                <section class="drawer-section">
                    <div class="section-heading"><div><span class="eyebrow">Private</span><h3>Guardian & contact</h3></div></div>
                    <div class="detail-grid">${contactContent}</div>
                </section>
            `;
        const genderField = inputField(
            'gender_text',
            'Gender',
            skater.gender_text || skater.gender_name || '',
            'text',
            'maxlength="80"'
        );

        const editForm = permissions.can_edit
            ? `
                <section class="drawer-section">
                    <div class="section-heading">
                        <div><h3>EDIT SKATER RECORD</h3></div>
                    </div>
                    <form class="drawer-edit-form" data-skater-edit>
                        <input type="hidden" name="updated_at" value="${escapeHtml(skater.updated_at || '')}">
                        <div class="form-grid">
                            ${inputField('first_name', 'First name', skater.first_name)}
                            ${inputField('last_name', 'Last name', skater.last_name)}
                            ${inputField(
                                'skate_canada_number',
                                'Skate Canada no. (10 letters/numbers)',
                                skater.skate_canada_number,
                                'text',
                                'maxlength="10" pattern="[A-Za-z0-9]{10}" autocapitalize="characters"'
                            )}
                            ${inputField('date_of_birth', 'Date of birth', skater.date_of_birth, 'date')}
                            ${genderField}
                            ${inputField('parent_guardian_name', 'Guardian name', skater.parent_guardian_name)}
                            ${inputField('parent_guardian_email', 'Guardian email', skater.parent_guardian_email, 'email')}
                            ${inputField('parent_guardian_phone', 'Guardian phone', skater.parent_guardian_phone, 'tel')}
                        </div>
                        <label>
                            <span>General notes</span>
                            <textarea name="general_notes" rows="3">${escapeHtml(skater.general_notes ?? '')}</textarea>
                        </label>
                        <label>
                            <span>Accommodations/Medical notes</span>
                            <textarea name="medical_notes" rows="3">${escapeHtml(skater.medical_notes ?? '')}</textarea>
                        </label>
                        <label class="checkbox-field">
                            <input type="checkbox" name="active" ${Number(skater.active) === 1 ? 'checked' : ''}>
                            <span>Active skater record</span>
                        </label>
                        <div class="form-message" data-form-message hidden></div>
                        <div class="drawer-form-actions">
                            <button class="button button-primary" type="submit">Save changes</button>
                            <button class="button button-danger" type="button" data-delete-skater>Delete Skater</button>
                        </div>
                    </form>
                </section>
            `
            : '';

        drawerTitle.textContent = 'Skater record';
        drawerContent.innerHTML = `
            <section class="drawer-identity">
                <div class="large-avatar">${escapeHtml(skater.first_name.charAt(0))}${escapeHtml(skater.last_name.charAt(0))}</div>
                <div>
                    <strong>${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)}</strong>
                    <span>${escapeHtml(skater.skate_canada_number || '[unassigned]')}</span>
                </div>
                <span class="status-badge ${Number(skater.active) === 1 ? 'active' : ''}">${Number(skater.active) === 1 ? 'Active' : 'Inactive'}</span>
            </section>

            ${readOnlyProfile}
            ${readOnlyContact}

            <details class="drawer-section audit-details registration-details">
                <summary class="audit-summary">
                    <div><h3>SESSIONS &amp; GROUPS</h3></div>
                    <span class="count-pill">${data.enrollments.length}</span>
                    <span class="audit-toggle" aria-hidden="true"></span>
                </summary>
                <div class="audit-details-content">
                    ${registrationEditor || `<div class="session-list">${enrollments || '<p class="empty-copy">No session registrations found.</p>'}</div>`}
                </div>
            </details>

            <details class="drawer-section audit-details attendance-details">
                <summary class="audit-summary">
                    <div><h3>ATTENDANCE</h3></div>
                    <span class="audit-toggle" aria-hidden="true"></span>
                </summary>
                <div class="audit-details-content">
                    <div class="attendance-summary">${attendanceSummary}</div>
                    ${recentAttendance}
                </div>
            </details>

            ${achievementEditor}

            <details class="drawer-section audit-details">
                <summary class="audit-summary">
                    <div><h3>ASSESSMENT HISTORY</h3></div>
                    <span class="count-pill">${(data.assessments || []).length}</span>
                    <span class="audit-toggle" aria-hidden="true"></span>
                </summary>
                <div class="audit-details-content">${assessmentHistory}</div>
            </details>
            ${editForm}
        `;

        const form = drawerContent.querySelector('[data-skater-edit]');
        form?.addEventListener('submit', saveSkater);
        drawerContent.querySelector('[data-delete-skater]')?.addEventListener('click', () => {
            openSkaterDeletionDialog(
                [skater.public_id],
                'Delete Skater',
                `Delete ${skater.first_name} ${skater.last_name} from the live roster?`
            );
        });
        const registrationForm = drawerContent.querySelector('[data-session-registration-edit]');
        registrationForm?.addEventListener('submit', saveRegistrations);
        registrationForm?.querySelectorAll('[data-registration-session]').forEach((checkbox) => {
            checkbox.addEventListener('change', syncRegistrationControls);
        });
        registrationForm?.querySelectorAll('[data-registration-group]').forEach((select) => {
            select.addEventListener('change', syncRegistrationGroupSwatch);
        });
        drawerContent.querySelectorAll('[data-drawer-skill-control]').forEach((control) => {
            control.addEventListener('click', () => updateDrawerSkillAchievement(control));
        });
        drawerContent.querySelectorAll('[data-drawer-award-control]').forEach((control) => {
            control.addEventListener('click', () => updateDrawerAward(control));
        });
        syncRegistrationControls();
    };

    const openDrawer = async (publicId) => {
        if (!drawer || !drawerContent || !backdrop) return;
        closeProgressSettings();
        activeSkaterId = publicId;
        drawer.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('drawer-open');
        drawerTitle.textContent = 'Skater record';
        drawerContent.innerHTML = '<div class="loading-state"><span></span><p>Loading skater details…</p></div>';

        try {
            const response = await fetch(`${document.body.dataset.apiBase}/${encodeURIComponent(publicId)}`, {
                headers: {Accept: 'application/json'},
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Could not load the skater.');
            renderDetail(data);
        } catch (error) {
            drawerContent.innerHTML = `<div class="drawer-error"><strong>Unable to load details</strong><p>${escapeHtml(error.message)}</p></div>`;
        }
    };

    const closeDrawer = () => {
        if (!drawer || !backdrop) return;
        drawer.hidden = true;
        backdrop.hidden = true;
        activeSkaterId = null;
        document.body.classList.remove('drawer-open');
    };

    const openAddSkaterDrawer = () => {
        if (!drawer || !drawerContent || !backdrop) return;
        closeProgressSettings();
        activeSkaterId = null;
        drawer.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('drawer-open');
        drawerTitle.textContent = 'Add skater';
        const sessionOptions = availableSessions.map((session) => `
            <label class="checkbox-field">
                <input type="checkbox" value="${escapeHtml(session.id)}" data-add-registration>
                <span>${escapeHtml(session.season_name)} · ${escapeHtml(session.name)}</span>
            </label>
        `).join('');
        drawerContent.innerHTML = `
            <form class="drawer-edit-form add-skater-form" data-add-skater>
                <div class="form-grid">
                    ${inputField('first_name', 'First name', '', 'text', 'required maxlength="100" autocomplete="given-name"')}
                    ${inputField('last_name', 'Last name', '', 'text', 'required maxlength="100" autocomplete="family-name"')}
                    ${inputField('skate_canada_number', 'Skate Canada no. (optional)', '', 'text', 'maxlength="10" pattern="[A-Za-z0-9]{10}" autocapitalize="characters"')}
                    ${inputField('date_of_birth', 'Date of birth', '', 'date', 'required')}
                    ${inputField('gender_text', 'Gender', '', 'text', 'maxlength="80"')}
                    ${inputField('parent_guardian_name', 'Guardian name', '', 'text', 'maxlength="200"')}
                    ${inputField('parent_guardian_email', 'Guardian email', '', 'email', 'maxlength="254"')}
                    ${inputField('parent_guardian_phone', 'Guardian phone', '', 'tel', 'maxlength="40"')}
                </div>
                <fieldset class="drawer-registration-picker">
                    <legend>Register in programs</legend>
                    <p>Select any sessions for this skater. This is optional; programs can also be assigned later.</p>
                    <div class="drawer-registration-options">
                        ${sessionOptions || '<span class="empty-copy">No active sessions are available.</span>'}
                    </div>
                </fieldset>
                <label>
                    <span>General notes</span>
                    <textarea name="general_notes" rows="3"></textarea>
                </label>
                <label>
                    <span>Accommodations/Medical notes</span>
                    <textarea name="medical_notes" rows="3"></textarea>
                </label>
                <div class="form-message" data-add-skater-message hidden></div>
                <button class="button button-primary" type="submit">Add skater</button>
            </form>
        `;
        drawerContent.querySelector('[data-add-skater]')?.addEventListener('submit', saveNewSkater);
    };

    const openSkaterDeletionDialog = (skaterIds, title, copy) => {
        if (!deleteSkatersDialog || skaterIds.length === 0) return;
        pendingDeletionSkaterIds = [...new Set(skaterIds)];
        deleteSkatersForm?.reset();
        if (deleteDialogTitle) deleteDialogTitle.textContent = title;
        if (deleteDialogCopy) deleteDialogCopy.textContent = copy;
        if (confirmDeleteSkatersButton) {
            confirmDeleteSkatersButton.textContent = title;
            confirmDeleteSkatersButton.disabled = true;
        }
        if (deleteMessage) deleteMessage.hidden = true;
        deleteSkatersDialog.showModal();
    };

    const bindInsertedRosterRow = (row) => {
        const selectionInput = row.querySelector('[data-select-skater]');
        if (selectionInput) {
            selectionInput.addEventListener('click', () => {
                skaterSelectionAnchor = selectionInput;
                syncSelectAllSkatersState();
            });
            selectionInput.addEventListener('change', syncSelectAllSkatersState);
        }
        row.querySelectorAll('.sticky-first-name, .sticky-last-name').forEach((cell) => {
            cell.addEventListener('click', () => selectionInput?.click());
        });
        row.querySelectorAll('[data-skater-id]').forEach((button) => {
            button.addEventListener('click', () => openDrawer(button.dataset.skaterId));
        });
        row.querySelectorAll('[data-edit-skill]').forEach((button) => {
            button.addEventListener('click', () => {
                if (button.dataset.achieved === 'true') {
                    removeSkillAchievement(button);
                } else {
                    markSkillAchieved(button);
                }
            });
        });
        row.querySelectorAll('[data-award-control]').forEach((control) => {
            syncAwardControl(control);
            control.addEventListener('click', () => openAchievementEditor(control));
        });
    };

    const addNewSkaterToCurrentRoster = async (publicId) => {
        if (!rosterBody) return false;
        const requestUrl = new URL(window.location.href);
        requestUrl.searchParams.set('focus_skater', publicId);
        const response = await fetch(requestUrl.toString(), {headers: {Accept: 'text/html'}});
        if (!response.ok) return false;

        const documentSnapshot = new DOMParser().parseFromString(await response.text(), 'text/html');
        const sourceRow = Array.from(documentSnapshot.querySelectorAll('[data-skater-row]')).find(
            (row) => row.querySelector('[data-select-skater]')?.value === publicId
        );
        if (!sourceRow) return false;

        const existingRow = skaterRows.find(
            (row) => row.querySelector('[data-select-skater]')?.value === publicId
        );
        if (existingRow) existingRow.remove();

        const newRow = document.importNode(sourceRow, true);
        rosterBody.append(newRow);
        skaterRows = skaterRows.filter((row) => row !== existingRow);
        skaterRows.push(newRow);
        const newSelectionInput = newRow.querySelector('[data-select-skater]');
        skaterSelectionInputs = skaterSelectionInputs.filter((input) => input !== existingRow?.querySelector('[data-select-skater]'));
        if (newSelectionInput) skaterSelectionInputs.push(newSelectionInput);
        try {
            rosterFilterMaps.set(newRow, JSON.parse(newRow.dataset.rosterFilterMap || '{}'));
        } catch {
            rosterFilterMaps.set(newRow, {});
        }
        bindInsertedRosterRow(newRow);
        applyRosterFilters();
        return !newRow.hidden;
    };

    const saveNewSkater = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[data-add-skater-message]');
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.registrations = Array.from(form.querySelectorAll('[data-add-registration]:checked')).map((input) => ({
            session_id: Number(input.value),
            group_id: null,
        }));

        button.disabled = true;
        button.textContent = 'Adding…';
        message.hidden = true;

        try {
            const response = await fetch(document.body.dataset.apiBase, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The skater could not be added.');
            let addedToRoster = false;
            try {
                addedToRoster = await addNewSkaterToCurrentRoster(result.public_id);
            } catch {
                // The record was created successfully; the user can still work in its drawer.
            }
            await openDrawer(result.public_id);
            showSkillToast(addedToRoster
                ? 'Skater added to the current dashboard view.'
                : 'Skater added. It does not match the current dashboard filters.');
        } catch (error) {
            message.textContent = error.message;
            message.className = 'form-message error';
            message.hidden = false;
            button.disabled = false;
            button.textContent = 'Add skater';
        }
    };

    const deleteSkaters = async (event) => {
        event.preventDefault();
        if (pendingDeletionSkaterIds.length === 0) return;
        const confirmation = String(deleteConfirmationInput?.value || '');
        const button = confirmDeleteSkatersButton;
        if (!button) return;

        button.disabled = true;
        button.textContent = 'Deleting…';
        if (deleteMessage) deleteMessage.hidden = true;

        try {
            const response = await fetch(document.body.dataset.apiBase, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    skater_ids: pendingDeletionSkaterIds,
                    confirmation,
                }),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The skater records could not be deleted.');
            deleteSkatersDialog?.close();
            window.location.reload();
        } catch (error) {
            if (deleteMessage) {
                deleteMessage.textContent = error.message;
                deleteMessage.className = 'form-message error';
                deleteMessage.hidden = false;
            }
            button.disabled = false;
            button.textContent = deleteDialogTitle?.textContent || 'Delete skaters';
        }
    };

    const restoreAchievementEditorState = async () => {
        if (!activeSkaterId) return;
        const editor = drawerContent?.querySelector('[data-achievement-editor]');
        const editorWasOpen = editor?.open === true;
        const openStages = Array.from(
            drawerContent?.querySelectorAll('[data-achievement-editor-stage][open]') || []
        ).map((stage) => stage.dataset.achievementEditorStage);

        await openDrawer(activeSkaterId);

        const refreshedEditor = drawerContent?.querySelector('[data-achievement-editor]');
        if (refreshedEditor) refreshedEditor.open = editorWasOpen;
        openStages.forEach((stageId) => {
            const stage = drawerContent?.querySelector(
                `[data-achievement-editor-stage="${stageId}"]`
            );
            if (stage) stage.open = true;
        });
    };

    const setDrawerAchievementMessage = (message) => {
        const status = drawerContent?.querySelector('[data-drawer-achievement-message]');
        if (!status) return;
        status.textContent = message;
        status.className = 'form-message error';
        status.hidden = false;
    };

    const updateDrawerSkillAchievement = async (control) => {
        if (!activeSkaterId || control.disabled) return;
        const achieved = control.dataset.achieved === 'true';
        const skillName = control.dataset.skillName || 'this skill';
        if (achieved) {
            const confirmed = await window.CAT.confirm({
                eyebrow: 'Achievement change',
                title: 'Remove this achievement?',
                message: `Remove the achievement for ${skillName}?`,
                cancelLabel: 'Keep achievement',
                confirmLabel: 'Remove achievement',
                tone: 'danger',
            });
            if (!confirmed) return;
        }

        control.disabled = true;
        try {
            const response = await fetch(
                `${document.body.dataset.apiBase}/${encodeURIComponent(activeSkaterId)}/skills/${encodeURIComponent(control.dataset.skillId)}`,
                {
                    method: achieved ? 'DELETE' : 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: '{}',
                }
            );
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The achievement could not be updated.');

            const rosterControl = Array.from(document.querySelectorAll('[data-edit-skill]')).find(
                (button) => button.dataset.assessmentSkaterId === activeSkaterId
                    && button.dataset.skillId === control.dataset.skillId
            );
            if (rosterControl) updateSkillCell(rosterControl, !achieved);
            await restoreAchievementEditorState();
            showSkillToast(result.message || `${skillName} updated.`);
        } catch (error) {
            setDrawerAchievementMessage(error.message);
            control.disabled = false;
        }
    };

    const updateDrawerAward = async (control, overrideIneligible = false, bubbleErrors = false) => {
        if (!activeSkaterId || control.disabled) return;
        const awarded = control.dataset.awarded === 'true';
        const awardName = control.dataset.awardName || 'this award';
        if (awarded) {
            const confirmed = await window.CAT.confirm({
                eyebrow: 'Award change',
                title: 'Remove this award?',
                message: `Remove ${awardName}?`,
                cancelLabel: 'Keep award',
                confirmLabel: 'Remove award',
                tone: 'danger',
            });
            if (!confirmed) return;
        }
        if (!awarded && control.dataset.eligible !== 'true' && !overrideIneligible) {
            openAwardOverrideDialog(
                control,
                () => updateDrawerAward(control, true, true)
            );
            return;
        }

        control.disabled = true;
        try {
            const response = await fetch(awardEndpoint(control), {
                method: awarded ? 'DELETE' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({override_ineligible: overrideIneligible}),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The award could not be updated.');

            const rosterControl = Array.from(document.querySelectorAll('[data-award-control]')).find(
                (button) => button.dataset.awardSkaterId === activeSkaterId
                    && button.dataset.awardType === control.dataset.awardType
                    && button.dataset.awardId === control.dataset.awardId
            );
            if (rosterControl) {
                rosterControl.dataset.awarded = String(!awarded);
                if (!awarded) rosterControl.dataset.awardedAt = result.awarded_at || '';
                applyOverriddenSkills(rosterControl, result.completed_skill_ids);
                syncAwardControl(rosterControl);
                syncHighestBadgeForRow(rosterControl);
            }
            await restoreAchievementEditorState();
            showSkillToast(result.message || `${awardName} updated.`);
        } catch (error) {
            setDrawerAchievementMessage(error.message);
            control.disabled = false;
            if (bubbleErrors) throw error;
        }
    };

    const saveSkater = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[data-form-message]');
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        payload.active = form.querySelector('[name="active"]').checked;

        button.disabled = true;
        button.textContent = 'Saving…';
        message.hidden = true;

        try {
            const response = await fetch(`${document.body.dataset.apiBase}/${encodeURIComponent(activeSkaterId)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The skater could not be updated.');
            message.textContent = result.message;
            message.className = 'form-message success';
            message.hidden = false;
            window.setTimeout(() => window.location.reload(), 650);
        } catch (error) {
            message.textContent = error.message;
            message.className = 'form-message error';
            message.hidden = false;
            button.disabled = false;
            button.textContent = 'Save changes';
        }
    };

    const syncRegistrationGroupSwatch = (event) => {
        const select = event?.currentTarget || event;
        const row = select?.closest('.registration-editor-row');
        const swatch = row?.querySelector('[data-registration-group-swatch]');
        const option = select?.options[select.selectedIndex];
        if (swatch) swatch.style.backgroundColor = option?.dataset.groupColour || '#94a3b8';
    };

    const syncRegistrationControls = () => {
        drawerContent?.querySelectorAll('.registration-editor-row').forEach((row) => {
            const checkbox = row.querySelector('[data-registration-session]');
            const select = row.querySelector('[data-registration-group]');
            if (!checkbox || !select) return;
            select.disabled = !checkbox.checked;
            row.classList.toggle('is-selected', checkbox.checked);
            syncRegistrationGroupSwatch(select);
        });
    };

    const saveRegistrations = async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[data-registration-message]');
        const registrations = Array.from(form.querySelectorAll('[data-registration-session]:checked')).map((checkbox) => {
            const row = checkbox.closest('.registration-editor-row');
            const group = row?.querySelector('[data-registration-group]');
            return {
                session_id: Number(checkbox.value),
                group_id: group?.value ? Number(group.value) : null,
            };
        });
        button.disabled = true;
        button.textContent = 'Saving…';
        message.hidden = true;

        try {
            const response = await fetch(
                `${document.body.dataset.apiBase}/${encodeURIComponent(activeSkaterId)}/registrations`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({registrations}),
                }
            );
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'The session registrations could not be updated.');
            message.textContent = result.message;
            message.className = 'form-message success';
            message.hidden = false;
            window.setTimeout(() => window.location.reload(), 650);
        } catch (error) {
            message.textContent = error.message;
            message.className = 'form-message error';
            message.hidden = false;
            button.disabled = false;
            button.textContent = 'Save sessions & groups';
        }
    };

    document.querySelectorAll('[data-skater-id]').forEach((button) => {
        button.addEventListener('click', () => openDrawer(button.dataset.skaterId));
    });
    document.querySelectorAll('[data-close-drawer]').forEach((button) => {
        button.addEventListener('click', closeDrawer);
    });
    addSkaterButton?.addEventListener('click', openAddSkaterDrawer);
    deleteSkatersButton?.addEventListener('click', () => {
        const skaterIds = selectedSkaterIds();
        openSkaterDeletionDialog(
            skaterIds,
            skaterIds.length === 1 ? 'Delete Skater' : 'Delete skaters',
            skaterIds.length === 1
                ? 'Delete the selected skater from the live roster?'
                : `Delete ${skaterIds.length} selected skaters from the live roster?`
        );
    });
    deleteConfirmationInput?.addEventListener('input', () => {
        if (confirmDeleteSkatersButton) {
            confirmDeleteSkatersButton.disabled = deleteConfirmationInput.value.trim().toLowerCase() !== 'delete';
        }
    });
    deleteSkatersForm?.addEventListener('submit', deleteSkaters);
    deleteSkatersDialog?.addEventListener('click', (event) => {
        if (event.target === deleteSkatersDialog) deleteSkatersDialog.close();
    });
    document.addEventListener('keydown', (event) => {
        if (!isCurrentApplicationGeneration()) return;
        if (event.key !== 'Escape') return;
        if (progressSettingsDrawer && !progressSettingsDrawer.hidden) {
            closeProgressSettings();
        } else if (drawer && !drawer.hidden) {
            closeDrawer();
        }
    }, { signal: applicationEventController.signal });
})();
