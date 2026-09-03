(() => {
    'use strict';

    const body = document.body;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const seasonId = Number(body.dataset.seasonId || 0);
    const sessionId = Number(body.dataset.sessionId || 0);
    const currentActivity = body.dataset.rinkActivity || 'roster';
    const stateScope = body.dataset.rinkStateScope || 'session';
    const canEditRink = body.dataset.rinkCanEdit === 'true';
    let groupId = Number(body.dataset.groupId || 0);
    const apiBase = body.dataset.rinkApiBase || '';
    const connectionStatus = document.querySelector('[data-rink-connection-status]');
    const connectionLabel = connectionStatus?.querySelector('[data-rink-connection-label]');
    const activityBar = document.querySelector('#rink-activity-bar');
    const activityScrollKey = `rink-activity-scroll:${stateScope}:${seasonId}:${sessionId}`;
    const restoreActivityScroll = () => {
        if (!activityBar) {
            document.documentElement.classList.remove('rink-restoring-activity');
            return;
        }
        try {
            const saved = JSON.parse(window.sessionStorage.getItem(activityScrollKey) || 'null');
            if (!saved) {
                document.documentElement.classList.remove('rink-restoring-activity');
                return;
            }
            window.sessionStorage.removeItem(activityScrollKey);
            activityBar.scrollLeft = Number(saved.tabScrollLeft) || 0;
            const savedTabTop = Number(saved.tabViewportTop);
            const currentTabTop = activityBar.getBoundingClientRect().top;
            const targetScrollTop = Number.isFinite(savedTabTop)
                ? Math.max(0, window.scrollY + currentTabTop - savedTabTop)
                : Math.max(0, Number(saved.pageScrollTop) || 0);
            const scrollingElement = document.scrollingElement;
            const availableScroll = scrollingElement
                ? Math.max(0, scrollingElement.scrollHeight - scrollingElement.clientHeight)
                : 0;
            if (targetScrollTop > availableScroll) {
                document.body.style.minHeight = `${document.body.scrollHeight + targetScrollTop - availableScroll + 2}px`;
            }
            window.scrollTo({
                left: Number(saved.pageScrollLeft) || 0,
                top: targetScrollTop,
                behavior: 'auto',
            });
            window.requestAnimationFrame(() => document.documentElement.classList.remove('rink-restoring-activity'));
        } catch (error) {
            // Scroll restoration is an enhancement; navigation must still work if storage is unavailable.
            document.documentElement.classList.remove('rink-restoring-activity');
        }
    };
    activityBar?.addEventListener('click', (event) => {
        const tab = event.target.closest('.rink-activity-card');
        if (!tab || !activityBar.contains(tab)) return;
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        try {
            saveViewState();
            const targetUrl = new URL(tab.href);
            const targetActivity = targetUrl.searchParams.get('activity') || 'roster';
            const targetState = loadViewState(targetActivity);
            if (targetState && Object.prototype.hasOwnProperty.call(targetState, 'groupId')) {
                const targetGroupId = Number(targetState.groupId || 0);
                if (targetGroupId) targetUrl.searchParams.set('group_id', String(targetGroupId));
                else targetUrl.searchParams.delete('group_id');
                tab.href = targetUrl.toString();
            }
            window.sessionStorage.setItem(activityScrollKey, JSON.stringify({
                tabScrollLeft: activityBar.scrollLeft,
                tabViewportTop: activityBar.getBoundingClientRect().top,
                pageScrollLeft: window.scrollX,
                pageScrollTop: window.scrollY,
            }));
        } catch (error) {
            // Allow normal tab navigation when storage is unavailable.
        }
    });
    restoreActivityScroll();
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
    const localCalendarDate = () => new Date().toLocaleDateString('en-CA');
    const nameTagTextColour = (colour) => {
        const match = String(colour || '').match(/^#([0-9a-f]{6})$/i);
        if (!match) return '#17417c';
        const red = parseInt(match[1].slice(0, 2), 16);
        const green = parseInt(match[1].slice(2, 4), 16);
        const blue = parseInt(match[1].slice(4, 6), 16);
        return ((red * 299) + (green * 587) + (blue * 114)) / 1000 < 150 ? '#ffffff' : '#16263d';
    };
    const request = async (url, options = {}) => {
        const method = String(options.method || 'GET').toUpperCase();
        if (!canEditRink && !['GET', 'HEAD'].includes(method)) {
            throw new Error('This account has read-only access.');
        }
        const response = await fetch(url, {
            headers: {Accept: 'application/json', ...(options.headers || {})},
            cache: 'no-store',
            ...options,
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'The request could not be completed.');
        return result;
    };
    const withQuery = (endpoint, parameters) => {
        const url = new URL(endpoint, window.location.href);
        new URLSearchParams(parameters).forEach((value, key) => {
            url.searchParams.set(key, value);
        });
        return url.toString();
    };
    let rinkConnected = true;
    const setConnectionStatus = (connected) => {
        rinkConnected = connected;
        if (connectionStatus && connectionLabel) {
            connectionStatus.classList.toggle('is-connected', connected);
            connectionStatus.classList.toggle('is-offline', !connected);
            connectionLabel.textContent = connected ? 'Connected' : 'Offline';
        }
        const chatPostButton = document.querySelector('[data-rink-chat-post]');
        const chatPostStatus = document.querySelector('[data-rink-chat-post-status]');
        if (chatPostButton) chatPostButton.disabled = !connected || !canEditRink;
        if (chatPostStatus) chatPostStatus.hidden = connected;
    };
    const checkConnection = async () => {
        if (!body.dataset.rinkStatusUrl || document.hidden) return;
        try {
            const status = await request(body.dataset.rinkStatusUrl);
            setConnectionStatus(status.connected === true);
        } catch (error) {
            setConnectionStatus(false);
        }
    };
    window.addEventListener('offline', checkConnection);
    window.addEventListener('online', checkConnection);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkConnection(); });
    const contextBody = (extra = {}) => JSON.stringify({season_id: seasonId, session_id: sessionId, ...extra});
    const medicalIconUrl = body.dataset.rinkMedicalIcon || '';
    const toast = document.querySelector('[data-rink-toast]');
    const toastMessage = toast?.querySelector('[data-rink-toast-message]');
    const toastClose = toast?.querySelector('[data-rink-toast-close]');
    let toastTimer = null;
    const showToast = (message, isError = false, persistent = false) => {
        if (!toast) return;
        window.clearTimeout(toastTimer);
        if (toastMessage) toastMessage.textContent = message;
        toast.classList.toggle('error', isError);
        if (toastClose) toastClose.hidden = !persistent;
        toast.hidden = false;
        if (!persistent) toastTimer = window.setTimeout(() => { toast.hidden = true; }, 3000);
    };
    toastClose?.addEventListener('click', () => { toast.hidden = true; });

    const navigator = document.querySelector('[data-rink-navigator]');
    navigator?.querySelector('[data-rink-season]')?.addEventListener('change', (event) => {
        navigator?.querySelector('[data-rink-session]')?.removeAttribute('name');
        event.currentTarget.form?.submit();
    });
    navigator?.querySelector('[data-rink-session]')?.addEventListener('change', (event) => event.currentTarget.form?.submit());
    const rosterBody = document.querySelector('[data-rink-roster-body]');
    const noResults = document.querySelector('[data-rink-no-results]');
    const reportCardsToolbar = document.querySelector('.rink-report-cards-toolbar');
    const reportCardsPanel = document.querySelector('[data-rink-report-cards-panel]');
    const reportCardsTab = document.querySelector('[data-rink-report-cards-tab]');
    const generateReportCardsButton = document.querySelector('[data-rink-generate-report-cards]');
    const noteLibraryList = document.querySelector('[data-rink-note-library-list]');
    const noteLibraryActions = document.querySelector('[data-rink-note-library-actions]');
    const openNoteLibraryButton = document.querySelector('[data-rink-open-note-library]');
    const reportCardNoteDialog = document.querySelector('#rink-report-card-note-dialog');
    const reportCardNoteForm = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-form]');
    const reportCardNoteTitle = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-title]');
    const reportCardNoteInput = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-input]');
    const reportCardNoteCount = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-count]');
    const reportCardNoteUpdated = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-updated]');
    const reportCardRegistration = reportCardNoteDialog?.querySelector('[data-rink-report-card-registration]');
    const reportCardAchievements = reportCardNoteDialog?.querySelector('[data-rink-report-card-achievements]');
    const reportCardAchievementWeeksInput = reportCardNoteDialog?.querySelector('[data-rink-report-card-achievement-weeks]');
    const reportCardNoteMessage = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-message]');
    const reportCardNoteSave = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-save]');
    const reportCardNoteDelete = reportCardNoteDialog?.querySelector('[data-rink-report-card-note-delete]');
    const reportCardNoteBackdrop = document.querySelector('[data-rink-report-card-note-backdrop]');
    const noteLibraryDialog = document.querySelector('#rink-note-library-dialog');
    const noteLibraryForm = noteLibraryDialog?.querySelector('[data-rink-note-library-form]');
    const noteLibraryTitleInput = noteLibraryDialog?.querySelector('[data-rink-note-library-title]');
    const noteLibraryContentInput = noteLibraryDialog?.querySelector('[data-rink-note-library-content]');
    const noteLibraryTitleCount = noteLibraryDialog?.querySelector('[data-rink-note-library-title-count]');
    const noteLibraryContentCount = noteLibraryDialog?.querySelector('[data-rink-note-library-content-count]');
    const noteLibraryMessage = noteLibraryDialog?.querySelector('[data-rink-note-library-message]');
    const noteLibrarySave = noteLibraryDialog?.querySelector('[data-rink-note-library-save]');
    const isAssessView = (body.dataset.rinkActivity || '') === 'skills';
    const isChatView = (body.dataset.rinkActivity || '') === 'chat';
    const assessRoot = document.querySelector('[data-rink-assess-stages]');
    const assessBody = document.querySelector('[data-rink-assess-table-body]');
    const assessHead = document.querySelector('[data-rink-assess-table-head]');
    const assessTableWrap = document.querySelector('[data-rink-assess-table-wrap]');
    const assessEmpty = document.querySelector('[data-rink-assess-empty]');
    const assessSummary = document.querySelector('[data-rink-assess-summary]');
    const assessCategorySelector = document.querySelector('[data-rink-assess-category-selector]');
    const assessCategoryIcons = document.querySelector('[data-rink-assess-category-icons]');
    const assessCategoryLabel = document.querySelector('[data-rink-assess-category-label]');
    const chatHistory = document.querySelector('[data-rink-chat-history]');
    const chatForm = document.querySelector('[data-rink-chat-form]');
    const chatInput = document.querySelector('[data-rink-chat-input]');
    const chatUnreadBadge = document.querySelector('[data-rink-chat-unread]');
    const chatExpiryDialog = document.querySelector('[data-rink-chat-expiry-dialog]');
    const chatExpiryForm = document.querySelector('[data-rink-chat-expiry-form]');
    const chatExpiryInput = document.querySelector('[data-rink-chat-expiry-input]');
    const chatExpiryCancel = document.querySelector('[data-rink-chat-expiry-cancel]');
    const currentUserId = Number(body.dataset.currentUserId || 0);
    const reportCardAchievementWeeksKey = `rink-report-card-achievement-weeks:${currentUserId}`;
    const reportCardAchievementWeekOptions = [4, 8, 12, 16, 26, 52];
    const loadReportCardAchievementWeeks = () => {
        try {
            const saved = Number(window.localStorage.getItem(reportCardAchievementWeeksKey));
            return reportCardAchievementWeekOptions.includes(saved) ? saved : 12;
        } catch (error) {
            return 12;
        }
    };
    let reportCardAchievementWeeks = loadReportCardAchievementWeeks();
    if (reportCardAchievementWeeksInput) {
        reportCardAchievementWeeksInput.value = String(reportCardAchievementWeeks);
    }
    const canCustomizeChatExpiry = body.dataset.rinkCanCustomizeChatExpiry === 'true';
    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    const viewStateKey = (activity = currentActivity) => `rink-view-state:${stateScope}:${seasonId}:${sessionId}:${activity}`;
    const loadViewState = (activity = currentActivity) => {
        try {
            const saved = JSON.parse(window.sessionStorage.getItem(viewStateKey(activity)) || 'null');
            return saved && typeof saved === 'object' ? saved : null;
        } catch (error) {
            return null;
        }
    };
    const restoredViewState = loadViewState();
    let rosterData = null;
    let reportCardSelectionActive = false;
    const selectedReportCardSkaterIds = new Set();
    let reportCardNoteSkaterId = null;
    let reportCardNoteOriginalValue = '';
    let reportCardsGenerating = false;
    let noteLibrary = [];
    let editingLibraryNoteId = null;
    let activeLibraryNoteId = null;
    let noteLibraryActionsTimer = null;
    const updateGenerateReportCardsButton = () => {
        if (!generateReportCardsButton) return;
        const hasSelectedSkaters = selectedReportCardSkaterIds.size > 0;
        generateReportCardsButton.disabled = !hasSelectedSkaters || reportCardsGenerating;
        generateReportCardsButton.setAttribute('aria-disabled', String(!hasSelectedSkaters || reportCardsGenerating));
    };

    const reportCardDate = (value) => {
        const raw = String(value || '').trim();
        if (raw === '') return '';
        const date = /^\d{4}-\d{2}-\d{2}$/.test(raw)
            ? new Date(`${raw}T00:00:00`)
            : new Date(`${raw.replace(' ', 'T')}Z`);
        if (Number.isNaN(date.getTime())) return '';
        return date.toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'});
    };
    const reportCardGenerationDate = () => new Date().toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
    const reportCardText = (value) => String(value || '')
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201C\u201D]/g, '"')
        .replace(/[\u2013\u2014]/g, '-')
        .replace(/\u2026/g, '...')
        .replace(/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/g, '?');
    const reportCardNote = (skater) => {
        const replacements = {
            '{skater first name}': skater.first_name || '',
            '{skater last name}': skater.last_name || '',
            '{coach first name}': body.dataset.currentUserFirstName || '',
            '{coach last name}': body.dataset.currentUserLastName || '',
        };
        return Object.entries(replacements).reduce(
            (note, [token, replacement]) => note.replaceAll(token, replacement),
            String(skater.report_card_notes || '')
        );
    };
    const reportCardSkillField = (stageNumber, ribbonName, skillIndex) => {
        if (stageNumber === 0) return `PC${String(skillIndex + 1).padStart(2, '0')}`;
        const firstFieldByStage = {1: 1, 2: 5, 3: 9, 4: 14, 5: 21, 6: 28};
        const suffixByRibbon = {balance: '', control: '_2', agility: '_3'};
        const firstField = firstFieldByStage[stageNumber];
        const suffix = suffixByRibbon[String(ribbonName || '').toLowerCase()];
        if (!firstField || suffix === undefined) return '';
        return `${String(firstField + skillIndex).padStart(2, '0')}${suffix}`;
    };
    const fillReportCard = async (templateBytes, signatureBytes, generatedOn, skater, curriculum) => {
        const {PDFDocument, PDFName, StandardFonts} = window.PDFLib;
        const document = await PDFDocument.load(templateBytes, {ignoreEncryption: true});
        const form = document.getForm();
        const font = await document.embedFont(StandardFonts.Helvetica);
        const pages = document.getPages();
        const fieldRectangle = (name) => form.getField(name).acroField.getWidgets()[0].getRectangle();
        const drawTextField = (name, value, pageIndex, size = 9, alignment = 'left') => {
            const rectangle = fieldRectangle(name);
            const text = reportCardText(value);
            let fittedSize = size;
            while (fittedSize > 6.5 && font.widthOfTextAtSize(text, fittedSize) > rectangle.width - 4) {
                fittedSize -= 0.5;
            }
            const textWidth = font.widthOfTextAtSize(text, fittedSize);
            pages[pageIndex].drawText(text, {
                x: alignment === 'center'
                    ? rectangle.x + ((rectangle.width - textWidth) / 2)
                    : rectangle.x + 2,
                y: rectangle.y + Math.max(1.5, (rectangle.height - fittedSize) / 2),
                size: fittedSize,
                font,
                maxWidth: Math.max(1, rectangle.width - 4),
            });
        };
        const check = (name) => {
            if (!name) return;
            const rectangle = fieldRectangle(name);
            const xOffset = 0.75; // 1 px in PDF points at 96 dpi.
            const yOffset = 0.1;
            pages[0].drawSvgPath(
                'M 0.12 2.35 C 0.38 2.08 0.68 2.05 0.93 2.30 C 1.22 2.60 1.48 3.00 1.70 3.38 C 2.15 2.15 2.70 0.86 3.45 0.25 C 3.82 -0.05 4.28 -0.10 4.72 0.08 C 3.78 0.78 3.12 1.65 2.62 2.66 C 2.23 3.45 1.95 4.22 1.72 4.86 C 1.40 4.18 1.04 3.58 0.62 3.04 C 0.42 2.77 0.24 2.55 0.12 2.35 Z',
                {
                    x: rectangle.x + xOffset,
                    y: rectangle.y + rectangle.height + yOffset,
                    scale: 1.1,
                    color: window.PDFLib.rgb(0, 0, 0),
                }
            );
        };
        const drawComments = (value) => {
            const rectangle = fieldRectangle('CoachComments');
            const text = reportCardText(value).trim();
            if (text === '') return;
            const wrap = (size) => {
                const lines = [];
                String(text).split(/\r?\n/).forEach((paragraph) => {
                    if (paragraph === '') {
                        lines.push('');
                        return;
                    }
                    let line = '';
                    paragraph.split(/\s+/).forEach((word) => {
                        const candidate = line === '' ? word : `${line} ${word}`;
                        if (font.widthOfTextAtSize(candidate, size) <= rectangle.width - 10) {
                            line = candidate;
                            return;
                        }
                        if (line !== '') lines.push(line);
                        line = word;
                    });
                    if (line !== '') lines.push(line);
                });
                return lines;
            };
            let size = 10;
            let lineHeight = 12;
            let lines = wrap(size);
            while (size > 7 && lines.length * lineHeight > rectangle.height - 10) {
                size -= 0.5;
                lineHeight = size + 2;
                lines = wrap(size);
            }
            const maximumLines = Math.floor((rectangle.height - 10) / lineHeight);
            lines.slice(0, maximumLines).forEach((line, index) => {
                pages[1].drawText(line, {
                    x: rectangle.x + 5,
                    y: rectangle.y + rectangle.height - 5 - size - (index * lineHeight),
                    size,
                    font,
                    maxWidth: rectangle.width - 10,
                });
            });
        };
        const drawSignature = async () => {
            if (!signatureBytes) return;
            const signature = await document.embedPng(signatureBytes);
            const rectangle = fieldRectangle('CoachSIG');
            const maximumWidth = rectangle.width - 8;
            const maximumHeight = 40;
            const scale = Math.min(maximumWidth / signature.width, maximumHeight / signature.height);
            const width = signature.width * scale;
            const height = signature.height * scale;
            pages[1].drawImage(signature, {
                x: rectangle.x + 4,
                // The normalized PNG includes transparent padding. Let that padding
                // straddle the line so the visible ink rests naturally above it.
                y: rectangle.y - 3,
                width,
                height,
            });
        };

        drawTextField('Name', `${skater.first_name || ''} ${skater.last_name || ''}`.trim(), 0, 11);
        drawTextField('Club', body.dataset.clubName || '', 0, 11);
        drawComments(reportCardNote(skater));
        drawTextField('CoachDate', generatedOn, 1, 9);
        await drawSignature();

        curriculum.forEach((stage) => {
            const stageNumber = Number(stage.number);
            (stage.ribbons || []).forEach((ribbon) => {
                const achievedSkills = (ribbon.skills || []).filter((skill) => {
                    if (String(skill.code || '') === 'PCS-PARTICIPATION') return false;
                    return Boolean(skater.skills?.[skill.id]);
                });
                (ribbon.skills || [])
                    .filter((skill) => String(skill.code || '') !== 'PCS-PARTICIPATION')
                    .forEach((skill, skillIndex) => {
                        if (achievedSkills.some((achieved) => Number(achieved.id) === Number(skill.id))) {
                            check(reportCardSkillField(stageNumber, ribbon.name, skillIndex));
                        }
                    });
                const awardedAt = skater.ribbons?.[ribbon.id];
                if (!awardedAt) return;
                if (stageNumber === 0) {
                    drawTextField('PCRibbon', reportCardDate(awardedAt), 0, 9);
                    return;
                }
                const prefix = {balance: 'BAL', control: 'CON', agility: 'AGI'}[String(ribbon.name || '').toLowerCase()];
                if (prefix) drawTextField(`${prefix}Stage${stageNumber}Ribbon`, reportCardDate(awardedAt), 0, 8);
            });
            if (stageNumber >= 1 && stageNumber <= 6 && skater.badges?.[stage.id]) {
                drawTextField(`Stage${stageNumber}Date`, reportCardDate(skater.badges[stage.id]), 1, 8, 'center');
            }
        });

        pages.forEach((page) => page.node.delete(PDFName.of('Annots')));
        document.catalog.delete(PDFName.of('AcroForm'));
        return document;
    };
    const generateReportCards = async () => {
        if (reportCardsGenerating || selectedReportCardSkaterIds.size === 0) return;
        if (!window.PDFLib || !assessData) throw new Error('Report-card generation is unavailable. Refresh and try again.');
        const skaters = (assessData.skaters || []).filter((skater) => selectedReportCardSkaterIds.has(String(skater.public_id)));
        if (skaters.length === 0) throw new Error('Select at least one skater.');
        reportCardsGenerating = true;
        updateGenerateReportCardsButton();
        const label = generateReportCardsButton?.querySelector('span');
        const originalLabel = label?.textContent || 'Generate';
        if (label) label.textContent = 'Generating...';
        try {
            const [templateResponse, signatureResponse] = await Promise.all([
                fetch(body.dataset.rinkReportCardTemplateUrl || '', {
                    headers: {Accept: 'application/pdf'},
                    cache: 'no-store',
                }),
                fetch(body.dataset.rinkReportCardSignatureUrl || '', {
                    headers: {Accept: 'image/png'},
                    cache: 'no-store',
                }),
            ]);
            if (!templateResponse.ok) throw new Error('The report-card template could not be loaded.');
            if (!signatureResponse.ok && signatureResponse.status !== 204) {
                throw new Error('Your report-card signature could not be loaded.');
            }
            const [templateBytes, signatureBytes] = await Promise.all([
                templateResponse.arrayBuffer(),
                signatureResponse.status === 204 ? Promise.resolve(null) : signatureResponse.arrayBuffer(),
            ]);
            const output = await window.PDFLib.PDFDocument.create();
            const generatedOn = reportCardGenerationDate();
            for (const skater of skaters) {
                const card = await fillReportCard(templateBytes, signatureBytes, generatedOn, skater, assessData.stages || []);
                const pages = await output.copyPages(card, card.getPageIndices());
                pages.forEach((page) => output.addPage(page));
            }
            output.setTitle(`CanSkate progress reports - ${localCalendarDate()}`);
            output.setCreator('CanSkate Achievement Tracker');
            output.setProducer('CanSkate Achievement Tracker');
            const bytes = await output.save();
            const blobUrl = URL.createObjectURL(new Blob([bytes], {type: 'application/pdf'}));
            const download = document.createElement('a');
            download.href = blobUrl;
            download.download = `CanSkate-report-cards-${localCalendarDate()}.pdf`;
            document.body.appendChild(download);
            download.click();
            download.remove();
            window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
            showToast(`${skaters.length} report card${skaters.length === 1 ? '' : 's'} downloaded.`);
        } finally {
            reportCardsGenerating = false;
            if (label) label.textContent = originalLabel;
            updateGenerateReportCardsButton();
        }
    };
    generateReportCardsButton?.addEventListener('click', () => {
        void generateReportCards().catch((error) => showToast(error.message, true, true));
    });
    const updateReportCardNoteCount = () => {
        if (!reportCardNoteInput || !reportCardNoteCount) return;
        const remaining = Math.max(0, Number(reportCardNoteInput.maxLength) - reportCardNoteInput.value.length);
        reportCardNoteCount.textContent = `${remaining} characters remaining`;
    };
    const updateReportCardNoteSaveState = () => {
        if (!reportCardNoteInput || !reportCardNoteSave) return;
        reportCardNoteSave.disabled = reportCardNoteInput.value === reportCardNoteOriginalValue;
    };
    const updateReportCardNoteUpdated = (skater) => {
        if (!reportCardNoteUpdated) return;
        const name = String(skater?.report_card_note_updated_by_name || '').trim();
        const updatedAt = String(skater?.report_card_note_updated_at || '').trim();
        if (!name || !updatedAt) {
            reportCardNoteUpdated.hidden = true;
            return;
        }
        const timestamp = new Date(`${updatedAt.replace(' ', 'T')}Z`);
        const displayTimestamp = Number.isNaN(timestamp.getTime())
            ? updatedAt
            : timestamp.toLocaleString('en-US', {dateStyle: 'medium', timeStyle: 'short', hour12: true});
        reportCardNoteUpdated.textContent = `Last updated by ${name} · ${displayTimestamp}`;
        reportCardNoteUpdated.hidden = false;
    };
    const renderReportCardAchievements = (skater) => {
        if (!reportCardAchievements) return;
        const cutoff = new Date();
        cutoff.setDate(cutoff.getDate() - (reportCardAchievementWeeks * 7));
        cutoff.setHours(0, 0, 0, 0);
        const parseAchievementDate = (value) => {
            const raw = String(value || '').trim();
            if (raw === '') return null;
            const date = /^\d{4}-\d{2}-\d{2}$/.test(raw)
                ? new Date(`${raw}T00:00:00`)
                : new Date(`${raw.replace(' ', 'T')}Z`);
            return Number.isNaN(date.getTime()) ? null : date;
        };
        const achievements = [];
        const addAchievement = (kind, label, value) => {
            const date = parseAchievementDate(value);
            if (!date || date < cutoff) return;
            achievements.push({kind, label, date});
        };
        (assessData?.stages || []).forEach((stage) => {
            const stageLabel = stage.name || `Stage ${stage.number}`;
            if (stage.has_badge) addAchievement('B', `${stageLabel} badge`, skater.badges?.[stage.id]);
            (stage.ribbons || []).forEach((ribbon) => {
                addAchievement('R', `${stageLabel} · ${ribbon.name} ribbon`, skater.ribbons?.[ribbon.id]);
                (ribbon.skills || []).forEach((skill) => {
                    addAchievement('S', `${stageLabel} · ${skill.name || skill.description || 'Skill'}`, skater.skills?.[skill.id]);
                });
            });
        });
        achievements.sort((left, right) => right.date - left.date || collator.compare(left.label, right.label));
        if (achievements.length === 0) {
            reportCardAchievements.innerHTML = '<p class="rink-report-card-achievements-empty">No achievements in this period.</p>';
            return;
        }
        reportCardAchievements.innerHTML = achievements.map((achievement) => `<div class="rink-report-card-achievement kind-${achievement.kind}"><time datetime="${achievement.date.toISOString().slice(0, 10)}">${escapeHtml(achievement.date.toLocaleDateString('en-CA', {month: 'short', day: 'numeric'}))}</time><b title="${achievement.kind === 'S' ? 'Skill' : achievement.kind === 'R' ? 'Ribbon' : 'Badge'}">${achievement.kind}</b><span>${escapeHtml(achievement.label)}</span></div>`).join('');
    };
    reportCardAchievementWeeksInput?.addEventListener('change', () => {
        const weeks = Number(reportCardAchievementWeeksInput.value);
        if (!reportCardAchievementWeekOptions.includes(weeks)) return;
        reportCardAchievementWeeks = weeks;
        try {
            window.localStorage.setItem(reportCardAchievementWeeksKey, String(weeks));
        } catch (error) {
            // The selected period remains active for this page if storage is unavailable.
        }
        const skater = [...(assessData?.skaters || []), ...(rosterData?.skaters || [])]
            .find((candidate) => candidate.public_id === reportCardNoteSkaterId);
        if (skater) renderReportCardAchievements(skater);
    });
    const setReportCardsPanelOpen = (open) => {
        if (!reportCardsPanel || !reportCardsTab) return;
        const selectionStateChanged = reportCardSelectionActive !== open;
        reportCardSelectionActive = open;
        reportCardsPanel.classList.toggle('is-collapsed', !open);
        reportCardsPanel.setAttribute('aria-hidden', String(!open));
        reportCardsTab.setAttribute('aria-expanded', String(open));
        if (selectionStateChanged) renderAssess();
    };
    const updateReportCardBackdropBoundary = () => {
        if (!reportCardsPanel) return;
        const panelStyles = window.getComputedStyle(reportCardsPanel);
        const borderHeight = Number.parseFloat(panelStyles.borderTopWidth) || 0;
        const openPanelHeight = reportCardsPanel.scrollHeight + borderHeight;
        const panelTop = Math.max(0, window.innerHeight - openPanelHeight);
        reportCardNoteDialog?.style.setProperty('--report-cards-toolbar-top', `${panelTop}px`);
        reportCardNoteBackdrop?.style.setProperty('--report-cards-toolbar-top', `${panelTop}px`);
    };
    reportCardsTab?.addEventListener('click', () => {
        setReportCardsPanelOpen(reportCardsPanel?.classList.contains('is-collapsed'));
    });
    let activeSort = ['group', 'first_name', 'last_name', 'gender', 'age', 'attendance'].includes(restoredViewState?.sort?.key)
        && ['asc', 'desc'].includes(restoredViewState?.sort?.direction)
        ? restoredViewState.sort
        : {key: 'first_name', direction: 'asc'};
    let isMutating = false;
    let rosterRefreshVersion = 0;
    let assessData = null;
    let assessActiveSort = ['group', 'name', 'notes'].includes(restoredViewState?.sort?.key)
        && ['asc', 'desc'].includes(restoredViewState?.sort?.direction)
        ? restoredViewState.sort
        : {key: 'name', direction: 'asc'};
    let activeAssessStageId = restoredViewState?.stageId ? String(restoredViewState.stageId) : null;
    let activeAssessCategory = ['all', 'balance', 'control', 'agility'].includes(restoredViewState?.category)
        ? restoredViewState.category
        : '';
    let assessMutating = false;
    let chatData = null;
    let editingChatPublicId = null;
    const saveViewState = () => {
        if (!sessionId || !['roster', 'skills'].includes(currentActivity)) return;
        const tableScroll = document.querySelector('.rink-activity-page .rink-table-scroll');
        const state = {
            groupId,
            sort: currentActivity === 'skills' ? assessActiveSort : activeSort,
            tableScrollLeft: tableScroll?.scrollLeft || 0,
            tableScrollTop: tableScroll?.scrollTop || 0,
        };
        if (currentActivity === 'skills') {
            state.stageId = activeAssessStageId;
            state.category = activeAssessCategory;
        }
        try {
            window.sessionStorage.setItem(viewStateKey(), JSON.stringify(state));
        } catch (error) {
            // View-state persistence is optional when browser storage is unavailable.
        }
    };
    const restoreViewScroll = () => {
        if (!restoredViewState) return;
        window.requestAnimationFrame(() => {
            const tableScroll = document.querySelector('.rink-activity-page .rink-table-scroll');
            if (!tableScroll) return;
            tableScroll.scrollLeft = Number(restoredViewState.tableScrollLeft) || 0;
            tableScroll.scrollTop = Number(restoredViewState.tableScrollTop) || 0;
        });
    };
    window.addEventListener('pagehide', saveViewState);
    const assessRemovalMessage = 'Skills added prior to today (red checks) are locked and can only be removed by an administrator using their Skater Dashboard.';
    const assessBadgeIcon = () => window.CAT.skateCanadaBadgeIcon();
    const assessRibbonIcon = (ribbon) => `<svg viewBox="0 0 24 28" aria-hidden="true"><circle class="ribbon-icon-shape ribbon-icon-centre" cx="12" cy="8" r="7"></circle><path class="ribbon-icon-shape" d="M8 13.2 5.5 26 12 22.2 18.5 26 16 13.2"></path><text class="ribbon-icon-letter" x="12" y="10.7">${escapeHtml(String(ribbon.name || '').charAt(0).toUpperCase())}</text></svg>`;
    const assessCategoryIconMarkup = (category = '') => {
        const categories = category ? [category] : ['balance', 'control', 'agility'];
        return categories.map((value) => `<span class="rink-assess-category-icon ribbon-${escapeHtml(value)}">${assessRibbonIcon({name: value})}</span>`).join('');
    };

    try {
        const initial = document.querySelector('#rink-roster-data')?.textContent || '';
        rosterData = initial ? JSON.parse(initial) : null;
    } catch { rosterData = null; }
    try {
        const initialAssess = document.querySelector('#rink-assess-data')?.textContent || '';
        assessData = initialAssess ? JSON.parse(initialAssess) : null;
    } catch { assessData = null; }
    try {
        const initialChat = document.querySelector('#rink-chat-data')?.textContent || '';
        chatData = initialChat ? JSON.parse(initialChat) : null;
    } catch { chatData = null; }

    const rowValue = (skater, key) => {
        if (key === 'group') return skater.group_name || '';
        if (key === 'first_name') return skater.first_name || '';
        if (key === 'last_name') return skater.last_name || '';
        if (key === 'gender') return skater.gender_name || '';
        if (key === 'age') return Number(skater.age ?? -1);
        if (key === 'attendance') return skater.present ? 1 : 0;
        return '';
    };
    const sortedSkaters = () => [...(rosterData?.skaters || [])].sort((left, right) => {
        const leftValue = rowValue(left, activeSort.key);
        const rightValue = rowValue(right, activeSort.key);
        let comparison = ['age', 'attendance'].includes(activeSort.key)
            ? Number(leftValue) - Number(rightValue)
            : collator.compare(String(leftValue), String(rightValue));
        if (comparison === 0) {
            if (activeSort.key === 'group') {
                comparison = collator.compare(String(left.last_name || ''), String(right.last_name || ''))
                    || collator.compare(String(left.first_name || ''), String(right.first_name || ''));
            }
            else if (activeSort.key === 'first_name') {
                comparison = collator.compare(String(left.last_name || ''), String(right.last_name || ''));
            } else if (activeSort.key === 'last_name') {
                comparison = collator.compare(String(left.first_name || ''), String(right.first_name || ''));
            } else {
                comparison = collator.compare(`${left.last_name || ''} ${left.first_name || ''}`, `${right.last_name || ''} ${right.first_name || ''}`);
            }
        }
        return activeSort.direction === 'asc' ? comparison : -comparison;
    });
    const updateSortHeaders = () => {
        document.querySelectorAll('[data-rink-sort-header]').forEach((header) => {
            const selected = header.dataset.rinkSortHeader === activeSort.key;
            header.setAttribute('aria-sort', selected ? (activeSort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            const indicator = header.querySelector('button span');
            if (indicator) indicator.textContent = selected ? (activeSort.direction === 'asc' ? '↑' : '↓') : '↕';
        });
    };
    const groupDot = (skater, interactive = true) => {
        const colour = skater.group_id ? (skater.group_colour || '#ffffff') : '#ffffff';
        const unassignedLine = skater.group_id ? '' : '<path d="M4 16 16 4" stroke="#697789" stroke-width="2" stroke-linecap="round"></path>';
        if (!interactive) {
            return `<span class="rink-group-dot is-readonly ${skater.group_id ? '' : 'is-unassigned'}" aria-hidden="true"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="${escapeHtml(colour)}" stroke="#5f6e80" stroke-width="1.5"></circle>${unassignedLine}</svg></span>`;
        }
        return `<button class="rink-group-dot ${skater.group_id ? '' : 'is-unassigned'}" type="button" data-rink-group-button data-skater-id="${escapeHtml(skater.public_id)}" data-skater-name="${escapeHtml(`${skater.first_name} ${skater.last_name}`)}" data-group-id="${escapeHtml(skater.group_id || '')}" data-group-name="${escapeHtml(skater.group_name || 'No group')}" aria-label="Group: ${escapeHtml(skater.group_name || 'none')}. Change group for ${escapeHtml(`${skater.first_name} ${skater.last_name}`)}" title="${escapeHtml(skater.group_name || 'No group')}"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="${escapeHtml(colour)}" stroke="#5f6e80" stroke-width="1.5"></circle>${unassignedLine}</svg></button>`;
    };
    const medicalButton = (skater, name) => {
        const hasNote = String(skater.medical_notes || '').trim() !== '';
        if (!hasNote) return '';
        return `<button class="rink-medical-button" type="button" data-medical-skater="${escapeHtml(skater.public_id)}" aria-label="View medical or accommodation note for ${escapeHtml(name)}" title="Medical/accommodation note"><img src="${escapeHtml(medicalIconUrl)}" alt="" aria-hidden="true"></button>`;
    };
    const generalNoteButton = (skater, name) => {
        const hasNote = String(skater.general_notes || '').trim() !== '';
        if (!hasNote) return '';
        return `<button class="rink-general-note-button" type="button" data-general-note-skater="${escapeHtml(skater.public_id)}" aria-label="View general note for ${escapeHtml(name)}" title="General note"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 2.5h10.8L17 5.7v11.8H3z"></path><path d="M13.8 2.5v3.3H17"></path><path d="M6 9h8M6 12h6" class="rink-general-note-lines"></path></svg></button>`;
    };
    const renderRoster = () => {
        if (!rosterBody || !rosterData) return;
        rosterBody.innerHTML = sortedSkaters().map((skater) => {
            const name = `${skater.first_name} ${skater.last_name}`;
            const attendanceTitle = rosterData.attendance.enabled
                ? (skater.attendance_recorded ? (skater.attendance_status || 'Recorded') : 'Attendance not yet recorded')
                : (rosterData.attendance.message || 'Attendance unavailable');
            const nameCells = `<td class="rink-name-cell"><button class="rink-name-button rink-roster-name-tag" type="button" data-profile-skater="${escapeHtml(skater.public_id)}" data-rink-name-tag-colour="${escapeHtml(skater.group_colour || '')}"><strong>${escapeHtml(skater.first_name)}</strong></button></td><td class="rink-name-cell"><span class="rink-last-name">${escapeHtml(skater.last_name)}</span></td>`;
            const rosterCells = isAssessView
                ? `${nameCells}<td class="rink-medical-cell">${generalNoteButton(skater, name)}${medicalButton(skater, name)}</td>`
                : `${nameCells}<td class="rink-gender-cell">${escapeHtml(skater.gender_name || '—')}</td><td class="rink-age-cell">${escapeHtml(skater.age ?? '—')}</td><td class="rink-attendance-cell ${skater.attendance_recorded ? 'is-recorded' : ''}" title="${escapeHtml(attendanceTitle)}"><input type="checkbox" data-rink-attendance data-skater-id="${escapeHtml(skater.public_id)}" aria-label="Mark ${escapeHtml(name)} present" ${skater.present ? 'checked' : ''} ${rosterData.attendance.enabled && canEditRink ? '' : 'disabled'}></td><td class="rink-medical-cell">${generalNoteButton(skater, name)}${medicalButton(skater, name)}</td>`;
            return `<tr data-rink-row data-skater-id="${escapeHtml(skater.public_id)}"><td class="rink-skater-cell">${groupDot(skater)}</td>${rosterCells}</tr>`;
        }).join('');
        rosterBody.querySelectorAll('[data-rink-name-tag-colour]').forEach((tag) => {
            const colour = tag.dataset.rinkNameTagColour || '';
            if (!/^#[0-9a-f]{6}$/i.test(colour)) return;
            tag.style.setProperty('--rink-name-tag-colour', colour);
            tag.style.setProperty('--rink-name-tag-text-colour', nameTagTextColour(colour));
        });
        if (noResults) noResults.hidden = rosterData.skaters.length > 0;
        updateSortHeaders();
    };

    document.addEventListener('change', (event) => {
        const selectAll = event.target.closest('[data-rink-select-all-report-cards]');
        if (selectAll) {
            assessBody?.querySelectorAll('[data-rink-select-report-card-skater]').forEach((input) => {
                input.checked = selectAll.checked;
                if (input.checked) selectedReportCardSkaterIds.add(input.value);
                else selectedReportCardSkaterIds.delete(input.value);
            });
            selectAll.indeterminate = false;
            updateGenerateReportCardsButton();
            return;
        }
        const input = event.target.closest('[data-rink-select-report-card-skater]');
        if (!input) return;
        if (input.checked) selectedReportCardSkaterIds.add(input.value);
        else selectedReportCardSkaterIds.delete(input.value);
        const selectionInputs = Array.from(assessBody?.querySelectorAll('[data-rink-select-report-card-skater]') || []);
        const reportSelectAllInput = assessHead?.querySelector('[data-rink-select-all-report-cards]');
        if (reportSelectAllInput) {
            const selectedCount = selectionInputs.filter((control) => control.checked).length;
            reportSelectAllInput.checked = selectionInputs.length > 0 && selectedCount === selectionInputs.length;
            reportSelectAllInput.indeterminate = selectedCount > 0 && selectedCount < selectionInputs.length;
        }
        updateGenerateReportCardsButton();
    });
    const assessRowValue = (skater, key) => {
        if (key === 'group') return skater.group_name || '';
        if (key === 'name') return skater.first_name || '';
        if (key === 'notes') return String(skater.report_card_notes || '').trim() === '' ? '0' : '1';
        if (key === 'first_name') return skater.first_name || '';
        if (key === 'last_name') return skater.last_name || '';
        return '';
    };
    const assessSortedSkaters = () => [...(assessData?.skaters || [])].sort((left, right) => {
        const leftValue = assessRowValue(left, assessActiveSort.key);
        const rightValue = assessRowValue(right, assessActiveSort.key);
        let comparison = collator.compare(String(leftValue), String(rightValue));
        if (comparison === 0) {
            if (assessActiveSort.key === 'group') {
                comparison = collator.compare(String(left.last_name || ''), String(right.last_name || ''))
                    || collator.compare(String(left.first_name || ''), String(right.first_name || ''));
            } else if (assessActiveSort.key === 'name' || assessActiveSort.key === 'first_name') {
                comparison = collator.compare(String(left.last_name || ''), String(right.last_name || ''));
            } else if (assessActiveSort.key === 'last_name') {
                comparison = collator.compare(String(left.first_name || ''), String(right.first_name || ''));
            } else if (assessActiveSort.key === 'notes') {
                comparison = collator.compare(String(left.last_name || ''), String(right.last_name || ''))
                    || collator.compare(String(left.first_name || ''), String(right.first_name || ''));
            }
        }
        return assessActiveSort.direction === 'asc' ? comparison : -comparison;
    });
    const assessSelectedStage = () => (assessData?.stages || []).find((stage) => String(stage.id) === String(activeAssessStageId || '')) || null;
    const updateAssessSortHeaders = () => {
        document.querySelectorAll('[data-rink-assess-sort-header]').forEach((header) => {
            const selected = header.dataset.rinkAssessSortHeader === assessActiveSort.key;
            header.setAttribute('aria-sort', selected ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
            const indicator = header.querySelector('button span');
            if (indicator) indicator.textContent = selected ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕';
        });
    };
    const renderAssess = () => {
        if (!isAssessView || !assessData) return;
        const selectedStage = assessSelectedStage();
        assessRoot?.classList.toggle('has-selected-stage', Boolean(selectedStage));
        if (assessCategorySelector) assessCategorySelector.hidden = !selectedStage;
        document.querySelectorAll('[data-rink-assess-stage]').forEach((button) => {
            const selected = Boolean(selectedStage) && String(button.dataset.stageId || '') === String(selectedStage.id);
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        if (assessCategorySelector && assessHead?.contains(assessCategorySelector)) assessRoot?.append(assessCategorySelector);

        if (!selectedStage) {
            if (assessCategorySelector) assessCategorySelector.hidden = true;
            assessCategorySelector?.setAttribute('aria-disabled', 'true');
            assessCategorySelector?.removeAttribute('open');
            assessCategorySelector?.classList.remove('is-all-categories', 'is-single-category', 'is-single-ribbon');
            if (assessCategoryIcons) assessCategoryIcons.innerHTML = assessCategoryIconMarkup();
            if (assessCategoryLabel) assessCategoryLabel.textContent = 'Choose a category';
            const assessTable = assessTableWrap?.querySelector('.rink-assess-table');
            assessTable?.classList.remove('is-stage-expanded');
            assessTable?.classList.toggle('has-report-card-selection', reportCardSelectionActive);
            if (reportCardSelectionActive) {
                if (assessEmpty) assessEmpty.hidden = true;
                if (assessTableWrap) assessTableWrap.hidden = false;
                const selectableSkaters = assessSortedSkaters();
                selectedReportCardSkaterIds.forEach((skaterId) => {
                    if (!selectableSkaters.some((skater) => String(skater.public_id) === skaterId)) selectedReportCardSkaterIds.delete(skaterId);
                });
                updateGenerateReportCardsButton();
                const groupHeader = groupId
                    ? '<th class="rink-skater-icon-header"><span class="rink-static-header">Group</span></th>'
                    : `<th class="rink-skater-icon-header" data-rink-assess-sort-header="group" aria-sort="${assessActiveSort.key === 'group' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-rink-assess-sort="group">Group<span aria-hidden="true">${assessActiveSort.key === 'group' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th>`;
                if (assessHead) assessHead.innerHTML = `<tr><th class="rink-report-selection-header" style="background:#164a91;color:#fff"><input class="row-selection-checkbox" type="checkbox" data-rink-select-all-report-cards aria-label="Select all displayed skaters for report cards" title="Select all displayed skaters"></th><th class="rink-report-notes-header" data-rink-assess-sort-header="notes" aria-sort="${assessActiveSort.key === 'notes' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}" style="background:#164a91;color:#fff"><button type="button" data-rink-assess-sort="notes">Notes<span aria-hidden="true">${assessActiveSort.key === 'notes' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th>${groupHeader}<th data-rink-assess-sort-header="name" aria-sort="${assessActiveSort.key === 'name' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-rink-assess-sort="name">Name<span aria-hidden="true">${assessActiveSort.key === 'name' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th></tr>`;
                if (assessBody) assessBody.innerHTML = selectableSkaters.map((skater) => {
                    const name = `${skater.first_name} ${skater.last_name}`;
                    return `<tr data-rink-assess-row data-skater-id="${escapeHtml(skater.public_id)}"><td class="rink-report-selection-cell"><input type="checkbox" data-rink-select-report-card-skater value="${escapeHtml(skater.public_id)}" aria-label="Select ${escapeHtml(name)} for report cards" ${selectedReportCardSkaterIds.has(String(skater.public_id)) ? 'checked' : ''}></td><td class="rink-report-notes-cell"><button class="rink-report-note-edit ${String(skater.report_card_notes || '').trim() !== '' ? 'is-saved' : ''}" type="button" data-rink-edit-report-card-note="${escapeHtml(skater.public_id)}" aria-label="Edit report-card note for ${escapeHtml(name)}">Edit</button></td><td class="rink-skater-cell">${groupDot(skater, false)}</td><td class="rink-name-cell rink-assess-name-cell"><span class="rink-assess-skater-name"><strong>${escapeHtml(skater.first_name)}</strong><small>${escapeHtml(skater.last_name)}</small></span></td></tr>`;
                }).join('');
                const selectionInputs = Array.from(assessBody?.querySelectorAll('[data-rink-select-report-card-skater]') || []);
                const selectAll = assessHead?.querySelector('[data-rink-select-all-report-cards]');
                if (selectAll) {
                    const selectedCount = selectionInputs.filter((input) => input.checked).length;
                    selectAll.checked = selectionInputs.length > 0 && selectedCount === selectionInputs.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < selectionInputs.length;
                    selectAll.disabled = selectionInputs.length === 0;
                }
                updateAssessSortHeaders();
                return;
            }
            if (assessEmpty) {
                assessEmpty.hidden = false;
                assessEmpty.querySelector('h3')?.replaceChildren(document.createTextNode('No stage selected'));
                const copy = assessEmpty.querySelector('p');
                if (copy) copy.textContent = 'Select a stage button above.';
            }
            if (assessTableWrap) assessTableWrap.hidden = true;
            if (assessHead) assessHead.innerHTML = '';
            if (assessBody) assessBody.innerHTML = '';
            updateAssessSortHeaders();
            return;
        }

        const ribbons = selectedStage.ribbons || [];
        const allSkills = ribbons.flatMap((ribbon) => ribbon.skills || []);
        const stageLabel = selectedStage.name || `Stage ${selectedStage.number}`;
        const participationStage = Number(selectedStage.number) === 0;
        if (participationStage) activeAssessCategory = 'all';
        const showCategorySelector = !participationStage;
        if (assessCategorySelector) {
            assessCategorySelector.hidden = !showCategorySelector;
            assessCategorySelector.toggleAttribute('open', false);
        }
        assessCategorySelector?.setAttribute('aria-disabled', showCategorySelector ? 'false' : 'true');
        if (assessCategorySelector) assessCategorySelector.dataset.selectedCategory = activeAssessCategory;
        assessCategorySelector?.classList.toggle('is-all-categories', activeAssessCategory === 'all');
        assessCategorySelector?.classList.toggle('is-single-category', Boolean(activeAssessCategory && activeAssessCategory !== 'all'));
        assessCategorySelector?.classList.toggle('is-single-ribbon', activeAssessCategory === 'all' && ribbons.length === 1);
        if (assessCategoryIcons) assessCategoryIcons.innerHTML = assessCategoryIconMarkup(
            activeAssessCategory === 'all' && Number(selectedStage.number) === 0
                ? 'pre-canskate'
                : (activeAssessCategory === 'all' ? '' : activeAssessCategory)
        );
        if (assessCategoryLabel) assessCategoryLabel.textContent = activeAssessCategory === 'all'
            ? 'Show All'
            : activeAssessCategory
            ? `${activeAssessCategory.charAt(0).toUpperCase()}${activeAssessCategory.slice(1)}`
            : 'Choose a category';
        const selectedRibbon = ribbons.find((ribbon) => String(ribbon.name || '').toLowerCase() === activeAssessCategory) || null;
        const visibleRibbons = activeAssessCategory === 'all' ? ribbons : (selectedRibbon ? [selectedRibbon] : []);
        const skills = activeAssessCategory === 'all'
            ? ribbons.flatMap((ribbon) => (ribbon.skills || []).map((skill) => ({...skill, category: String(ribbon.name || '').toLowerCase()})))
            : (selectedRibbon?.skills || []).map((skill) => ({...skill, category: String(selectedRibbon?.name || '').toLowerCase()}));
        const stageExpanded = true;
        if (assessEmpty) assessEmpty.hidden = true;
        if (assessTableWrap) assessTableWrap.hidden = false;
        const assessTable = assessTableWrap?.querySelector('.rink-assess-table');
        assessTable?.classList.toggle('is-stage-expanded', Boolean(selectedRibbon));
        assessTable?.classList.toggle('has-report-card-selection', reportCardSelectionActive);
        const selectableSkaters = assessSortedSkaters();
        selectedReportCardSkaterIds.forEach((skaterId) => {
            if (!selectableSkaters.some((skater) => String(skater.public_id) === skaterId)) {
                selectedReportCardSkaterIds.delete(skaterId);
            }
        });
        updateGenerateReportCardsButton();
        if (assessHead) {
            const groupHeader = groupId
                ? '<th class="rink-skater-icon-header"><span class="rink-static-header">Group</span></th>'
                : `<th class="rink-skater-icon-header" data-rink-assess-sort-header="group" aria-sort="${assessActiveSort.key === 'group' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-rink-assess-sort="group">Group<span aria-hidden="true">${assessActiveSort.key === 'group' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th>`;
            const selectionHeader = reportCardSelectionActive
                ? '<th class="rink-report-selection-header" style="background:#164a91;color:#fff"><input class="row-selection-checkbox" type="checkbox" data-rink-select-all-report-cards aria-label="Select all displayed skaters for report cards" title="Select all displayed skaters"></th>'
                : '';
            const notesHeader = reportCardSelectionActive
                ? `<th class="rink-report-notes-header" data-rink-assess-sort-header="notes" aria-sort="${assessActiveSort.key === 'notes' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}" style="background:#164a91;color:#fff"><button type="button" data-rink-assess-sort="notes">Notes<span aria-hidden="true">${assessActiveSort.key === 'notes' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th>`
                : '';
            assessHead.innerHTML = `<tr>
                ${selectionHeader}
                ${notesHeader}
                ${groupHeader}
                <th data-rink-assess-sort-header="name" aria-sort="${assessActiveSort.key === 'name' ? (assessActiveSort.direction === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-rink-assess-sort="name">Name<span aria-hidden="true">${assessActiveSort.key === 'name' ? (assessActiveSort.direction === 'asc' ? '↑' : '↓') : '↕'}</span></button></th>
                <th class="rink-assess-stage-header"><div class="rink-assess-stage-header-stack"><div data-rink-assess-category-slot></div><span>${escapeHtml(stageLabel)}</span></div></th>
                ${stageExpanded ? skills.map((skill) => `<th class="rink-assess-skill-header ribbon-${escapeHtml(skill.category)}"><span>${escapeHtml(skill.name || skill.description || 'Skill')}</span></th>`).join('') : ''}
            </tr>`;
            if (assessCategorySelector) assessHead.querySelector('[data-rink-assess-category-slot]')?.append(assessCategorySelector);
        }
        if (assessBody) {
            assessBody.innerHTML = selectableSkaters.map((skater) => {
                const name = `${skater.first_name} ${skater.last_name}`;
                const achieved = (skillList) => skillList.filter((skill) => (
                    participationStage ? skill.is_participation : !skill.is_participation
                ) && Boolean(skater.skills?.[skill.id])).length;
                const ribbonSkillCount = (ribbon) => ribbon.is_participation_ribbon
                    ? 1
                    : (ribbon.skills || []).filter((skill) => !skill.is_participation).length;
                const ribbonRequiredCount = (ribbon) => Math.min(
                    ribbonSkillCount(ribbon),
                    Number(ribbon.required_count || ribbonSkillCount(ribbon))
                );
                const stageRequiredRibbonCount = Number(selectedStage.required_ribbon_count || ribbons.length);
                const stageRibbonAwarded = ribbons.filter((ribbon) => Boolean(skater.ribbons?.[ribbon.id])).length;
                const stageComplete = stageRequiredRibbonCount > 0 && stageRibbonAwarded >= stageRequiredRibbonCount;
                const selectionCell = reportCardSelectionActive
                    ? `<td class="rink-report-selection-cell"><input type="checkbox" data-rink-select-report-card-skater value="${escapeHtml(skater.public_id)}" aria-label="Select ${escapeHtml(name)} for report cards" ${selectedReportCardSkaterIds.has(String(skater.public_id)) ? 'checked' : ''}></td>`
                    : '';
                const noteCell = reportCardSelectionActive
                    ? `<td class="rink-report-notes-cell"><button class="rink-report-note-edit ${String(skater.report_card_notes || '').trim() !== '' ? 'is-saved' : ''}" type="button" data-rink-edit-report-card-note="${escapeHtml(skater.public_id)}" aria-label="Edit report-card note for ${escapeHtml(name)}">Edit</button></td>`
                    : '';
                return `<tr data-rink-assess-row data-skater-id="${escapeHtml(skater.public_id)}">
                    ${selectionCell}
                    ${noteCell}
                    <td class="rink-skater-cell">${groupDot(skater, false)}</td>
                    <td class="rink-name-cell rink-assess-name-cell"><span class="rink-assess-skater-name"><strong>${escapeHtml(skater.first_name)}</strong><small>${escapeHtml(skater.last_name)}</small></span></td>
                    <td class="rink-assess-stage-cell"><span class="stage-total"><button class="rink-assess-achievement-button" type="button" data-rink-assess-award data-skater-id="${escapeHtml(skater.public_id)}" data-stage-id="${escapeHtml(selectedStage.id)}" aria-label="Open ${escapeHtml(stageLabel)} achievements for ${escapeHtml(name)}"><span class="stage-detail-controls">${selectedStage.has_badge ? `<span class="stage-badge-stack stage-badge-stage-${escapeHtml(selectedStage.number)}"><span class="stage-badge-button stage-award-control ${stageComplete ? 'is-eligible' : ''} ${skater.badges?.[selectedStage.id] ? 'is-awarded' : ''}">${assessBadgeIcon(selectedStage)}</span><span class="progress-value">${stageRibbonAwarded}/${stageRequiredRibbonCount}</span></span>` : ''}<span class="rink-assess-ribbon-status">${visibleRibbons.map((ribbon) => { const ribbonSkills = ribbon.skills || []; const ribbonAchieved = achieved(ribbonSkills); const required = ribbonRequiredCount(ribbon); const total = ribbonSkillCount(ribbon); const complete = required > 0 && ribbonAchieved >= required; const requirement = `${ribbon.name}: ${ribbonAchieved} of ${total} skills achieved; ${required} required`; return `<span class="ribbon-detail-button stage-ribbon-detail ribbon-${escapeHtml(String(ribbon.name || '').toLowerCase())} ${complete ? 'is-eligible' : ''} ${skater.ribbons?.[ribbon.id] ? 'is-awarded' : ''}" aria-label="${escapeHtml(requirement)}" title="${escapeHtml(requirement)}">${assessRibbonIcon(ribbon)}<span class="ribbon-progress-value">${ribbonAchieved}/${total}</span></span>`; }).join('')}</span></span></button></span></td>
                    ${stageExpanded ? skills.map((skill) => {
                        const achievementDate = skater.skills?.[skill.id] || '';
                        const achieved = Boolean(achievementDate);
                        const locked = achieved && achievementDate !== localCalendarDate();
                        const skillName = skill.name || skill.description || 'skill';
                        const actionLabel = locked
                            ? `${skillName} for ${name} was recorded on ${achievementDate} and cannot be removed here.`
                            : (achieved ? 'Remove' : 'Add') + ` ${skillName} for ${name}`;
                        return `<td class="rink-assess-skill-cell ribbon-${escapeHtml(skill.category)} ${skill.is_participation ? 'is-participation' : ''} ${achieved ? 'is-achieved' : ''} ${locked ? 'is-locked' : ''}">
                            <label class="rink-assess-skill-toggle">
                                <input type="checkbox" data-rink-assess-skill data-skater-id="${escapeHtml(skater.public_id)}" data-skill-id="${escapeHtml(skill.id)}" data-achievement-date="${escapeHtml(achievementDate)}" aria-label="${escapeHtml(actionLabel)}" title="${escapeHtml(locked ? 'Recorded on an earlier day — cannot remove in Coach App.' : '')}" ${achieved ? 'checked' : ''} ${assessMutating || !canEditRink ? 'disabled' : ''}>
                            </label>
                        </td>`;
                    }).join('') : ''}
                </tr>`;
            }).join('');
            const selectionInputs = Array.from(assessBody.querySelectorAll('[data-rink-select-report-card-skater]'));
            const selectAll = assessHead?.querySelector('[data-rink-select-all-report-cards]');
            if (selectAll) {
                const selectedCount = selectionInputs.filter((input) => input.checked).length;
                selectAll.checked = selectionInputs.length > 0 && selectedCount === selectionInputs.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < selectionInputs.length;
                selectAll.disabled = selectionInputs.length === 0;
            }
        }
        if (assessSummary) {
            const skillCount = (ribbon) => ribbon.is_participation_ribbon ? 1 : (ribbon.skills || []).filter((skill) => !skill.is_participation).length;
            const requirements = ribbons.map((ribbon) => `${ribbon.name} ${ribbon.required_count || skillCount(ribbon)}/${skillCount(ribbon)}`).join(' · ');
            assessSummary.innerHTML = `<strong>${escapeHtml(stageLabel)}</strong><small>${activeAssessCategory === 'all' ? `All categories · ${requirements} required` : selectedRibbon ? `${escapeHtml(selectedRibbon.name)} · ${selectedRibbon.required_count || skillCount(selectedRibbon)}/${skillCount(selectedRibbon)} required` : 'Choose a category to show skills.'}</small>`;
        }
        updateAssessSortHeaders();
    };
    const assessEndpoint = () => {
        const parameters = new URLSearchParams({season_id: String(seasonId), session_id: String(sessionId)});
        if (groupId) parameters.set('group_id', String(groupId));
        return withQuery(body.dataset.rinkAssessUrl || '', parameters);
    };
    const refreshAssess = async () => {
        const assessUrl = body.dataset.rinkAssessUrl || '';
        if (!assessUrl) return;
        assessData = await request(assessEndpoint());
        renderAssess();
    };
    if (isAssessView && assessRoot && assessData) {
        assessRoot.addEventListener('click', (event) => {
            const button = event.target.closest('[data-rink-assess-stage]');
            if (!button || !assessRoot.contains(button)) return;
            const stageId = button.dataset.stageId || '';
            if (activeAssessStageId === stageId) {
                activeAssessStageId = null;
                activeAssessCategory = '';
            } else {
                activeAssessStageId = stageId;
                if (Number(button.dataset.stageNumber || '') === 0) {
                    activeAssessCategory = 'all';
                } else if (!['all', 'balance', 'control', 'agility'].includes(activeAssessCategory)) {
                    activeAssessCategory = 'all';
                }
            }
            renderAssess();
            saveViewState();
        });
        assessCategorySelector?.addEventListener('click', (event) => {
            if (assessCategorySelector.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                return;
            }
            const option = event.target.closest('[data-rink-assess-category-option]');
            if (!option || !assessCategorySelector.contains(option)) return;
            activeAssessCategory = String(option.dataset.category || '').toLowerCase();
            assessCategorySelector.removeAttribute('open');
            renderAssess();
            saveViewState();
        });
        assessHead?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-rink-assess-sort]');
            if (!button || !assessHead.contains(button)) return;
            const key = button.dataset.rinkAssessSort;
            assessActiveSort = assessActiveSort.key === key
                ? {
                    key,
                    direction: assessActiveSort.direction === 'asc' ? 'desc' : 'asc',
                }
                : {key, direction: 'asc'};
            renderAssess();
            saveViewState();
        });
        assessBody?.addEventListener('click', (event) => {
            const reportCardNoteButton = event.target.closest('[data-rink-edit-report-card-note]');
            if (reportCardNoteButton && assessBody.contains(reportCardNoteButton)) {
                openReportCardNote(reportCardNoteButton.dataset.rinkEditReportCardNote);
                return;
            }
            const profileButton = event.target.closest('[data-profile-skater]');
            if (profileButton && assessBody?.contains(profileButton)) {
                openProfile(profileButton.dataset.profileSkater);
            }
            const awardButton = event.target.closest('[data-rink-assess-award]');
            if (awardButton && assessBody?.contains(awardButton)) {
                openProfile(awardButton.dataset.skaterId, awardButton.dataset.stageId);
            }
        });
        assessBody?.addEventListener('change', async (event) => {
            const input = event.target.closest('[data-rink-assess-skill]');
            if (!input || assessMutating) return;
            const skaterId = input.dataset.skaterId || '';
            const skillId = Number(input.dataset.skillId || 0);
            const achievementDate = String(input.dataset.achievementDate || '');
            const removing = achievementDate !== '' && !input.checked;
            if (removing && achievementDate !== localCalendarDate()) {
                input.checked = true;
                showToast(assessRemovalMessage, true, true);
                return;
            }
            assessMutating = true;
            assessBody.querySelectorAll('[data-rink-assess-skill]').forEach((control) => { control.disabled = true; });
            try {
                await request(`${apiBase}/${encodeURIComponent(skaterId)}/skills/${skillId}`, {
                    method: removing ? 'DELETE' : 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: contextBody(),
                });
                showToast(removing ? 'Skill removed.' : 'Skill added.');
                await refreshAssess();
            } catch (error) {
                input.checked = !input.checked;
                showToast(error.message, true);
                await refreshAssess();
            } finally {
                assessMutating = false;
                assessBody.querySelectorAll('[data-rink-assess-skill]').forEach((control) => { control.disabled = false; });
            }
        });
        renderAssess();
    }
    const applyGroupAssignment = (result) => {
        const updates = Array.isArray(result?.updates) ? result.updates : [];
        updates.forEach((update) => {
            if (Number(update.session_id) !== sessionId) return;
            const index = rosterData?.skaters.findIndex((skater) => skater.public_id === update.skater_id) ?? -1;
            if (index < 0) return;
            if (groupId && Number(update.group_id || 0) !== groupId) {
                rosterData.skaters.splice(index, 1);
                return;
            }
            Object.assign(rosterData.skaters[index], {
                group_id: update.group_id,
                group_name: update.group_name || 'No group',
                group_colour: update.group_colour || '#ffffff',
            });
        });
        renderRoster();
    };
    document.querySelectorAll('[data-rink-sort]').forEach((button) => button.addEventListener('click', () => {
        const key = button.dataset.rinkSort;
        activeSort = activeSort.key === key
            ? {key, direction: activeSort.direction === 'asc' ? 'desc' : 'asc'}
            : {key, direction: 'asc'};
        renderRoster();
        saveViewState();
    }));

    const rosterEndpoint = () => {
        const parameters = new URLSearchParams({season_id: String(seasonId), session_id: String(sessionId)});
        if (groupId) parameters.set('group_id', String(groupId));
        return withQuery(body.dataset.rinkRosterUrl || '', parameters);
    };
    const refreshRoster = async () => {
        if (!rosterBody || isMutating || document.hidden || document.querySelector('dialog[open]')) return;
        const refreshVersion = ++rosterRefreshVersion;
        try {
            const refreshedRoster = await request(rosterEndpoint());
            if (refreshVersion !== rosterRefreshVersion || isMutating) return;
            rosterData = refreshedRoster;
            renderRoster();
        } catch {}
    };

    const groupFilterSelector = document.querySelector('[data-rink-group-selector]');
    const applyGroupSelectorColour = (colour, topColour = colour) => {
        if (!groupFilterSelector) return;
        const summary = groupFilterSelector.querySelector('summary');
        if (summary?.classList.contains('is-all-groups')) {
            groupFilterSelector.style.setProperty('--selector-colour', '#64748b');
            summary.style.removeProperty('border-top-color');
            return;
        }
        const selectorColour = colour || '#64748b';
        groupFilterSelector.style.setProperty('--selector-colour', selectorColour);
        if (summary) summary.style.borderTopColor = topColour || selectorColour;
    };
    applyGroupSelectorColour(
        groupFilterSelector?.dataset.selectorColour || '#64748b',
        groupFilterSelector?.dataset.selectorTopColour || undefined
    );
    const renderGroupSelectorSummary = (groupName, selectedGroupCount = 0) => {
        const summary = groupFilterSelector?.querySelector('summary');
        if (!summary) return;
        summary.classList.toggle('is-all-groups', !groupId);
        summary.querySelector('strong').textContent = groupId ? groupName : 'All Groups';
        const subtitle = summary.querySelector('small');
        const allGroupCount = Number(groupFilterSelector?.dataset.allGroupCount || 0);
        subtitle.textContent = groupId
            ? `${selectedGroupCount} ${selectedGroupCount === 1 ? 'skater' : 'skaters'}`
            : `${allGroupCount} ${allGroupCount === 1 ? 'skater' : 'skaters'}`;
    };
    const ensureGroupFilterOption = (update) => {
        if (!groupFilterSelector || !update?.group_id) return;
        const options = Array.from(groupFilterSelector.querySelectorAll('[data-rink-group-filter]'));
        if (options.some((option) => Number(option.dataset.groupId || 0) === Number(update.group_id))) return;
        const allGroupsOption = options.find((option) => !option.dataset.groupId);
        const menu = groupFilterSelector.querySelector('.rink-bar-group-menu');
        if (!allGroupsOption || !menu) return;
        const optionUrl = new URL(allGroupsOption.href);
        optionUrl.searchParams.set('group_id', String(update.group_id));
        const name = update.group_name || 'Group';
        const colour = update.group_colour || '#ffffff';
        menu.insertAdjacentHTML('beforeend', `<a data-rink-group-filter data-group-id="${escapeHtml(update.group_id)}" data-group-name="${escapeHtml(name)}" data-group-colour="${escapeHtml(colour)}" data-group-count="0" href="${escapeHtml(optionUrl.toString())}"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="${escapeHtml(colour)}" stroke="#5f6e80" stroke-width="1.5"></circle></svg><span><strong>${escapeHtml(name)}</strong><small>0 skaters</small></span></a>`);
        if (!groupId) renderGroupSelectorSummary('All Groups');
    };
    const adjustGroupFilterCount = (targetGroupId, adjustment) => {
        if (!targetGroupId || !groupFilterSelector || !adjustment) return;
        const option = groupFilterSelector.querySelector(`[data-rink-group-filter][data-group-id="${CSS.escape(String(targetGroupId))}"]`);
        if (!option) return;
        const count = Math.max(0, Number(option.dataset.groupCount || 0) + adjustment);
        option.dataset.groupCount = String(count);
        option.hidden = count === 0;
        const countLabel = option.querySelector('small');
        if (countLabel) countLabel.textContent = `${count} ${count === 1 ? 'skater' : 'skaters'}`;
        if (Number(groupId || 0) === Number(targetGroupId)) {
            renderGroupSelectorSummary(option.dataset.groupName || 'Group', count);
        }
    };
    groupFilterSelector?.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-rink-group-filter]');
        if (!link || !groupFilterSelector.contains(link)) return;
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        groupId = Number(link.dataset.groupId || 0);
        body.dataset.groupId = groupId ? String(groupId) : '';
        groupFilterSelector.removeAttribute('open');
        groupFilterSelector.querySelectorAll('[data-rink-group-filter]').forEach((option) => {
            option.classList.toggle('is-selected', option === link);
        });
        const groupName = link.dataset.groupName || 'All Groups';
        renderGroupSelectorSummary(groupName, Number(link.dataset.groupCount || 0));
        const selectedColour = link.dataset.groupColour || '#64748b';
        applyGroupSelectorColour(selectedColour);
        document.querySelectorAll('.rink-activity-card').forEach((activityLink) => {
            const activityUrl = new URL(activityLink.href);
            if (groupId) activityUrl.searchParams.set('group_id', String(groupId));
            else activityUrl.searchParams.delete('group_id');
            activityLink.href = activityUrl.toString();
        });
        window.history.replaceState({}, '', link.href);
        rosterRefreshVersion += 1;
        saveViewState();
        if (isAssessView) {
            await refreshAssess();
        } else {
            await refreshRoster();
        }
    });

    const groupDialog = document.querySelector('#rink-group-dialog');
    const groupTitle = groupDialog?.querySelector('[data-rink-group-title]');
    const groupMessage = groupDialog?.querySelector('[data-rink-group-message]');
    let activeGroupSkaterId = null;
    let activeGroupId = null;
    let activeGroupSkaterName = '';
    rosterBody?.addEventListener('click', (event) => {
        const groupButton = event.target.closest('[data-rink-group-button]');
        if (groupButton) {
            activeGroupSkaterId = groupButton.dataset.skaterId;
            activeGroupId = Number(groupButton.dataset.groupId || 0) || null;
            activeGroupSkaterName = groupButton.dataset.skaterName || 'Skater';
            if (groupTitle) groupTitle.textContent = `Choose a group for ${groupButton.dataset.skaterName}`;
            if (groupMessage) groupMessage.hidden = true;
            groupDialog?.querySelectorAll('[data-rink-group-choice]').forEach((choice) => {
                const selected = choice.dataset.unassign === '1'
                    ? !groupButton.dataset.groupId
                    : String(choice.dataset.groupName || '').toLocaleLowerCase() === String(groupButton.dataset.groupName || '').toLocaleLowerCase();
                choice.classList.toggle('is-selected', selected);
                choice.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            groupDialog?.showModal();
            return;
        }
        const profileButton = event.target.closest('[data-profile-skater]');
        if (profileButton) {
            openProfile(profileButton.dataset.profileSkater, null, true);
            return;
        }
        const medicalButton = event.target.closest('[data-medical-skater]');
        if (medicalButton) openMedical(medicalButton.dataset.medicalSkater);
        const generalNoteButton = event.target.closest('[data-general-note-skater]');
        if (generalNoteButton) openGeneralNote(generalNoteButton.dataset.generalNoteSkater);
        const reportCardNoteButton = event.target.closest('[data-rink-edit-report-card-note]');
        if (reportCardNoteButton) openReportCardNote(reportCardNoteButton.dataset.rinkEditReportCardNote);
    });
    groupDialog?.querySelectorAll('[data-rink-group-choice]').forEach((choice) => choice.addEventListener('click', async () => {
        if (!activeGroupSkaterId) return;
        const choices = Array.from(groupDialog.querySelectorAll('[data-rink-group-choice]'));
        choices.forEach((button) => { button.disabled = true; });
        rosterRefreshVersion += 1;
        isMutating = true;
        if (groupMessage) groupMessage.hidden = true;
        try {
            const existingGroupId = choice.dataset.groupId ? Number(choice.dataset.groupId) : null;
            const createFromPalette = choice.dataset.unassign !== '1' && existingGroupId === null;
            const response = await request(body.dataset.rinkGroupUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: contextBody({
                    skater_id: activeGroupSkaterId,
                    group_id: existingGroupId,
                    group_name: createFromPalette ? choice.dataset.groupName : null,
                    group_colour: createFromPalette ? choice.dataset.groupColour : null,
                }),
            });
            applyGroupAssignment(response.result);
            const update = response.result?.updates?.find((candidate) => Number(candidate.session_id) === sessionId);
            if (update?.group_id) ensureGroupFilterOption(update);
            const nextGroupId = Number(update?.group_id || 0) || null;
            if (update && activeGroupId !== nextGroupId) {
                adjustGroupFilterCount(activeGroupId, -1);
                adjustGroupFilterCount(nextGroupId, 1);
                activeGroupId = nextGroupId;
            }
            if (createFromPalette && update?.group_id) choice.dataset.groupId = String(update.group_id);
            groupDialog.close();
            showToast(response.message || 'Group assignment updated.');
        } catch (error) {
            if (groupMessage) {
                groupMessage.textContent = error.message;
                groupMessage.hidden = false;
            }
        } finally {
            choices.forEach((button) => { button.disabled = false; });
            isMutating = false;
            await refreshRoster();
        }
    }));

    rosterBody?.addEventListener('change', async (event) => {
        const checkbox = event.target.closest('[data-rink-attendance]');
        if (!checkbox) return;
        const present = checkbox.checked;
        const skater = rosterData?.skaters.find((candidate) => candidate.public_id === checkbox.dataset.skaterId);
        isMutating = true;
        checkbox.disabled = true;
        try {
            const result = await request(body.dataset.rinkAttendanceUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: contextBody({skater_id: checkbox.dataset.skaterId, present}),
            });
            if (skater) {
                skater.present = present;
                skater.attendance_recorded = true;
                skater.attendance_status = present ? 'PRESENT' : 'ABSENT';
            }
            showToast(result.message || (present ? 'Marked present.' : 'Marked absent.'));
        } catch (error) {
            checkbox.checked = !present;
            showToast(error.message, true);
        } finally {
            isMutating = false;
            renderRoster();
        }
    });

    const profileDialog = document.querySelector('#rink-profile-dialog');
    const profileContent = profileDialog?.querySelector('[data-rink-profile-content]');
    const priorAchievementRemovalMessage = 'Achievements added prior to today cannot be removed in the Coach App. Ask an administrator to make this change via the Skater Dashboard.';
    let activeProfileData = null;
    let activeProfileId = null;
    let profileAchievementOnly = false;
    let profileRosterOnly = false;
    let achievementMutating = false;
    const expandedAchievementStages = new Set();
    const formatDate = (value) => value ? new Intl.DateTimeFormat(undefined, {year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC'}).format(new Date(`${value}T00:00:00Z`)) : '—';
    const profileItem = (label, value, wide = false) => `<div class="rink-profile-item ${wide ? 'is-wide' : ''}"><span>${escapeHtml(label)}</span><strong>${value ? escapeHtml(value) : '—'}</strong></div>`;
    const formatSessionTime = (value) => {
        const [hours, minutes] = String(value || '').split(':').map(Number);
        if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return '';
        return new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(new Date(2000, 0, 1, hours, minutes));
    };
    const rosterProfileRegistrations = (enrollments) => {
        const selectedSeasonName = document.querySelector('[data-rink-season] option:checked')?.textContent?.trim() || 'Selected season';
        const sessions = (enrollments || []).filter((enrollment) => Number(enrollment.season_id) === seasonId);
        const items = sessions.map((enrollment) => {
            const time = [formatSessionTime(enrollment.start_time), formatSessionTime(enrollment.end_time)].filter(Boolean).join('–');
            const schedule = [enrollment.day_of_week_name, time, enrollment.location].filter(Boolean).join(' · ');
            const group = enrollment.group_name ? ` · ${enrollment.group_name}` : '';
            return `<li><strong>${escapeHtml(enrollment.session_name || 'Session')}</strong><span>${escapeHtml(schedule || 'Schedule not specified')}${escapeHtml(group)}</span></li>`;
        }).join('') || '<li class="is-empty">Not registered in any sessions this season.</li>';
        return `<section class="rink-profile-registrations"><span class="eyebrow">Selected season registrations</span><h3>${escapeHtml(selectedSeasonName)}</h3><ul>${items}</ul></section>`;
    };
    const badgeIcon = () => window.CAT.skateCanadaBadgeIcon();
    const ribbonIcon = (ribbon) => `<svg viewBox="0 0 24 28" aria-hidden="true"><circle class="ribbon-icon-shape ribbon-icon-centre" cx="12" cy="8" r="7"></circle><path class="ribbon-icon-shape" d="M8 13.2 5.5 26 12 22.2 18.5 26 16 13.2"></path><text class="ribbon-icon-letter" x="12" y="10.7">${escapeHtml(String(ribbon.name || '').charAt(0).toUpperCase())}</text></svg>`;
    const achievementSummary = (stages) => `<div class="rink-achievement-summary">${(stages || []).map((stage) => {
        const ribbons = stage.ribbons || [];
        const stageLabel = stage.name || `Stage ${stage.number}`;
        const requiredRibbonCount = Number(stage.required_ribbon_count || ribbons.length);
        const awardedRibbonCount = ribbons.filter((ribbon) => Boolean(ribbon.awarded_at)).length;
        const eligible = requiredRibbonCount > 0 && awardedRibbonCount >= requiredRibbonCount;
        const expanded = expandedAchievementStages.has(Number(stage.id));
        const ribbonSkillCount = (ribbon) => ribbon.is_participation_ribbon
            ? 1
            : (ribbon.skills || []).filter((skill) => !skill.is_participation).length;
        const ribbonAchievedCount = (ribbon) => (ribbon.skills || []).filter((skill) => (
            ribbon.is_participation_ribbon ? skill.is_participation : !skill.is_participation
        ) && skill.achieved).length;
        const ribbonRequiredCount = (ribbon) => Math.min(ribbonSkillCount(ribbon), Number(ribbon.required_count || ribbonSkillCount(ribbon)));
        const stageBadge = stage.has_badge ? `<label class="achievement-editor-modal-award-toggle achievement-editor-modal-stage-award"><input type="checkbox" data-rink-achievement-type="badges" data-rink-achievement-id="${escapeHtml(stage.id)}" ${stage.badge_awarded_at ? 'checked' : ''} ${!canEditRink || achievementMutating || (!stage.badge_awarded_at && !eligible) ? 'disabled' : ''}><span>Stage badge awarded</span></label><span class="stage-badge-button stage-award-control stage-badge-stage-${escapeHtml(stage.number)} ${eligible ? 'is-eligible' : ''} ${stage.badge_awarded_at ? 'is-awarded' : ''}">${badgeIcon(stage)}<small>${awardedRibbonCount}/${requiredRibbonCount}</small></span>` : '';
        const ribbonStatus = ribbons.map((ribbon) => {
            const achieved = ribbonAchievedCount(ribbon);
            const required = ribbonRequiredCount(ribbon);
            const total = ribbonSkillCount(ribbon);
            const ribbonEligible = required > 0 && achieved >= required;
            const title = `${ribbon.name}: ${achieved}/${total} skills achieved; ${required}/${total} required`;
            return `<span class="ribbon-detail-button stage-ribbon-detail ribbon-${escapeHtml(String(ribbon.name || '').toLowerCase())} ${ribbonEligible ? 'is-eligible' : ''} ${ribbon.awarded_at ? 'is-awarded' : ''}" title="${escapeHtml(title)}">${ribbonIcon(ribbon)}<small>${achieved}/${total}</small></span>`;
        }).join('');
        const editor = ribbons.map((ribbon) => {
            const skills = ribbon.skills || [];
            const achieved = ribbonAchievedCount(ribbon);
            const required = ribbonRequiredCount(ribbon);
            const total = ribbonSkillCount(ribbon);
            const ribbonEligible = required > 0 && achieved >= required;
            return `<section class="achievement-editor-modal-ribbon"><div class="achievement-editor-modal-ribbon-head"><h3>${escapeHtml(ribbon.name)} <small>${required}/${total} required</small></h3><label class="achievement-editor-modal-award-toggle"><input type="checkbox" data-rink-achievement-type="ribbons" data-rink-achievement-id="${escapeHtml(ribbon.id)}" ${ribbon.awarded_at ? 'checked' : ''} ${!canEditRink || achievementMutating || (!ribbon.awarded_at && !ribbonEligible) ? 'disabled' : ''}><span>Ribbon awarded</span></label></div><div class="achievement-editor-modal-skills">${skills.map((skill) => `<label class="achievement-editor-modal-skill ${skill.is_participation ? 'is-participation' : ''} ${skill.achieved ? 'is-achieved' : ''}"><input type="checkbox" data-rink-achievement-type="skills" data-rink-achievement-id="${escapeHtml(skill.id)}" ${skill.achieved ? 'checked' : ''} ${!canEditRink || achievementMutating ? 'disabled' : ''}><span>${escapeHtml(skill.name || skill.description || 'Unnamed skill')}</span></label>`).join('')}</div></section>`;
        }).join('');
        return `<section class="rink-achievement-stage achievement-editor-modal-stage stage-colour-${escapeHtml(stage.number)} ${expanded ? 'is-expanded' : ''}"><div class="rink-achievement-stage-summary"><button class="rink-achievement-expand" type="button" data-rink-achievement-stage="${escapeHtml(stage.id)}" aria-expanded="${expanded ? 'true' : 'false'}" aria-label="${expanded ? 'Collapse' : 'Expand'} ${escapeHtml(stageLabel)}"><span>${expanded ? '−' : '+'}</span></button><button class="rink-achievement-stage-name" type="button" data-rink-achievement-stage="${escapeHtml(stage.id)}"><strong>${escapeHtml(stageLabel)}</strong><small>${awardedRibbonCount}/${requiredRibbonCount} ribbons awarded</small></button>${stageBadge}<span class="rink-detail-ribbons">${ribbonStatus}</span></div>${expanded ? `<div class="rink-achievement-stage-editor">${editor}</div>` : ''}</section>`;
    }).join('')}</div>`;

    const renderProfile = () => {
        if (!profileContent || !activeProfileData) return;
        const skater = activeProfileData.skater;
        const achievements = `<div class="rink-detail-achievements"><span class="eyebrow">Achievements</span><h2>${profileAchievementOnly ? `${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)} — Achievements` : 'Badges &amp; ribbons'}</h2><p class="rink-achievement-help">Open a stage to update its skills and awards. Changes save immediately.</p><p class="form-message error rink-achievement-message" data-rink-achievement-message hidden></p>${achievementSummary(activeProfileData.achievement_editor || [])}</div>`;
        const details = `<span class="eyebrow">Skater details</span><h2>${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)}</h2><div class="rink-profile-grid">${profileItem('Skate Canada no.', skater.skate_canada_number)}${profileItem('Date of birth', formatDate(skater.date_of_birth))}${profileItem('Gender', skater.gender_name)}${profileItem('Guardian', skater.parent_guardian_name)}${profileItem('Email', skater.parent_guardian_email, true)}${profileItem('Phone', skater.parent_guardian_phone, true)}</div>`;
        const assessDetails = `<span class="eyebrow">Skater details</span><h2>${escapeHtml(skater.first_name)} ${escapeHtml(skater.last_name)}</h2><div class="rink-profile-grid">${profileItem('Skate Canada no.', skater.skate_canada_number)}</div>`;
        const generalNotes = `<section class="rink-profile-general-notes"><span class="eyebrow">General notes</span><p>${escapeHtml(skater.general_notes || 'No general notes.')}</p></section>`;
        const medical = `<section class="rink-profile-medical"><span class="eyebrow">Medical / accommodations</span><p>${escapeHtml(skater.medical_notes || 'No medical or accommodation notes.')}</p></section>`;
        profileContent.innerHTML = profileAchievementOnly
            ? `${generalNotes}${achievements}`
            : profileRosterOnly
                ? `${assessDetails}${generalNotes}${medical}${rosterProfileRegistrations(activeProfileData.enrollments)}`
                : `${assessDetails}${generalNotes}${achievements}`;
    };

    const findAchievement = (type, id) => {
        for (const stage of activeProfileData?.achievement_editor || []) {
            if (type === 'badges' && Number(stage.id) === id) {
                return {active: Boolean(stage.badge_awarded_at), removableToday: Boolean(stage.badge_removable_today)};
            }
            for (const ribbon of stage.ribbons || []) {
                if (type === 'ribbons' && Number(ribbon.id) === id) {
                    return {active: Boolean(ribbon.awarded_at), removableToday: Boolean(ribbon.removable_today)};
                }
                const skill = (ribbon.skills || []).find((candidate) => Number(candidate.id) === id);
                if (type === 'skills' && skill) {
                    return {active: Boolean(skill.achieved), removableToday: Boolean(skill.removable_today)};
                }
            }
        }
        return null;
    };

    const showAchievementMessage = (message) => {
        const messageBox = profileContent?.querySelector('[data-rink-achievement-message]');
        if (!messageBox) return;
        messageBox.textContent = message;
        messageBox.hidden = false;
        messageBox.scrollIntoView({block: 'nearest'});
    };

    profileContent?.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-rink-achievement-stage]');
        if (!toggle) return;
        const stageId = Number(toggle.dataset.rinkAchievementStage || 0);
        if (expandedAchievementStages.has(stageId)) expandedAchievementStages.delete(stageId);
        else expandedAchievementStages.add(stageId);
        renderProfile();
    });

    profileContent?.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-rink-achievement-type]');
        if (!input || achievementMutating || !activeProfileId) return;
        const type = input.dataset.rinkAchievementType;
        const id = Number(input.dataset.rinkAchievementId || 0);
        const current = findAchievement(type, id);
        const removing = current?.active && !input.checked;
        if (!current || !['skills', 'ribbons', 'badges'].includes(type) || id <= 0) {
            input.checked = !input.checked;
            showAchievementMessage('This achievement could not be identified. Refresh the page and try again.');
            return;
        }
        if (removing && !current.removableToday) {
            input.checked = true;
            showAchievementMessage(priorAchievementRemovalMessage);
            return;
        }

        achievementMutating = true;
        profileContent.querySelectorAll('[data-rink-achievement-type]').forEach((control) => { control.disabled = true; });
        try {
            await request(`${apiBase}/${encodeURIComponent(activeProfileId)}/${type}/${id}`, {
                method: removing ? 'DELETE' : 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: contextBody(),
            });
            activeProfileData = await request(withQuery(
                `${apiBase}/${encodeURIComponent(activeProfileId)}`,
                {season_id: String(seasonId), session_id: String(sessionId)}
            ));
            if (isAssessView) {
                try {
                    await refreshAssess();
                } catch (error) {
                    // The saved achievement remains visible in the dialog if the roster refresh is temporarily unavailable.
                }
            }
            showToast(removing ? 'Achievement removed.' : 'Achievement added.');
            renderProfile();
        } catch (error) {
            input.checked = current.active;
            showAchievementMessage(error.message);
        } finally {
            achievementMutating = false;
            profileContent.querySelectorAll('[data-rink-achievement-type]').forEach((control) => { control.disabled = false; });
        }
    });

    async function openProfile(publicId, focusStageId = null, rosterOnly = false) {
        if (!profileDialog || !profileContent) return;
        activeProfileId = publicId;
        activeProfileData = null;
        profileAchievementOnly = focusStageId !== null && focusStageId !== '';
        profileRosterOnly = rosterOnly;
        expandedAchievementStages.clear();
        if (focusStageId !== null && focusStageId !== '') {
            expandedAchievementStages.add(Number(focusStageId));
        }
        profileContent.innerHTML = '<div class="loading-state"><span></span><p>Loading skater details…</p></div>';
        profileDialog.showModal();
        try {
            activeProfileData = await request(withQuery(
                `${apiBase}/${encodeURIComponent(publicId)}`,
                {season_id: String(seasonId), session_id: String(sessionId)}
            ));
            renderProfile();
        } catch (error) {
            profileContent.innerHTML = `<p class="form-message error">${escapeHtml(error.message)}</p>`;
        }
    }

    const medicalDialog = document.querySelector('#rink-medical-dialog');
    function openMedical(publicId) {
        const skater = rosterData?.skaters.find((candidate) => candidate.public_id === publicId);
        if (!skater || !medicalDialog) return;
        medicalDialog.querySelector('[data-medical-title]').textContent = `${skater.first_name} ${skater.last_name}`;
        medicalDialog.querySelector('[data-medical-content]').textContent = skater.medical_notes;
        medicalDialog.showModal();
    }

    const generalNoteDialog = document.querySelector('#rink-general-note-dialog');
    function openGeneralNote(publicId) {
        const skater = rosterData?.skaters.find((candidate) => candidate.public_id === publicId);
        if (!skater || !generalNoteDialog) return;
        generalNoteDialog.querySelector('[data-general-note-title]').textContent = `${skater.first_name} ${skater.last_name}`;
        generalNoteDialog.querySelector('[data-general-note-content]').textContent = skater.general_notes;
        generalNoteDialog.showModal();
    }

    function openReportCardNote(publicId) {
        const skater = [...(assessData?.skaters || []), ...(rosterData?.skaters || [])]
            .find((candidate) => candidate.public_id === publicId);
        if (!skater || !reportCardNoteDialog || !reportCardNoteInput || !reportCardNoteTitle) return;
        setReportCardsPanelOpen(true);
        reportCardNoteSkaterId = publicId;
        reportCardNoteTitle.textContent = `${skater.first_name} ${skater.last_name}`;
        if (reportCardRegistration) {
            const sessionCount = Number(skater.season_session_count) || 0;
            reportCardRegistration.textContent = `Registered in ${sessionCount} session${sessionCount === 1 ? '' : 's'} this season`;
        }
        reportCardNoteInput.value = skater.report_card_notes || '';
        reportCardNoteOriginalValue = reportCardNoteInput.value;
        if (reportCardNoteDelete) reportCardNoteDelete.hidden = reportCardNoteInput.value.trim() === '';
        updateReportCardNoteCount();
        updateReportCardNoteSaveState();
        updateReportCardNoteUpdated(skater);
        renderReportCardAchievements(skater);
        updateReportCardBackdropBoundary();
        if (reportCardNoteBackdrop) reportCardNoteBackdrop.hidden = false;
        if (reportCardNoteMessage) reportCardNoteMessage.hidden = true;
        if (!reportCardNoteDialog.open) reportCardNoteDialog.show();
        reportCardNoteInput.focus();
    }

    const renderNoteLibrary = () => {
        if (!noteLibraryList) return;
        noteLibraryList.innerHTML = noteLibrary.map((note) => `<button class="rink-note-library-oval" type="button" draggable="true" data-rink-library-note-id="${escapeHtml(note.id)}" title="${escapeHtml(note.title)}"><span>${escapeHtml(note.title)}</span></button>`).join('');
    };
    const hideNoteLibraryActions = () => {
        activeLibraryNoteId = null;
        if (noteLibraryActions) noteLibraryActions.hidden = true;
    };
    const cancelNoteLibraryActionsHide = () => {
        if (noteLibraryActionsTimer) window.clearTimeout(noteLibraryActionsTimer);
        noteLibraryActionsTimer = null;
    };
    const scheduleNoteLibraryActionsHide = () => {
        cancelNoteLibraryActionsHide();
        noteLibraryActionsTimer = window.setTimeout(hideNoteLibraryActions, 120);
    };
    const showNoteLibraryActions = (oval) => {
        const noteId = oval?.dataset.rinkLibraryNoteId;
        if (!noteId || !noteLibraryActions) return;
        cancelNoteLibraryActionsHide();
        activeLibraryNoteId = noteId;
        const rect = oval.getBoundingClientRect();
        noteLibraryActions.style.left = `${rect.left}px`;
        noteLibraryActions.style.top = `${Math.max(4, rect.top - 21)}px`;
        noteLibraryActions.querySelector('[data-rink-edit-library-note]')?.setAttribute('data-rink-edit-library-note', noteId);
        noteLibraryActions.querySelector('[data-rink-delete-library-note]')?.setAttribute('data-rink-delete-library-note', noteId);
        noteLibraryActions.hidden = false;
    };
    const loadNoteLibrary = async () => {
        const url = body.dataset.rinkReportCardNoteLibraryUrl || '';
        if (!url) return;
        const data = await request(url);
        noteLibrary = Array.isArray(data.notes) ? data.notes : [];
        renderNoteLibrary();
    };
    const appendLibraryNoteToReportCard = (note) => {
        if (!note || !reportCardNoteDialog?.open || !reportCardNoteInput) return;
        const skater = [...(rosterData?.skaters || []), ...(assessData?.skaters || [])]
            .find((candidate) => candidate.public_id === reportCardNoteSkaterId);
        const replacements = {
            '{skater first name}': skater?.first_name || '',
            '{skater last name}': skater?.last_name || '',
            '{coach first name}': body.dataset.currentUserFirstName || '',
            '{coach last name}': body.dataset.currentUserLastName || '',
        };
        const content = Object.entries(replacements).reduce(
            (value, [token, replacement]) => value.replaceAll(token, replacement),
            String(note.content || '')
        );
        const separator = reportCardNoteInput.value.trim() === '' ? '' : ' ';
        const nextValue = `${reportCardNoteInput.value}${separator}${content}`;
        reportCardNoteInput.value = nextValue.slice(0, Number(reportCardNoteInput.maxLength) || nextValue.length);
        updateReportCardNoteCount();
        updateReportCardNoteSaveState();
        reportCardNoteInput.focus();
    };

    const openNoteLibraryEditor = (note = null) => {
        if (!noteLibraryDialog || !noteLibraryTitleInput || !noteLibraryContentInput) return;
        editingLibraryNoteId = note ? Number(note.id) : null;
        noteLibraryDialog.querySelector('#rink-note-library-title').textContent = note ? 'Edit custom note' : 'Create reusable note clip';
        noteLibraryTitleInput.value = note?.title || '';
        noteLibraryContentInput.value = note?.content || '';
        if (noteLibraryMessage) noteLibraryMessage.hidden = true;
        updateNoteLibraryCounts();
        noteLibraryDialog.showModal();
        noteLibraryTitleInput.focus();
    };
    openNoteLibraryButton?.addEventListener('click', () => openNoteLibraryEditor());
    const updateNoteLibraryCounts = () => {
        if (noteLibraryTitleCount && noteLibraryTitleInput) {
            noteLibraryTitleCount.textContent = `${Math.max(0, 32 - noteLibraryTitleInput.value.length)} characters remaining`;
        }
        if (noteLibraryContentCount && noteLibraryContentInput) {
            noteLibraryContentCount.textContent = `${Math.max(0, 512 - noteLibraryContentInput.value.length)} characters remaining`;
        }
    };
    noteLibraryTitleInput?.addEventListener('input', updateNoteLibraryCounts);
    noteLibraryContentInput?.addEventListener('input', updateNoteLibraryCounts);
    const insertNoteLibraryFieldToken = (token) => {
        if (!noteLibraryContentInput || !token) return;
        const start = noteLibraryContentInput.selectionStart ?? noteLibraryContentInput.value.length;
        const end = noteLibraryContentInput.selectionEnd ?? start;
        const nextValue = `${noteLibraryContentInput.value.slice(0, start)}${token}${noteLibraryContentInput.value.slice(end)}`;
        noteLibraryContentInput.value = nextValue.slice(0, Number(noteLibraryContentInput.maxLength) || nextValue.length);
        const nextPosition = Math.min(start + token.length, noteLibraryContentInput.value.length);
        noteLibraryContentInput.setSelectionRange(nextPosition, nextPosition);
        noteLibraryContentInput.focus();
        updateNoteLibraryCounts();
    };
    noteLibraryDialog?.querySelector('.rink-note-library-fields')?.addEventListener('click', (event) => {
        const field = event.target.closest('[data-rink-note-library-field-token]');
        if (field) insertNoteLibraryFieldToken(field.dataset.rinkNoteLibraryFieldToken);
    });
    noteLibraryDialog?.querySelector('.rink-note-library-fields')?.addEventListener('dragstart', (event) => {
        const field = event.target.closest('[data-rink-note-library-field-token]');
        if (!field || !event.dataTransfer) return;
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', field.dataset.rinkNoteLibraryFieldToken || '');
    });
    noteLibraryContentInput?.addEventListener('dragover', (event) => event.preventDefault());
    noteLibraryContentInput?.addEventListener('drop', (event) => {
        event.preventDefault();
        insertNoteLibraryFieldToken(event.dataTransfer?.getData('text/plain') || '');
    });
    noteLibraryDialog?.querySelector('[data-rink-note-library-cancel]')?.addEventListener('click', () => noteLibraryDialog.close());
    noteLibraryForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!noteLibraryTitleInput || !noteLibraryContentInput || !noteLibrarySave) return;
        noteLibrarySave.disabled = true;
        if (noteLibraryMessage) noteLibraryMessage.hidden = true;
        try {
            const response = await request(
                editingLibraryNoteId
                    ? `${body.dataset.rinkReportCardNoteLibraryUrl || ''}/${encodeURIComponent(editingLibraryNoteId)}`
                    : (body.dataset.rinkReportCardNoteLibraryUrl || ''),
                {
                method: editingLibraryNoteId ? 'PUT' : 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: JSON.stringify({title: noteLibraryTitleInput.value, content: noteLibraryContentInput.value}),
                }
            );
            if (editingLibraryNoteId) {
                const index = noteLibrary.findIndex((note) => Number(note.id) === editingLibraryNoteId);
                if (index >= 0) noteLibrary[index] = response.note;
            } else {
                noteLibrary.push(response.note);
            }
            renderNoteLibrary();
            noteLibraryDialog.close();
            showToast(response.message || 'Note added to your library.');
        } catch (error) {
            if (noteLibraryMessage) {
                noteLibraryMessage.textContent = error.message;
                noteLibraryMessage.className = 'form-message error';
                noteLibraryMessage.hidden = false;
            }
        } finally {
            noteLibrarySave.disabled = false;
        }
    });
    const handleNoteLibraryAction = async (event) => {
        const edit = event.target.closest('[data-rink-edit-library-note]');
        if (edit) {
            const note = noteLibrary.find((candidate) => Number(candidate.id) === Number(edit.dataset.rinkEditLibraryNote));
            if (note) {
                hideNoteLibraryActions();
                openNoteLibraryEditor(note);
            }
            return true;
        }
        const remove = event.target.closest('[data-rink-delete-library-note]');
        if (remove) {
            const note = noteLibrary.find((candidate) => Number(candidate.id) === Number(remove.dataset.rinkDeleteLibraryNote));
            if (!note) return true;
            const confirmed = await window.CAT.confirm({
                eyebrow: 'Delete library note',
                title: `Delete “${note.title}”?`,
                message: 'This reusable note will be permanently deleted.',
                cancelLabel: 'Keep note',
                confirmLabel: 'Delete note',
                tone: 'danger',
            });
            if (!confirmed) return true;
            try {
                await request(`${body.dataset.rinkReportCardNoteLibraryUrl || ''}/${encodeURIComponent(note.id)}`, {
                    method: 'DELETE',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                    body: JSON.stringify({}),
                });
                noteLibrary = noteLibrary.filter((candidate) => Number(candidate.id) !== Number(note.id));
                hideNoteLibraryActions();
                renderNoteLibrary();
                showToast('Library note deleted.');
            } catch (error) {
                showToast(error.message, true);
            }
            return true;
        }
        return false;
    };
    noteLibraryList?.addEventListener('click', async (event) => {
        if (await handleNoteLibraryAction(event)) return;
        const oval = event.target.closest('[data-rink-library-note-id]');
        const note = noteLibrary.find((candidate) => Number(candidate.id) === Number(oval?.dataset.rinkLibraryNoteId));
        if (reportCardNoteDialog?.open) appendLibraryNoteToReportCard(note);
        else openNoteLibraryEditor(note);
    });
    noteLibraryList?.addEventListener('dragstart', (event) => {
        const oval = event.target.closest('[data-rink-library-note-id]');
        if (!oval || !event.dataTransfer) return;
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', oval.dataset.rinkLibraryNoteId || '');
    });
    noteLibraryList?.addEventListener('pointerover', (event) => {
        const oval = event.target.closest('[data-rink-library-note-id]');
        if (oval) showNoteLibraryActions(oval);
    });
    noteLibraryList?.addEventListener('pointerleave', scheduleNoteLibraryActionsHide);
    noteLibraryList?.addEventListener('focusin', (event) => {
        const oval = event.target.closest('[data-rink-library-note-id]');
        if (oval) showNoteLibraryActions(oval);
    });
    noteLibraryList?.addEventListener('focusout', scheduleNoteLibraryActionsHide);
    noteLibraryActions?.addEventListener('pointerenter', cancelNoteLibraryActionsHide);
    noteLibraryActions?.addEventListener('pointerleave', scheduleNoteLibraryActionsHide);
    noteLibraryActions?.addEventListener('focusin', cancelNoteLibraryActionsHide);
    noteLibraryActions?.addEventListener('focusout', scheduleNoteLibraryActionsHide);
    noteLibraryActions?.addEventListener('click', (event) => { void handleNoteLibraryAction(event); });
    noteLibraryList?.addEventListener('scroll', () => {
        if (!activeLibraryNoteId) return;
        showNoteLibraryActions(noteLibraryList.querySelector(`[data-rink-library-note-id="${activeLibraryNoteId}"]`));
    });
    reportCardNoteInput?.addEventListener('dragover', (event) => event.preventDefault());
    reportCardNoteInput?.addEventListener('input', () => {
        updateReportCardNoteCount();
        updateReportCardNoteSaveState();
    });
    reportCardNoteInput?.addEventListener('drop', (event) => {
        event.preventDefault();
        const note = noteLibrary.find((candidate) => Number(candidate.id) === Number(event.dataTransfer?.getData('text/plain')));
        appendLibraryNoteToReportCard(note);
    });
    if (isAssessView) {
        void loadNoteLibrary().catch((error) => showToast(error.message, true));
    }

    reportCardNoteDialog?.querySelector('[data-rink-report-card-note-cancel]')?.addEventListener('click', () => {
        reportCardNoteDialog.close();
    });
    reportCardNoteDelete?.addEventListener('click', async () => {
        if (!reportCardNoteSkaterId || !reportCardNoteDelete) return;
        const confirmed = await window.CAT.confirm({
            eyebrow: 'Delete report-card note',
            title: 'Delete this skater’s note?',
            message: 'The saved report-card note will be permanently removed.',
            cancelLabel: 'Keep note',
            confirmLabel: 'Delete note',
            tone: 'danger',
        });
        if (!confirmed) return;
        reportCardNoteDelete.disabled = true;
        if (reportCardNoteMessage) reportCardNoteMessage.hidden = true;
        try {
            const response = await request(body.dataset.rinkReportCardNotesUrl || '', {
                method: 'DELETE',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: contextBody({skater_id: reportCardNoteSkaterId}),
            });
            const skater = [...(rosterData?.skaters || []), ...(assessData?.skaters || [])]
                .find((candidate) => candidate.public_id === reportCardNoteSkaterId);
            if (skater) {
                skater.report_card_notes = '';
                skater.report_card_note_updated_by_name = '';
                skater.report_card_note_updated_at = null;
            }
            renderAssess();
            reportCardNoteDialog.close();
            showToast(response.message || 'Report-card note deleted.');
        } catch (error) {
            if (reportCardNoteMessage) {
                reportCardNoteMessage.textContent = error.message;
                reportCardNoteMessage.className = 'form-message error';
                reportCardNoteMessage.hidden = false;
            }
        } finally {
            reportCardNoteDelete.disabled = false;
        }
    });
    reportCardNoteDialog?.addEventListener('close', () => {
        if (reportCardNoteBackdrop) reportCardNoteBackdrop.hidden = true;
    });
    window.addEventListener('resize', () => {
        if (reportCardNoteDialog?.open) updateReportCardBackdropBoundary();
    });
    reportCardNoteForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!reportCardNoteSkaterId || !reportCardNoteInput || !reportCardNoteSave) return;
        reportCardNoteSave.disabled = true;
        if (reportCardNoteMessage) reportCardNoteMessage.hidden = true;
        try {
            const response = await request(body.dataset.rinkReportCardNotesUrl || '', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
                body: contextBody({skater_id: reportCardNoteSkaterId, note: reportCardNoteInput.value}),
            });
            const skater = [...(rosterData?.skaters || []), ...(assessData?.skaters || [])]
                .find((candidate) => candidate.public_id === reportCardNoteSkaterId);
            if (skater) {
                skater.report_card_notes = response.note || '';
                skater.report_card_note_updated_by_name = response.updated_by_name || '';
                skater.report_card_note_updated_at = response.updated_at || null;
            }
            renderAssess();
            reportCardNoteDialog.close();
            showToast(response.message || 'Report-card note saved.');
        } catch (error) {
            if (reportCardNoteMessage) {
                reportCardNoteMessage.textContent = error.message;
                reportCardNoteMessage.className = 'form-message error';
                reportCardNoteMessage.hidden = false;
            }
        } finally {
            updateReportCardNoteSaveState();
        }
    });

    document.querySelectorAll('dialog').forEach((dialog) => dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    }));

    const chatEndpoint = () => withQuery(
        body.dataset.rinkChatUrl || '',
        {season_id: String(seasonId), session_id: String(sessionId)}
    );
    const toDateTimeLocal = (date) => {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };
    const chatPosterColours = ['#f9d7df', '#fde3c4', '#fff1ad', '#d9efd5', '#cdeee8', '#d3e7fb', '#e3d9fb', '#f1d6ec', '#f3dfc9', '#dce6b9'];
    const chatColoursByPoster = (messages) => {
        const posterIds = [...new Set(messages
            .filter((message) => !message.deleted_at && Number(message.app_user_id) !== currentUserId)
            .map((message) => String(message.app_user_id)))]
            .sort((left, right) => left.localeCompare(right, undefined, {numeric: true}));
        return new Map(posterIds.map((posterId, index) => [
            posterId,
            chatPosterColours[index] || `hsl(${Math.round((index * 137.508) % 360)} 68% 86%)`,
        ]));
    };
    const renderChat = (stickToBottom = false) => {
        if (!chatHistory || !chatData) return;
        const wasNearBottom = chatHistory.scrollHeight - chatHistory.scrollTop - chatHistory.clientHeight < 40;
        const messages = chatData.messages || [];
        const posterColours = chatColoursByPoster(messages);
        chatHistory.innerHTML = messages.map((message) => {
            const mine = Number(message.app_user_id) === currentUserId;
            const deleted = Boolean(message.deleted_at);
            const editable = mine && !deleted;
            const bubbleColour = !mine && !deleted ? posterColours.get(String(message.app_user_id)) : '';
            const author = `${message.first_name || ''} ${String(message.last_name || '').slice(0, 1)}.`.trim();
            const timestamp = new Date(`${String(message.created_at).replace(' ', 'T')}Z`).toLocaleString('en-US', {dateStyle: 'medium', timeStyle: 'short', hour12: true});
            const status = deleted
                ? '<span class="rink-chat-message-status is-deleted">Deleted</span>'
                : (message.edited_at ? '<span class="rink-chat-message-status">Edited</span>' : '');
            const actions = mine && !deleted
                ? `<div class="rink-chat-actions">${editable ? `<button type="button" data-rink-chat-edit="${escapeHtml(message.public_id)}" aria-label="Edit message" title="Edit message"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4 13.8-.7 3 3-.7L15.4 7 13 4.6 4 13.8Z"></path><path d="m11.9 5.7 2.4 2.4"></path></svg></button>` : ''}${canCustomizeChatExpiry ? `<button type="button" data-rink-chat-expiry="${escapeHtml(message.public_id)}" aria-label="Set message deletion time" title="Set message deletion time"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="6.7"></circle><path d="M10 6.2v4.2l2.9 1.8"></path></svg></button>` : ''}<button type="button" data-rink-chat-delete="${escapeHtml(message.public_id)}" aria-label="Delete message" title="Delete message"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 6.5h9l-.6 10h-7.8l-.6-10Z"></path><path d="M4 6.5h12M7.5 6.5V4h5v2.5M8.5 9v4.5M11.5 9v4.5"></path></svg></button></div>`
                : '';
            return `<div class="rink-chat-message-row ${mine ? 'is-mine' : ''}">${actions}<article class="rink-chat-message ${deleted ? 'is-deleted' : ''}"${bubbleColour ? ` data-rink-chat-bubble-colour="${bubbleColour}"` : ''}${mine && !deleted ? ' tabindex="0"' : ''}><header><strong>${escapeHtml(author)}</strong><time>– ${escapeHtml(timestamp)}</time>${status}</header>${deleted ? '' : `<p>${escapeHtml(message.message_text || '')}</p>`}</article></div>`;
        }).join('') || '<p class="rink-chat-empty">No messages yet. Start the session conversation.</p>';
        chatHistory.querySelectorAll('[data-rink-chat-bubble-colour]').forEach((bubble) => {
            bubble.style.setProperty('--rink-chat-bubble-colour', bubble.dataset.rinkChatBubbleColour || '#e9e9eb');
        });
        if (stickToBottom || wasNearBottom) chatHistory.scrollTop = chatHistory.scrollHeight;
    };
    const updateChatUnread = (count) => {
        if (!chatUnreadBadge) return;
        chatUnreadBadge.textContent = String(count);
        chatUnreadBadge.hidden = !count;
    };
    const refreshChat = async () => {
        if (!isChatView || document.hidden) return;
        try { chatData = await request(chatEndpoint()); renderChat(); updateChatUnread(0); } catch { /* retain the current history */ }
    };
    const refreshChatUnread = async () => {
        if (isChatView || document.hidden || !sessionId) return;
        try {
            const statusUrl = withQuery(
                body.dataset.rinkChatStatusUrl || '',
                {season_id: String(seasonId), session_id: String(sessionId)}
            );
            const status = await request(statusUrl);
            updateChatUnread(Number(status.unread_count || 0));
        } catch { /* a temporary polling failure should not disturb the app */ }
    };
    if (isChatView) {
        if (chatData) renderChat(true);
        const resizeChatInput = () => {
            if (!chatInput) return;
            chatInput.style.height = '34px';
            chatInput.style.height = `${Math.min(chatInput.scrollHeight, 112)}px`;
            chatInput.style.overflowY = chatInput.scrollHeight > 112 ? 'auto' : 'hidden';
        };
        resizeChatInput();
        chatInput?.addEventListener('input', resizeChatInput);
        chatForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const message = String(chatInput?.value || '').trim();
            if (!message || !rinkConnected) return;
            const submit = chatForm.querySelector('button[type="submit"]');
            if (submit) submit.disabled = true;
            try {
                const endpoint = editingChatPublicId
                    ? `${body.dataset.rinkChatUrl}/${encodeURIComponent(editingChatPublicId)}/edit`
                    : body.dataset.rinkChatUrl;
                await request(endpoint, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: contextBody({message})});
                editingChatPublicId = null;
                if (submit) submit.textContent = 'Post';
                if (chatInput) { chatInput.value = ''; resizeChatInput(); }
                await refreshChat();
            } catch (error) { showToast(error.message, true); } finally { if (submit) submit.disabled = false; }
        });
        chatHistory?.addEventListener('click', async (event) => {
            const edit = event.target.closest('[data-rink-chat-edit]');
            const expiry = event.target.closest('[data-rink-chat-expiry]');
            const remove = event.target.closest('[data-rink-chat-delete]');
            if (edit) {
                const message = (chatData?.messages || []).find((item) => item.public_id === edit.dataset.rinkChatEdit);
                if (chatInput && message) {
                    editingChatPublicId = message.public_id;
                    chatInput.value = message.message_text || '';
                    resizeChatInput();
                    chatInput.focus();
                    const submit = chatForm?.querySelector('button[type="submit"]');
                    if (submit) submit.textContent = 'Save';
                }
                return;
            }
            if (expiry) {
                const message = (chatData?.messages || []).find((item) => item.public_id === expiry.dataset.rinkChatExpiry);
                const postedAt = new Date(`${String(message?.created_at || '').replace(' ', 'T')}Z`);
                if (!message || Number.isNaN(postedAt.getTime()) || !chatExpiryDialog || !chatExpiryInput) return;
                chatExpiryDialog.dataset.messageId = message.public_id;
                chatExpiryInput.min = toDateTimeLocal(new Date());
                chatExpiryInput.value = toDateTimeLocal(new Date(postedAt.getTime() + (24 * 60 * 60 * 1000)));
                chatExpiryDialog.showModal();
                chatExpiryInput.focus();
                return;
            }
            if (!remove) {
                const message = event.target.closest('.rink-chat-message[tabindex]');
                if (message) {
                    chatHistory.querySelectorAll('.rink-chat-message-row.is-actions-visible').forEach((row) => row.classList.remove('is-actions-visible'));
                    message.closest('.rink-chat-message-row')?.classList.add('is-actions-visible');
                }
                return;
            }
            const confirmed = await window.CAT.confirm({
                eyebrow: 'Delete message',
                title: 'Delete this message?',
                message: 'This message will be removed from the session chat for everyone.',
                confirmLabel: 'Delete message',
                tone: 'danger',
            });
            if (!confirmed) return;
            try {
                await request(`${body.dataset.rinkChatUrl}/${encodeURIComponent(remove.dataset.rinkChatDelete)}/delete`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: contextBody()});
                if (editingChatPublicId === remove.dataset.rinkChatDelete) {
                    editingChatPublicId = null;
                    if (chatInput) { chatInput.value = ''; resizeChatInput(); }
                    const submit = chatForm?.querySelector('button[type="submit"]');
                    if (submit) submit.textContent = 'Post';
                }
                await refreshChat();
            } catch (error) { showToast(error.message, true); }
        });
        chatExpiryCancel?.addEventListener('click', () => chatExpiryDialog?.close());
        chatExpiryForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const publicId = chatExpiryDialog?.dataset.messageId || '';
            const expiryDate = new Date(String(chatExpiryInput?.value || ''));
            if (!publicId || Number.isNaN(expiryDate.getTime())) {
                showToast('Choose a valid deletion date and time.', true);
                return;
            }
            const submit = chatExpiryForm.querySelector('button[type="submit"]');
            if (submit) submit.disabled = true;
            try {
                await request(`${body.dataset.rinkChatUrl}/${encodeURIComponent(publicId)}/expiry`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: contextBody({expires_at: expiryDate.toISOString()})});
                chatExpiryDialog?.close();
                showToast('Message deletion time updated.');
                await refreshChat();
            } catch (error) { showToast(error.message, true); } finally { if (submit) submit.disabled = false; }
        });
        chatHistory?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const message = event.target.closest('.rink-chat-message[tabindex]');
            if (!message) return;
            event.preventDefault();
            chatHistory.querySelectorAll('.rink-chat-message-row.is-actions-visible').forEach((row) => row.classList.remove('is-actions-visible'));
            message.closest('.rink-chat-message-row')?.classList.add('is-actions-visible');
        });
    }

    renderRoster();
    restoreViewScroll();
    setConnectionStatus(navigator.onLine);
    checkConnection();
    if (connectionStatus) window.setInterval(checkConnection, 3000);
    if (rosterBody) window.setInterval(refreshRoster, 2000);
    if (sessionId) window.setInterval(isChatView ? refreshChat : refreshChatUnread, 2000);
})();
