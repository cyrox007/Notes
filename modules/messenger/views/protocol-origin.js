{literal}
(() => {
    'use strict';

    const resolveSocketUrl = (rawUrl, locationLike = window.location) => {
        const value = String(rawUrl || '').trim();
        if (!value || !value.startsWith('/')) {
            return value;
        }

        const pageProtocol = String(locationLike?.protocol || '').toLowerCase();
        const socketProtocol = pageProtocol === 'https:'
            ? 'wss:'
            : (pageProtocol === 'http:' ? 'ws:' : '');
        const host = String(locationLike?.host || '').trim();
        if (!socketProtocol || !host) {
            return value;
        }

        return `${socketProtocol}//${host}${value}`;
    };

    // Kept as a tiny public helper so the protocol contract can exercise the
    // exact browser transformation without booting the rest of Messenger.
    window.wspaceResolveSocketUrl = resolveSocketUrl;

    document.addEventListener('DOMContentLoaded', () => {
        const config = window.wspace?.socketConfig;
        if (!config) return;
        config.url = resolveSocketUrl(config.url);
    });
})();
{/literal}
