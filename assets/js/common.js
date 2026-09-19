(function bootstrapWorkspaceRuntime(global) {
    'use strict';

    const wspace = global.wspace = global.wspace || {};
    const runtimeConfig = global.wspaceRuntime && typeof global.wspaceRuntime === 'object'
        ? global.wspaceRuntime
        : {};
    const socketTicket = typeof runtimeConfig.socketTicket === 'string' ? runtimeConfig.socketTicket : '';
    const socketUrl = typeof runtimeConfig.socketUrl === 'string' ? runtimeConfig.socketUrl : '';
    const appBasePath = typeof runtimeConfig.basePath === 'string' ? runtimeConfig.basePath : '';

    wspace.socketConfig = {
        ticket: socketTicket,
        url: socketUrl
    };

    wspace.basePath = appBasePath;
    wspace.path = function appPath(value = '/') {
        const raw = String(value || '/');
        if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || raw.startsWith('//') || raw.startsWith('#')) {
            return raw;
        }

        const normalized = '/' + raw.replace(/^\/+/, '');
        if (!appBasePath) {
            return normalized;
        }
        if (normalized === appBasePath || normalized.startsWith(appBasePath + '/')) {
            return normalized;
        }
        return appBasePath + normalized;
    };

    (function bootstrapSecurity() {
        const unsafeMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);
        const template = document.getElementById('csrf-token-template');
        const tokenInput = template && template.content
            ? template.content.querySelector('input[name="csrf_token"]')
            : null;
        const csrfToken = tokenInput ? tokenInput.value : '';

        function isSameOrigin(url) {
            try {
                return new URL(url, window.location.href).origin === window.location.origin;
            } catch (e) {
                return false;
            }
        }

        function prefixAppPath(url) {
            return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//')
                ? wspace.path(url)
                : url;
        }

        wspace.security = {
            csrfToken,
            getCSRFToken() {
                return csrfToken;
            }
        };

        if (typeof window.fetch === 'function') {
            const nativeFetch = window.fetch.bind(window);
            window.fetch = function securedFetch(input, init = {}) {
                let securedInput = input;
                if (typeof input === 'string') {
                    securedInput = prefixAppPath(input);
                }

                const requestMethod = String(
                    init.method || (typeof Request !== 'undefined' && input instanceof Request ? input.method : 'GET')
                ).toUpperCase();
                const requestUrl = typeof Request !== 'undefined' && input instanceof Request ? input.url : String(securedInput);

                if (csrfToken && unsafeMethods.has(requestMethod) && isSameOrigin(requestUrl)) {
                    const headers = new Headers(
                        init.headers || (typeof Request !== 'undefined' && input instanceof Request ? input.headers : undefined)
                    );
                    if (!headers.has('X-CSRF-Token')) {
                        headers.set('X-CSRF-Token', csrfToken);
                    }
                    init = Object.assign({}, init, { headers });
                }

                return nativeFetch(securedInput, init);
            };
        }

        if (typeof XMLHttpRequest !== 'undefined') {
            const nativeOpen = XMLHttpRequest.prototype.open;
            const nativeSend = XMLHttpRequest.prototype.send;

            XMLHttpRequest.prototype.open = function securedOpen(method, url, ...args) {
                const securedUrl = prefixAppPath(url);
                this.__wspaceMethod = String(method || 'GET').toUpperCase();
                this.__wspaceUrl = String(securedUrl || '');
                return nativeOpen.call(this, method, securedUrl, ...args);
            };

            XMLHttpRequest.prototype.send = function securedSend(body) {
                if (
                    csrfToken
                    && unsafeMethods.has(this.__wspaceMethod || 'GET')
                    && isSameOrigin(this.__wspaceUrl || window.location.href)
                ) {
                    this.setRequestHeader('X-CSRF-Token', csrfToken);
                }
                return nativeSend.call(this, body);
            };
        }

        document.addEventListener('submit', function addCsrfToForm(event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const method = String(form.method || 'GET').toUpperCase();
            if (!csrfToken || !unsafeMethods.has(method) || !isSameOrigin(form.action || window.location.href)) {
                return;
            }

            if (!form.querySelector('input[name="csrf_token"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = csrfToken;
                form.appendChild(input);
            }
        }, true);
    })();

    (function bootstrapWorkspaceShell() {
        const MOBILE_BREAKPOINT = 768;
        const STORAGE_KEY = 'workspace.sidebar.collapsed';

        function normalizePath(path) {
            const normalized = String(path || '/').replace(/\/+$/, '');
            return normalized === '' ? '/' : normalized;
        }

        function setupActiveNavigation() {
            const currentPath = normalizePath(window.location.pathname);
            const links = document.querySelectorAll('.sidebar__menu-link[href]');
            let best = null;
            let bestLength = -1;

            links.forEach((link) => {
                let path;
                try {
                    path = normalizePath(new URL(link.href, window.location.href).pathname);
                } catch (e) {
                    return;
                }

                const matches = currentPath === path || (path !== '/' && currentPath.startsWith(path + '/'));
                if (matches && path.length > bestLength) {
                    best = link;
                    bestLength = path.length;
                }
            });

            if (best) {
                best.setAttribute('aria-current', 'page');
            }
        }

        function setupSidebar() {
            const controls = [...document.querySelectorAll('[data-sidebar-toggle]')];
            const sidebar = document.getElementById('workspaceSidebar') || document.querySelector('.sidebar');
            if (!controls.length || !sidebar) {
                return;
            }

            let backdrop = document.querySelector('.sidebar-backdrop');
            if (!backdrop) {
                backdrop = document.createElement('button');
                backdrop.type = 'button';
                backdrop.className = 'sidebar-backdrop';
                backdrop.setAttribute('aria-label', 'Закрыть меню');
                document.body.appendChild(backdrop);
            }

            const isMobile = () => window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;

            function setExpanded(expanded) {
                controls.forEach((control) => {
                    control.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                    const icon = control.querySelector('.fa-angle-double-left, .fa-angle-double-right');
                    if (icon && !isMobile()) {
                        icon.classList.toggle('fa-angle-double-left', expanded);
                        icon.classList.toggle('fa-angle-double-right', !expanded);
                    }
                });
            }

            function closeMobile() {
                sidebar.classList.remove('sidebar--open');
                backdrop.classList.remove('is-visible');
                document.body.classList.remove('sidebar-mobile-open');
                setExpanded(false);
            }

            function openMobile() {
                sidebar.classList.remove('sidebar--collapsed');
                sidebar.classList.add('sidebar--open');
                backdrop.classList.add('is-visible');
                document.body.classList.add('sidebar-mobile-open');
                setExpanded(true);
            }

            function readCollapsed() {
                try {
                    return window.localStorage.getItem(STORAGE_KEY) === '1';
                } catch (e) {
                    return false;
                }
            }

            function applyDesktopState() {
                sidebar.classList.remove('sidebar--open');
                backdrop.classList.remove('is-visible');
                document.body.classList.remove('sidebar-mobile-open');
                const collapsed = readCollapsed();
                sidebar.classList.toggle('sidebar--collapsed', collapsed);
                setExpanded(!collapsed);
            }

            controls.forEach((control) => {
                control.addEventListener('click', (event) => {
                    event.preventDefault();

                    if (isMobile()) {
                        if (sidebar.classList.contains('sidebar--open')) {
                            closeMobile();
                        } else {
                            openMobile();
                        }
                        return;
                    }

                    const collapsed = sidebar.classList.toggle('sidebar--collapsed');
                    setExpanded(!collapsed);
                    try {
                        window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
                    } catch (e) {
                        // Storage may be unavailable in privacy-restricted contexts.
                    }
                });
            });

            backdrop.addEventListener('click', closeMobile);

            sidebar.addEventListener('click', (event) => {
                if (isMobile() && event.target.closest('a')) {
                    closeMobile();
                }
            });

            const media = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`);
            const syncLayout = () => {
                if (media.matches) {
                    sidebar.classList.remove('sidebar--collapsed');
                    closeMobile();
                } else {
                    applyDesktopState();
                }
            };

            if (typeof media.addEventListener === 'function') {
                media.addEventListener('change', syncLayout);
            } else if (typeof media.addListener === 'function') {
                media.addListener(syncLayout);
            }

            if (isMobile()) {
                closeMobile();
            } else {
                applyDesktopState();
            }

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && sidebar.classList.contains('sidebar--open')) {
                    closeMobile();
                    controls[0]?.focus();
                }
            });
        }

        function setupCommandPalette() {
            const palette = document.querySelector('[data-command-palette]');
            const openers = [...document.querySelectorAll('[data-command-open]')];
            if (!palette || !openers.length) {
                return;
            }

            const input = palette.querySelector('[data-command-input]');
            const items = [...palette.querySelectorAll('[data-command-item]')];
            const empty = palette.querySelector('[data-command-empty]');
            const closers = [...palette.querySelectorAll('[data-command-close]')];
            let restoreFocus = null;

            function filterItems() {
                const query = String(input?.value || '').trim().toLocaleLowerCase('ru');
                let visible = 0;
                items.forEach((item) => {
                    const haystack = String(item.dataset.commandText || item.textContent || '').toLocaleLowerCase('ru');
                    const matches = query === '' || haystack.includes(query);
                    item.hidden = !matches;
                    if (matches) visible += 1;
                });
                if (empty) empty.hidden = visible !== 0;
            }

            function openPalette(source) {
                restoreFocus = source instanceof HTMLElement ? source : document.activeElement;
                palette.hidden = false;
                document.body.classList.add('command-palette-open');
                if (input) {
                    input.value = '';
                    filterItems();
                    requestAnimationFrame(() => input.focus());
                }
            }

            function closePalette() {
                if (palette.hidden) return;
                palette.hidden = true;
                document.body.classList.remove('command-palette-open');
                if (restoreFocus && typeof restoreFocus.focus === 'function') {
                    restoreFocus.focus();
                }
            }

            openers.forEach((opener) => opener.addEventListener('click', () => openPalette(opener)));
            closers.forEach((closer) => closer.addEventListener('click', closePalette));
            input?.addEventListener('input', filterItems);

            document.addEventListener('keydown', (event) => {
                const key = event.key.toLowerCase();
                if ((event.ctrlKey || event.metaKey) && key === 'k') {
                    event.preventDefault();
                    if (palette.hidden) openPalette(document.activeElement);
                    else closePalette();
                    return;
                }

                if (event.key === 'Escape' && !palette.hidden) {
                    event.preventDefault();
                    closePalette();
                    return;
                }

                if (event.key === 'ArrowDown' && !palette.hidden && document.activeElement === input) {
                    const firstVisible = items.find((item) => !item.hidden);
                    if (firstVisible) {
                        event.preventDefault();
                        firstVisible.focus();
                    }
                }
            });

            items.forEach((item, index) => {
                item.addEventListener('keydown', (event) => {
                    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
                    const visible = items.filter((candidate) => !candidate.hidden);
                    const current = visible.indexOf(item);
                    if (current < 0) return;
                    event.preventDefault();
                    const next = event.key === 'ArrowDown'
                        ? visible[(current + 1) % visible.length]
                        : visible[(current - 1 + visible.length) % visible.length];
                    next?.focus();
                });
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            setupActiveNavigation();
            setupSidebar();
            setupCommandPalette();
        });
    })();
})(window);
