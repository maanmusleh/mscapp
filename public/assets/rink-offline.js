(() => {
    'use strict';
    const body = document.body;
    const M = window.CATRinkOfflineModel;
    const owner = `${body.dataset.currentClubId}:${body.dataset.currentUserId}:${body.dataset.rinkRole}`;
    const seasonId = Number(body.dataset.seasonId), sessionId = Number(body.dataset.sessionId);
    const key = `session:${owner}:${seasonId}:${sessionId}`;
    const lockName = `cat-rink:${location.origin}:${owner}`;
    const dataUrl = new URL(body.dataset.rinkOfflineDataUrl, location.href);
    dataUrl.searchParams.set('season_id', seasonId); dataUrl.searchParams.set('session_id', sessionId);
    let db, state, view, online = navigator.onLine, authorized = true, preparing = false, ticking = false, readyForOffline = false;
    const requests = new Set();
    let otherPending = [];
    let failure = '', lastRefresh = 0, csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const label = document.querySelector('[data-rink-offline-status]');
    const transact = (mode, action) => new Promise((resolve, reject) => {
        const tx = db.transaction('state', mode); const request = action(tx.objectStore('state'));
        tx.oncomplete = () => resolve(request?.result); tx.onerror = () => reject(tx.error); tx.onabort = () => reject(tx.error || new Error('Storage write was interrupted.'));
    });
    const read = k => transact('readonly', store => store.get(k));
    const save = () => transact('readwrite', store => store.put(state, key));
    const locked = fn => navigator.locks.request(lockName, fn);
    const reload = async () => { state = await read(key); view = state?.snapshot ? M.project(state.snapshot, state.queue) : null; };
    const updateUI = () => {
        body.classList.toggle('rink-is-offline', !online || !authorized);
        document.querySelectorAll('[data-rink-season], [data-rink-session]').forEach(select => { select.disabled = !online || !!state?.queue.length; });
        const pending = state?.queue.filter(op => !op.acked && !op.resolved).length || 0;
        const issues = state?.queue.filter(op => op.issue).length || 0;
        if (label) {
            label.textContent = failure ? 'Sync unavailable'
                : preparing ? 'Setting up…'
                : !authorized ? 'Sign in to sync'
                : !readyForOffline ? 'Setup incomplete'
                : issues ? `${pending} unsaved`
                : otherPending.length ? 'Other session unsaved'
                : pending ? `${pending} unsaved`
                : 'All saved';
        }
        document.querySelectorAll('[data-rink-chat-post], [data-rink-chat-edit], [data-rink-chat-delete], [data-rink-chat-expiry]').forEach(button => { button.disabled = !online || !authorized || body.dataset.rinkCanEdit !== 'true'; });
        if (!online || !authorized) document.querySelectorAll('#rink-report-card-note-dialog[open], #rink-note-library-dialog[open]').forEach(item => item.close());
        window.dispatchEvent(new CustomEvent('rink-offline-status', {detail: {connected: online && authorized}}));
    };
    const changed = () => { view = state?.snapshot ? M.project(state.snapshot, state.queue) : null; updateUI(); window.dispatchEvent(new Event('rink-offline-data')); };
    const raw = async (url, options = {}) => {
        if (!navigator.onLine) { online = false; updateUI(); throw new Error('The device is offline. Your saved changes remain on this device.'); }
        const controller = new AbortController(); requests.add(controller); const timer = setTimeout(() => controller.abort(), 8000);
        let response;
        try { response = await fetch(url, {...options, headers: {Accept: 'application/json', ...(options.headers || {}), ...(!['GET', 'HEAD'].includes((options.method || 'GET').toUpperCase()) ? {'X-CSRF-Token': csrf} : {})}, cache: 'no-store', signal: controller.signal}); }
        catch (cause) { online = false; updateUI(); throw new Error('CAT cannot reach the server. Downloaded information and saved changes remain on this device.'); }
        finally { clearTimeout(timer); requests.delete(controller); }
        if (response.redirected || [401, 403, 419].includes(response.status)) {
            authorized = false; updateUI(); const error = new Error('Sign in with the original account before syncing.'); error.status = response.status || 401; throw error;
        }
        if (response.status >= 500) {
            online = false; updateUI();
            const result = await response.json().catch(() => null);
            throw new Error(result?.error || 'The server is temporarily unavailable. Retry while connected. Any previously saved changes remain on this device.');
        }
        let result;
        try { result = await response.json(); } catch { throw new Error('The server returned an unexpected response.'); }
        online = true;
        if (!response.ok) { const error = new Error(result.error || result.message || 'The change was rejected.'); error.status = response.status; error.result = result; throw error; }
        return result;
    };
    const refresh = async () => {
        const snapshot = await raw(dataUrl);
        if (snapshot.user_id !== Number(body.dataset.currentUserId) || snapshot.club_id !== Number(body.dataset.currentClubId)) {
            authorized = false; throw new Error('Sign in with the account that downloaded this session.');
        }
        csrf = snapshot.csrf_token; authorized = true;
        state ||= {queue: [], snapshot: null};
        state.snapshot = snapshot;
        state.queue = state.queue.filter(op => !op.acked && !op.resolved);
        await save(); lastRefresh = Date.now(); changed();
    };
    const prepare = async () => {
        preparing = true; updateUI();
        try {
            const workerUrl = new URL(body.dataset.rinkWorkerUrl, location.href).href;
            const registration = await navigator.serviceWorker.register(workerUrl, {scope: new URL('./', workerUrl).pathname});
            await registration.update();
            await navigator.serviceWorker.ready;
            if (navigator.serviceWorker.controller?.scriptURL !== workerUrl) await new Promise((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('Reload once while online to finish offline setup.')), 10000);
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    if (navigator.serviceWorker.controller?.scriptURL === workerUrl) { clearTimeout(timer); resolve(); }
                });
            });
            const pages = ['roster', 'skills', 'chat'].map(activity => {
                const url = new URL(body.dataset.rinkPageUrl, location.href);
                url.searchParams.set('season_id', seasonId); url.searchParams.set('session_id', sessionId); url.searchParams.set('activity', activity); url.searchParams.delete('group_id');
                return url.href;
            });
            const assets = new Set(Array.from(document.querySelectorAll('script[src], link[rel="stylesheet"][href], img[src]'), el => el.src || el.href));
            for (const [name, value] of Object.entries(body.dataset)) if (['rinkMedicalIcon', 'skateCanadaEmblem', 'skateCanadaEmblemOutline', 'skateCanadaBadgeShape', 'skateCanadaBadgeOutline'].includes(name)) assets.add(new URL(value, location.href).href);
            await new Promise((resolve, reject) => {
                const channel = new MessageChannel();
                const timer = setTimeout(() => reject(new Error('Offline page download timed out. Retry with a stable connection.')), 120000);
                channel.port1.onmessage = event => { clearTimeout(timer); channel.port1.close(); event.data.ok ? resolve() : reject(new Error(event.data.error)); };
                navigator.serviceWorker.controller.postMessage({type: 'PREPARE', owner, pages, assets: [...assets]}, [channel.port2]);
            });
            state.prepared = true; await save(); readyForOffline = true;
            if (navigator.storage?.persist) await navigator.storage.persist().catch(() => false);
        } finally { preparing = false; updateUI(); }
    };
    const resolveGroupConflict = (op, error) => {
        if (error.status !== 409 || op.kind !== 'group') return false;
        // Group membership is a single shared value. Keeping a rejected local overlay
        // made different coaches see different colours after reconnecting.
        op.resolved = true;
        window.dispatchEvent(new CustomEvent('rink-offline-conflict', {
            detail: {message: 'Another coach changed this skater’s group while you were offline. CAT is showing the saved group.'},
        }));
        return true;
    };
    const sync = async () => {
        for (const op of state.queue) {
            if (op.acked || op.resolved || op.issue) continue;
            // Do not send an award while an earlier change for this skater needs review.
            if (state.queue.some(item => item.skater_id === op.skater_id && item.issue)) continue;
            op.attempted = true; await save();
            try {
                await raw(body.dataset.rinkOfflineSyncUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(op)});
                op.acked = true; await save();
            } catch (error) {
                if (error.status === 409) {
                    if (!resolveGroupConflict(op, error)) op.issue = {message: error.message, current: error.result.current};
                } else if (error.status && error.status < 500 && ![401, 403, 419].includes(error.status)) op.issue = {message: error.message};
                else throw error;
                await save();
            }
        }
        // Keep acknowledged overlays until a fresh snapshot is durably stored with their removal.
        if (state.queue.some(op => op.acked || op.resolved)) await refresh();
        changed();
    };
    const syncOtherSessions = async () => {
        otherPending = [];
        for (const storageKey of await read('session-keys') || []) {
            if (storageKey === key || !storageKey.startsWith(`session:${owner}:`)) continue;
            const saved = await read(storageKey);
            if (!saved?.queue.length) continue;
            for (const op of saved.queue) {
                if (op.acked || op.resolved || op.issue || saved.queue.some(item => item.skater_id === op.skater_id && item.issue)) continue;
                op.attempted = true;
                await transact('readwrite', store => store.put(saved, storageKey));
                try {
                    await raw(body.dataset.rinkOfflineSyncUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(op)});
                    op.acked = true;
                } catch (error) {
                    if (error.status === 409) {
                        if (!resolveGroupConflict(op, error)) op.issue = {message: error.message, current: error.result.current};
                    } else if (error.status && error.status < 500 && ![401, 403, 419].includes(error.status)) op.issue = {message: error.message};
                    else throw error;
                }
                await transact('readwrite', store => store.put(saved, storageKey));
            }
            if (saved.queue.some(op => op.acked || op.resolved)) {
                const url = new URL(dataUrl); url.searchParams.set('season_id', saved.snapshot.season_id); url.searchParams.set('session_id', saved.snapshot.session_id);
                const snapshot = await raw(url);
                if (snapshot.user_id !== Number(body.dataset.currentUserId) || snapshot.club_id !== Number(body.dataset.currentClubId)) throw new Error('Sign in with the original account to sync.');
                saved.snapshot = snapshot; saved.queue = saved.queue.filter(op => !op.acked && !op.resolved);
                await transact('readwrite', store => store.put(saved, storageKey));
            }
            if (saved.queue.length) otherPending.push(saved.snapshot);
        }
    };
    const tick = async (force = false) => {
        if (!navigator.onLine || ticking || preparing || !db || !seasonId || !sessionId || (document.hidden && !force)) return;
        ticking = true;
        try {
            await locked(async () => {
                await reload();
                const status = await raw(body.dataset.rinkStatusUrl);
                if (status.user_id !== Number(body.dataset.currentUserId) || status.club_id !== Number(body.dataset.currentClubId)) { authorized = false; throw new Error('Sign in with the original account to sync.'); }
                authorized = true; csrf = status.csrf_token;
                if (!state) await refresh();
                await sync();
                await syncOtherSessions();
                if (force || Date.now() - lastRefresh > 20000) await refresh();
                if (!readyForOffline) await prepare();
                failure = ''; changed();
            });
        } catch (error) { failure = error.message; updateUI(); }
        finally { ticking = false; }
    };
    const get = url => {
        if (!view) return null;
        const u = new URL(url, location.href), route = u.searchParams.get('route') || u.pathname;
        if ((u.searchParams.has('season_id') && Number(u.searchParams.get('season_id')) !== seasonId) || (u.searchParams.has('session_id') && Number(u.searchParams.get('session_id')) !== sessionId)) return null;
        if (route.endsWith('api/rink/roster') || route.endsWith('api/rink/assess')) {
            const result = M.copy(route.endsWith('/roster') ? view.roster : view.assess);
            const filter = Number(u.searchParams.get('group_id'));
            if (filter) result.skaters = result.skaters.filter(skater => Number(skater.group_id) === filter);
            return result;
        }
        const match = route.match(/api\/rink\/skaters\/([a-f\d-]{36})$/i);
        return match && view.profiles[match[1]] ? M.copy(view.profiles[match[1]]) : null;
    };
    const request = async (url, options = {}) => {
        await ready;
        const method = (options.method || 'GET').toUpperCase();
        if (db) await locked(reload);
        const cached = method === 'GET' ? get(url) : null;
        if (cached) return cached;
        const u = new URL(url, location.href), route = u.searchParams.get('route') || u.pathname;
        const achievement = route.match(/api\/rink\/skaters\/([a-f\d-]{36})\/(skills|ribbons|badges)\/(\d+)$/i);
        const kind = route.endsWith('api/rink/attendance') ? 'attendance' : route.endsWith('api/rink/group-assignment') ? 'group' : achievement?.[2];
        if (kind && ['POST', 'DELETE'].includes(method) && state?.snapshot) {
            if (body.dataset.rinkCanEdit !== 'true') throw new Error('This account has read-only access.');
            let result;
            await locked(async () => {
                await reload();
                const input = JSON.parse(options.body || '{}');
                const op = {id: crypto.randomUUID(), kind, user_id: Number(body.dataset.currentUserId), club_id: Number(body.dataset.currentClubId), season_id: seasonId, session_id: sessionId, skater_id: achievement?.[1] || input.skater_id, occurred_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')};
                const skater = view.roster.skaters.find(s => s.public_id === op.skater_id);
                if (!skater) throw new Error('This skater was not downloaded. Reconnect and reload the session.');
                if (achievement) op.target_id = Number(achievement[3]);
                else if (kind === 'attendance') {
                    op.date = M.dateAt(op.occurred_at, view.time_zone); op.present = input.present;
                    if (op.date !== view.roster.attendance.date) throw new Error('A new attendance day has started. Connect to download today’s session before taking attendance.');
                    op.expected = state.snapshot.versions[op.skater_id].attendance;
                } else {
                    op.group_id = input.group_id; op.group_name = input.group_name; op.group_colour = input.group_colour;
                    const choice = Array.from(document.querySelectorAll('[data-rink-group-choice]')).find(c => input.group_id ? Number(c.dataset.groupId) === input.group_id : c.dataset.groupName === input.group_name);
                    op.display_name = input.group_name || choice?.dataset.groupName || 'No group'; op.display_colour = input.group_colour || choice?.dataset.groupColour || '#ffffff';
                    op.expected = state.snapshot.versions[op.skater_id].group;
                    // Virtual palette groups have no server ID yet.
                    if (op.group_id < 0) { op.group_id = null; op.group_name = op.display_name; op.group_colour = op.display_colour; }
                }
                if (method === 'DELETE' && !state.queue.some(item => item.kind === kind && item.skater_id === op.skater_id && item.target_id === op.target_id)) {
                    if (!online || !authorized) throw new Error('Only newly queued achievements can be undone offline.');
                    result = await raw(url, {...options, headers: {...options.headers, 'X-CSRF-Token': csrf}}); await refresh(); return;
                }
                const next = M.copy(state); M.enqueue(next, op, method === 'DELETE'); state = next;
                try { await save(); } catch (error) { await reload(); throw new Error('The device could not save this change. Free storage and try again; this change was not recorded.'); }
                changed();
                result = {message: method === 'DELETE' ? 'Queued achievement undone.' : 'Saved on this device. Syncs automatically when connected.', result: kind === 'group' ? {updates: [{skater_id: op.skater_id, session_id: sessionId, group_id: M.groupId(op), group_name: op.display_name, group_colour: op.display_colour}]} : {present: op.present}};
            });
            if (online) setTimeout(() => tick(), 0);
            return result;
        }
        if (method !== 'GET' && (!online || !authorized)) {
            if (kind && !state?.snapshot) throw new Error('This session has not finished downloading for offline use. Reconnect and choose Retry in the offline banner.');
            throw new Error('This feature requires an Internet connection.');
        }
        const result = await raw(url, options);
        if (route.endsWith('api/rink/status')) {
            if (result.user_id !== Number(body.dataset.currentUserId) || result.club_id !== Number(body.dataset.currentClubId)) { authorized = false; updateUI(); throw new Error('Sign in with the original account to continue.'); }
        }
        if (method !== 'GET' && state) await locked(refresh);
        return result;
    };
    // Capture before the existing Coach App handlers, including controls rendered later.
    const blockUnavailable = event => {
        const target = event.target.closest('button, a, input, select, form');
        if (!target || target.dataset.rinkOfflineResume === 'true') return;
        const attrs = Array.from(target.attributes).map(a => a.name).join(' ');
        const report = /report-card|note-library|library-note/.test(attrs) || target.closest('#rink-report-card-note-dialog, #rink-note-library-dialog, [data-rink-report-cards-panel]');
        const sessionChange = target.matches('[data-rink-season], [data-rink-session]') || (target.matches('a[href]') && /rink-app/.test(target.href) && (new URL(target.href).searchParams.get('session_id') || String(sessionId)) !== String(sessionId));
        const logout = target.closest('form')?.action && new URL(target.closest('form').action).searchParams.get('route') === 'logout';
        if (((!online || !authorized) && report) || (sessionChange && (!online || state?.queue.length)) || (logout && state?.queue.length)) {
            event.preventDefault(); event.stopImmediatePropagation();
            failure = report ? 'Report-card functionality requires an Internet connection.' : 'Reconnect and sync pending changes before changing sessions or signing out.'; updateUI();
        }
    };
    for (const type of ['click', 'change', 'submit']) document.addEventListener(type, blockUnavailable, true);
    window.addEventListener('offline', () => { online = false; for (const controller of requests) controller.abort(); updateUI(); });
    window.addEventListener('online', () => tick(true));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(true); });
    window.addEventListener('pageshow', () => { if (db) tick(true); });
    const ready = (async () => {
        if (!seasonId || !sessionId) { if (label) label.hidden = true; return; }
        try {
            if (!window.isSecureContext || !navigator.serviceWorker || !window.indexedDB || !navigator.locks || !crypto.randomUUID) throw new Error('Offline use needs HTTPS and a current browser with device storage enabled.');
            db = await new Promise((resolve, reject) => { const open = indexedDB.open('cat-rink-offline-v1', 1); open.onupgradeneeded = () => open.result.createObjectStore('state'); open.onsuccess = () => resolve(open.result); open.onerror = () => reject(open.error); });
            await locked(async () => {
                await reload();
                const keys = await read('session-keys') || []; if (!keys.includes(key)) { keys.push(key); await transact('readwrite', store => store.put(keys, 'session-keys')); }
                const active = await read('active-cache'); readyForOffline = !!state?.prepared && active?.owner === owner && active.pages?.some(page => { const url = new URL(page); return Number(url.searchParams.get('season_id')) === seasonId && Number(url.searchParams.get('session_id')) === sessionId; });
                if (online) {
                    try { await refresh(); if (!readyForOffline) await prepare(); } catch (error) { failure = error.message; }
                }
                changed();
            });
            setInterval(() => tick(), 10000);
            setTimeout(() => tick(), 0);
        } catch (error) { failure = error.message; updateUI(); }
    })();
    window.CATRinkOffline = {ready, request, get, get connected() { return online && authorized; }, get available() { return !!view; }, canUndo: (skaterId, kind, id) => !!state?.queue.some(op => op.skater_id === skaterId && op.kind === kind && op.target_id === Number(id) && !op.attempted), get snapshot() { return view; }};
})();
