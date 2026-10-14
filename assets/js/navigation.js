(function bootstrapNavigation() {
    'use strict';
    const wspace = window.wspace;
    const loadedScripts = new Set([...document.scripts].filter(script => script.src).map(script => script.src));
    let request = null;
    let revision = 0;
    let submitting = false;
    let renderedUrl = location.href;
    let committing = false;
    let queuedVisit = null;
    const nativePath = /\/(?:auth|logout|update-continuations)(?:\/|$)|\/admin\/updates(?:\/|$)|\/files\/(?:get|download)(?:\/|$)/;
    const inlineNonce = document.querySelector('script[nonce]')?.nonce || '';

    function eligible(url) {
        return url.origin === location.origin && ['http:', 'https:'].includes(url.protocol) && !nativePath.test(url.pathname);
    }

    function notify(message) { wspace.feedback?.toast(message, { type: 'error' }); }

    async function loadScript(source, nonce, trustedHead = false) {
        const script = document.createElement('script');
        if (source.src) {
            if (!trustedHead && (!nonce || source.nonce !== nonce)) return;
            const url = new URL(source.getAttribute('src'), location.href);
            if (url.origin !== location.origin || loadedScripts.has(url.href)) return;
            script.src = url.href;
            script.async = false;
            await new Promise((resolve, reject) => {
                script.onload = resolve;
                script.onerror = () => reject(new Error('Не удалось загрузить скрипт страницы.'));
                document.head.append(script);
            });
            loadedScripts.add(url.href);
        } else {
            // Execute only server-authorized inline code, never scripts from user content.
            if (!nonce || source.nonce !== nonce) return;
            script.nonce = inlineNonce;
            script.textContent = source.textContent;
            document.head.append(script);
            script.remove();
        }
    }

    async function render(html, response, options, ticket) {
        const next = new DOMParser().parseFromString(html, 'text/html');
        const incoming = next.querySelector('#main-content');
        const current = document.querySelector('#main-content');
        if (!incoming || !current || !next.body.hasAttribute('data-workspace-section')) {
            location.assign(response.url);
            return false;
        }
        const accessKeys = doc => [...doc.querySelectorAll('.sidebar__menu [data-nav-key]')].map(link => link.dataset.navKey).sort().join(',');
        if (accessKeys(document) !== accessKeys(next)) {
            // License/permission changes invalidate the authenticated shell too.
            location.assign(response.url);
            return false;
        }
        const policy = response.headers.get('Content-Security-Policy') || '';
        const nonce = policy.match(/'nonce-([^']+)'/)?.[1] || '';
        const styles = [...next.querySelectorAll('link[data-workspace-module-style]')];
        const newStyles = [];
        const before = document.querySelector('link[href*="/assets/css/workspace.css"]');
        try { for (const source of styles) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            const href = new URL(source.getAttribute('href'), response.url);
            if (href.origin !== location.origin) throw new Error('Недопустимый адрес стилей страницы.');
            link.href = href.href;
            link.dataset.workspaceNextStyle = '1';
            newStyles.push(link);
            await new Promise((resolve, reject) => {
                link.onload = resolve;
                link.onerror = () => reject(new Error('Не удалось загрузить стили страницы.'));
                document.head.insertBefore(link, before);
            });
        } } catch (error) {
            newStyles.forEach(link => link.remove());
            throw error;
        }
        if (ticket !== revision) { newStyles.forEach(link => link.remove()); return false; }
        document.querySelectorAll('link[data-workspace-module-style]').forEach(link => link.remove());
        newStyles.forEach(link => { delete link.dataset.workspaceNextStyle; link.dataset.workspaceModuleStyle = '1'; });
        const previousScroll = [scrollX, scrollY];
        if (!options.popstate) history.replaceState({ ...history.state, workspaceScroll: previousScroll }, '', location.href);
        const samePageSubmission = options.submission && response.url === renderedUrl;
        const preserveScroll = options.submission ? samePageSubmission : options.preserveScroll;
        const method = (options.submission ? samePageSubmission : options.replace) ? 'replaceState' : 'pushState';
        if (!options.popstate) history[method]({ workspaceScroll: preserveScroll ? previousScroll : [0, 0] }, '', response.url);
        committing = true;
        current.inert = true;
        let initialized = false;
        try {
        wspace.page.beginRender();
        wspace.page.dispose();
        document.documentElement.classList.remove('calendar-editor-open');
        document.body.classList.remove('file-manager-modal-open', 'wspace-dialog-open');
        for (const attribute of [...document.body.attributes]) {
            if (attribute.name.startsWith('data-') || attribute.name === 'class') document.body.removeAttribute(attribute.name);
        }
        for (const attribute of [...next.body.attributes]) {
            if (attribute.name.startsWith('data-') || attribute.name === 'class') document.body.setAttribute(attribute.name, attribute.value);
        }
        document.title = next.title;
        const csrf = next.querySelector('#csrf-token-template');
        if (csrf) {
            document.querySelector('#csrf-token-template')?.replaceWith(csrf.cloneNode(true));
            const input = csrf.content.querySelector('input');
            wspace.security?.refreshToken(input?.value || '');
            if (input) document.querySelectorAll('input[type="hidden"]').forEach(field => {
                if (field.name === input.name) field.value = input.value;
            });
        }
        const profile = next.querySelector('.navbar__profile');
        if (profile) document.querySelector('.navbar__profile')?.replaceChildren(...[...profile.childNodes].map(node => node.cloneNode(true)));
        incoming.querySelectorAll('style').forEach(style => {
            if (nonce && style.nonce === nonce) style.nonce = inlineNonce;
            else style.remove();
        });
        const scripts = [...incoming.querySelectorAll('script')];
        scripts.forEach(script => script.remove());
        current.replaceChildren(...incoming.childNodes);
        const navigationLinks = next.querySelectorAll('[data-nav-key]');
        navigationLinks.forEach(fresh => {
            const href = new URL(fresh.getAttribute('href'), response.url).href;
            const existing = [...document.querySelectorAll('[data-nav-key]')].find(link => link.dataset.navKey === fresh.dataset.navKey && link.href === href);
            if (!existing) return;
            existing.className = fresh.className;
            if (fresh.hasAttribute('aria-current')) existing.setAttribute('aria-current', fresh.getAttribute('aria-current'));
            else existing.removeAttribute('aria-current');
        });
        for (const script of next.querySelectorAll('script[data-workspace-runtime]')) await loadScript(script, nonce);
        if (window.wspaceRuntime) wspace.socketConfig = { ticket: window.wspaceRuntime.socketTicket || '', url: window.wspaceRuntime.socketUrl || '' };
        for (const script of next.querySelectorAll('script[data-workspace-module-script]')) await loadScript(script, nonce, true);
        for (const script of scripts) await loadScript(script, nonce);
        initialized = true;
        wspace.page.initialize();
        current.inert = false;
        renderedUrl = response.url;
        const scroll = options.scroll || (preserveScroll ? previousScroll : [0, 0]);
        window.scrollTo(...scroll);
        if (!preserveScroll) current.focus({ preventScroll: true });
        if (location.hash) document.getElementById(decodeURIComponent(location.hash.slice(1)))?.scrollIntoView();
        document.dispatchEvent(new CustomEvent('wspace:page-loaded', { detail: { url: response.url } }));
        return true;
        } finally {
            try {
                if (!initialized) wspace.page.initialize();
            } finally {
            current.inert = false;
            committing = false;
            if (queuedVisit) {
                const queued = queuedVisit;
                queuedVisit = null;
                window.setTimeout(() => void visit(queued.value, queued.options), 0);
            }
            }
        }
    }

    async function visit(value, options = {}) {
        if (committing) { queuedVisit = { value, options }; return false; }
        const url = new URL(value, location.href);
        if (!eligible(url)) { location.assign(url.href); return false; }
        if (submitting && !options.submission) return false;
        if (!options.skipGuard && !await wspace.page.mayLeave()) {
            if (options.popstate) history.pushState({}, '', renderedUrl);
            return false;
        }
        request?.abort();
        request = new AbortController();
        const ticket = ++revision;
        document.documentElement.classList.add('workspace-navigating');
        document.querySelector('#main-content')?.setAttribute('aria-busy', 'true');
        try {
            const headers = new Headers(options.headers || {});
            headers.set('Accept', 'text/html');
            const response = await fetch(url, { ...options.fetch, headers, credentials: 'same-origin', signal: request.signal });
            const type = response.headers.get('Content-Type') || '';
            if (!type.includes('text/html')) throw new Error(`Ожидалась страница, сервер вернул HTTP ${response.status}.`);
            if (ticket !== revision) return false;
            if (response.status === 401 || response.status === 403 || !eligible(new URL(response.url))) {
                location.assign(response.url); return false;
            }
            if (!response.ok && response.status >= 500) throw new Error(`Сервер временно недоступен (HTTP ${response.status}).`);
            return await render(await response.text(), response, options, ticket);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
                notify(error.message || 'Не удалось загрузить страницу. Повторите действие.');
            }
            return false;
        } finally {
            if (ticket === revision) {
                document.documentElement.classList.remove('workspace-navigating');
                document.querySelector('#main-content')?.removeAttribute('aria-busy');
            }
        }
    }

    async function submit(form, submitter) {
        if (submitting || !(form instanceof HTMLFormElement)) return false;
        const url = new URL(submitter?.hasAttribute('formaction') ? submitter.formAction : form.getAttribute('action') || location.href, location.href);
        const method = String(submitter?.formMethod || form.getAttribute('method') || 'GET').toUpperCase();
        const target = form.getAttribute('target');
        if (!eligible(url) || form.hasAttribute('data-native-navigation') || target && target !== '_self') {
            HTMLFormElement.prototype.submit.call(form); return false;
        }
        if (!await wspace.page.mayLeave()) return false;
        if (submitting) return false;
        const data = new FormData(form);
        if (submitter?.name) data.append(submitter.name, submitter.value);
        const csrf = document.querySelector('#csrf-token-template')?.content.querySelector('input');
        if (method !== 'GET' && csrf && !data.has(csrf.name)) data.append(csrf.name, csrf.value);
        if (method === 'GET') {
            url.search = '';
            for (const [key, value] of data) if (typeof value === 'string') url.searchParams.append(key, value);
            return visit(url.href, { skipGuard: true });
        }
        submitting = true;
        const buttons = [...form.querySelectorAll('button,input[type="submit"]')];
        const previous = buttons.map(button => button.disabled);
        buttons.forEach(button => { button.disabled = true; });
        try {
            return await visit(url.href, { submission: true, skipGuard: true, replace: true, preserveScroll: true, fetch: { method, body: data } });
        } finally {
            buttons.forEach((button, index) => { button.disabled = previous[index]; });
            submitting = false;
        }
    }

    wspace.navigation = { visit, submit };
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-native-navigation') || link.target && link.target !== '_self') return;
        const url = new URL(link.href, location.href);
        if (!eligible(url) || url.pathname === location.pathname && url.search === location.search && url.hash) return;
        event.preventDefault();
        void visit(url.href);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || String(form.getAttribute('method')).toLowerCase() === 'dialog') return;
        const target = form.getAttribute('target');
        if (!eligible(new URL(form.getAttribute('action') || location.href, location.href)) || form.hasAttribute('data-native-navigation') || target && target !== '_self') return;
        event.preventDefault(); void submit(form, event.submitter);
    });
    window.addEventListener('popstate', event => {
        const target = location.href;
        void visit(target, { popstate: true, skipGuard: false, scroll: event.state?.workspaceScroll || [0, 0] });
    });
})();
