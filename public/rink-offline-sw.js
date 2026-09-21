/* This worker caches only explicitly downloaded Coach App pages and assets. */
'use strict';
const PREFIX = 'cat-rink-v2-';
const DB = 'cat-rink-offline-v1';
const openDB = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(DB, 1);
    request.onupgradeneeded = () => request.result.createObjectStore('state');
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});
const read = async key => {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('state'); const req = tx.objectStore('state').get(key);
        tx.oncomplete = () => { db.close(); resolve(req.result); }; tx.onerror = () => { db.close(); reject(tx.error); };
    });
};
const write = async (key, value) => {
    const db = await openDB();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('state', 'readwrite'); tx.objectStore('state').put(value, key);
        tx.oncomplete = () => { db.close(); resolve(); }; tx.onerror = () => { db.close(); reject(tx.error); };
    });
};
const route = url => url.searchParams.get('route') || url.pathname.slice(new URL(self.registration.scope).pathname.length).replace(/^\/+/, '');
const pageKey = input => {
    const url = new URL(input);
    const key = new URL('index.php', self.registration.scope);
    key.search = new URLSearchParams({route: 'rink-app', season_id: url.searchParams.get('season_id') || '', session_id: url.searchParams.get('session_id') || '', activity: url.searchParams.get('activity') || 'roster'}).toString();
    return key.href;
};
const isHtmlPage = response => /^text\/html(?:\s*;|$)/i.test(response.headers.get('Content-Type') || '');
const isPreparedPage = (response, owner) => response.ok && !response.redirected && isHtmlPage(response) && response.headers.get('X-CAT-Rink-Owner') === owner;
const network = async request => {
    const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 8000);
    try { return await fetch(request, {signal: controller.signal}); } finally { clearTimeout(timer); }
};
self.addEventListener('install', event => event.waitUntil(self.skipWaiting()));
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));
self.addEventListener('message', event => {
    if (event.data?.type !== 'PREPARE') return;
    event.waitUntil((async () => {
        try {
            const {owner, pages, assets} = event.data;
            const cacheName = PREFIX + owner + '-' + Date.now();
            const cache = await caches.open(cacheName);
            try {
                const previous = await read('active-cache');
                if (previous?.owner === owner) {
                    const old = await caches.open(previous.name);
                    for (const request of await old.keys()) await cache.put(request, await old.match(request));
                }
                for (const value of [...pages, ...assets]) {
                    const url = new URL(value, self.registration.scope);
                    if (url.origin !== self.location.origin) throw new Error('Invalid offline asset.');
                    const isPage = pages.includes(value);
                    if (isPage ? route(url) !== 'rink-app' : !url.pathname.startsWith(new URL('assets/', self.registration.scope).pathname)) throw new Error('Invalid offline URL.');
                    const response = await network(new Request(url, {cache: 'no-store', credentials: 'same-origin'}));
                    if (!response.ok || response.redirected || (isPage && !isPreparedPage(response, owner))) throw new Error('The session download could not finish. Sign in and try again.');
                    await cache.put(isPage ? pageKey(url) : url, response);
                }
                await write('active-cache', {name: cacheName, owner, pages: [...new Set([...pages.map(pageKey), ...(previous?.owner === owner ? previous.pages || [] : [])])]});
                if (previous?.name && previous.name !== cacheName) await caches.delete(previous.name);
                event.ports[0].postMessage({ok: true});
            } catch (error) { await caches.delete(cacheName); throw error; }
        } catch (error) { event.ports[0].postMessage({error: error.message}); }
    })());
});
const withOwnerLocks = async (owners, action, index = 0) => {
    if (index === owners.length) return action();
    return navigator.locks.request(`cat-rink:${self.location.origin}:${owners[index]}`, () => withOwnerLocks(owners, action, index + 1));
};
const unavailable = message => new Response('<!doctype html><meta name="viewport" content="width=device-width"><title>CAT — Connection required</title><h1>Connection required</h1><p>' + message + '</p><p>Return to your downloaded Coach App session to continue.</p>', {status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}});
const preparedPage = async (url, active) => {
    active ||= await read('active-cache');
    if (!active) return null;
    const cache = await caches.open(active.name);
    let key = pageKey(url);
    if (!url.searchParams.get('season_id') && !url.searchParams.get('session_id')) key = active.pages?.[0] || key;
    const response = await cache.match(key);
    return response && isHtmlPage(response) ? response : null;
};
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;
    const target = route(url);
    if (event.request.method === 'POST' && ['logout', 'login'].includes(target)) {
        event.respondWith((async () => {
            const keys = (await read('session-keys')) || [];
            const owners = [...new Set(keys.map(key => key.split(':').slice(1, 4).join(':')))].sort();
            return withOwnerLocks(owners, async () => {
            if (target === 'logout') {
                const keys = (await read('session-keys')) || [];
                for (const key of keys) {
                    const state = await read(key);
                    if (state?.queue?.length) return unavailable('There are changes saved on this device that still need to sync. Reconnect and resolve them before signing out.');
                }
            }
            const response = await network(event.request);
            // Never leave a previous account's HTML available after an authentication transition.
            for (const name of await caches.keys()) if (name.startsWith(PREFIX)) await caches.delete(name);
            await write('active-cache', null);
            if (target === 'logout' && response.ok) {
                const db = await openDB();
                await new Promise((resolve, reject) => { const tx = db.transaction('state', 'readwrite'); tx.objectStore('state').clear(); tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
                db.close();
            }
            return response;
            });
        })());
        return;
    }
    if (event.request.method !== 'GET') return;
    const isPage = event.request.mode === 'navigate' && target === 'rink-app';
    const isAsset = url.pathname.startsWith(new URL('assets/', self.registration.scope).pathname);
    if (!isPage && !isAsset) return;
    event.respondWith((async () => {
        // Page navigation must not depend on a network request once the browser has
        // reported that it is offline. Network-first here left a race during a Wi-Fi
        // drop, where Chrome could surface a cached logo response instead of Chat.
        if (isPage && !navigator.onLine) {
            return (await preparedPage(url)) || unavailable('This session has not been fully downloaded. Connect to the Internet and select it first.');
        }
        try {
            const response = await network(event.request);
            if (!isPage && response.status < 500) return response;
            if (isPage && response.ok && !response.redirected && isHtmlPage(response)) {
                const active = await read('active-cache');
                if (!active || response.headers.get('X-CAT-Rink-Owner') === active.owner) return response;
            }
        } catch { /* A prepared session remains usable during transport failures. */ }
        if (isPage) return (await preparedPage(url)) || unavailable('This session has not been fully downloaded. Connect to the Internet and select it first.');
        const active = await read('active-cache');
        if (active) {
            const response = await (await caches.open(active.name)).match(event.request);
            if (response) return response;
        }
        return unavailable('This session has not been fully downloaded. Connect to the Internet and select it first.');
    })());
});
