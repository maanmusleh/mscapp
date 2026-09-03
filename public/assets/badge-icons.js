(() => {
    'use strict';

    const body = document.body;
    const assets = {
        emblem: body.dataset.skateCanadaEmblem || '',
        emblemOutline: body.dataset.skateCanadaEmblemOutline || '',
        shape: body.dataset.skateCanadaBadgeShape || '',
        outline: body.dataset.skateCanadaBadgeOutline || '',
    };
    let instance = 0;
    const escapeAttribute = (value) => String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]));

    window.CAT = window.CAT || {};
    window.CAT.skateCanadaBadgeIcon = () => {
        instance += 1;
        const prefix = `skate-canada-badge-js-${instance}`;
        return `<svg viewBox="0 0 87 72" aria-hidden="true"><defs>`
            + `<mask id="${prefix}-fill" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="${escapeAttribute(assets.shape)}" x="0" y="0" width="87" height="72" preserveAspectRatio="none"></image></mask>`
            + `<mask id="${prefix}-outline" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="${escapeAttribute(assets.outline)}" x="0" y="0" width="87" height="72" preserveAspectRatio="none"></image></mask>`
            + `<mask id="${prefix}-mark-outline" maskUnits="userSpaceOnUse" mask-type="alpha"><image href="${escapeAttribute(assets.emblemOutline)}" x="17.5" y="10" width="52" height="52" preserveAspectRatio="xMidYMid meet"></image></mask>`
            + `</defs><rect class="badge-icon-shape badge-icon-fill" x="0" y="0" width="87" height="72" mask="url(#${prefix}-fill)"></rect>`
            + `<rect class="badge-icon-outline" x="0" y="0" width="87" height="72" mask="url(#${prefix}-outline)"></rect>`
            + `<rect class="badge-icon-mark-outline" x="17.5" y="10" width="52" height="52" mask="url(#${prefix}-mark-outline)"></rect>`
            + `<image class="badge-icon-mark" href="${escapeAttribute(assets.emblem)}" x="17.5" y="10" width="52" height="52" preserveAspectRatio="xMidYMid meet"></image></svg>`;
    };
})();
