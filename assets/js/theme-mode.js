(function bootstrapWorkspaceTheme(global) {
    'use strict';

    const STORAGE_KEY = 'workspace.theme';
    const ALLOWED = new Set(['light', 'system', 'dark']);
    const media = global.matchMedia('(prefers-color-scheme: dark)');

    function readPreference() {
        try {
            const value = global.localStorage.getItem(STORAGE_KEY);
            return ALLOWED.has(value) ? value : 'light';
        } catch (error) {
            return 'light';
        }
    }

    function resolveTheme(preference) {
        if (preference === 'system') {
            return media.matches ? 'dark' : 'light';
        }
        return preference === 'dark' ? 'dark' : 'light';
    }

    function syncThemeColor(theme) {
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', theme === 'dark' ? '#101525' : '#f4f6fb');
        }
    }

    function syncControls(preference) {
        document.querySelectorAll('[data-theme-option]').forEach((button) => {
            const active = button.dataset.themeOption === preference;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function apply(preference, persist) {
        const normalized = ALLOWED.has(preference) ? preference : 'light';
        const resolved = resolveTheme(normalized);
        document.documentElement.dataset.themePreference = normalized;
        document.documentElement.dataset.theme = resolved;
        syncThemeColor(resolved);
        syncControls(normalized);

        if (persist) {
            try {
                global.localStorage.setItem(STORAGE_KEY, normalized);
            } catch (error) {
                // Theme persistence is optional in restricted browser contexts.
            }
        }
    }

    function setupPicker() {
        document.querySelectorAll('[data-theme-option]').forEach((button) => {
            button.addEventListener('click', () => apply(button.dataset.themeOption || 'light', true));
        });
        syncControls(readPreference());
    }

    if (typeof media.addEventListener === 'function') {
        media.addEventListener('change', () => {
            const preference = readPreference();
            if (preference === 'system') {
                apply(preference, false);
            }
        });
    } else if (typeof media.addListener === 'function') {
        media.addListener(() => {
            const preference = readPreference();
            if (preference === 'system') {
                apply(preference, false);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', setupPicker);
    apply(readPreference(), false);

    global.wspace = global.wspace || {};
    global.wspace.theme = { apply, preference: readPreference };
})(window);
