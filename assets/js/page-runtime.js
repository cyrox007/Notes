(function bootstrapPageRuntime() {
    'use strict';
    const wspace = window.wspace = window.wspace || {};
    const initializers = new Map();
    let scope = makeScope();
    let started = false;
    let rendering = false;

    function makeScope() {
        const controller = new AbortController();
        const cleanups = [];
        const guards = [];
        const timers = new Set();
        const nodes = new Set();
        let disposed = false;
        let pending = 0;
        const current = {
            get signal() { return controller.signal; },
            get disposed() { return disposed; },
            get pending() { return pending; },
            onInit(callback) { if (!disposed) callback(); },
            onDispose(callback) { cleanups.push(callback); },
            listen(target, type, callback, options = {}) {
                if (disposed) return;
                if (target === window && type === 'pagehide') cleanups.push(callback);
                if (target === window && type === 'beforeunload') guards.push(callback);
                const settings = typeof options === 'boolean' ? { capture: options } : { ...options };
                target.addEventListener(type, callback, { ...settings, signal: controller.signal });
            },
            setTimeout(callback, delay, ...args) {
                if (disposed) return 0;
                const id = window.setTimeout(() => { timers.delete(id); if (!disposed) callback(...args); }, delay);
                timers.add(id); return id;
            },
            setInterval(callback, delay, ...args) {
                if (disposed) return 0;
                const id = window.setInterval(() => { if (!disposed) callback(...args); }, delay);
                timers.add(id); return id;
            },
            observer(Type, ...args) {
                const observer = new Type(...args);
                cleanups.push(() => observer.disconnect());
                return observer;
            },
            append(...elements) {
                elements.forEach(element => nodes.add(element));
                document.body.append(...elements);
            },
            xhr() {
                const xhr = new XMLHttpRequest();
                xhr.addEventListener('loadstart', () => pending++);
                xhr.addEventListener('loadend', () => pending = Math.max(0, pending - 1));
                cleanups.push(() => xhr.abort());
                return xhr;
            },
            async fetch(input, init = {}) {
                const method = String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
                const mutation = !['GET', 'HEAD', 'OPTIONS'].includes(method);
                if (disposed) throw new DOMException('Page disposed', 'AbortError');
                if (mutation) pending++;
                try { return await window.fetch(input, init); }
                finally { if (mutation) pending--; }
            },
            visit(url, options) { return disposed ? Promise.resolve(false) : wspace.navigation.visit(url, options); },
            refresh() { return current.visit(location.href, { replace: true, preserveScroll: true }); },
            submit(form, submitter) { return disposed ? Promise.resolve(false) : wspace.navigation.submit(form, submitter); },
            async mayLeave() {
                if (pending > 0) {
                    wspace.feedback?.toast('Дождитесь завершения сохранения или загрузки файла.');
                    return false;
                }
                const event = new Event('beforeunload', { cancelable: true });
                guards.forEach(callback => callback(event));
                if (!event.defaultPrevented) return true;
                return wspace.feedback
                    ? wspace.feedback.confirm('Есть несохранённые изменения. Перейти на другую страницу?', { title: 'Несохранённые изменения' })
                    : window.confirm('Есть несохранённые изменения. Перейти на другую страницу?');
            },
            dispose() {
                if (disposed) return;
                disposed = true;
                controller.abort();
                cleanups.forEach(callback => { try { callback(new Event('pagehide')); } catch (error) { console.error(error); } });
                timers.forEach(id => { window.clearTimeout(id); window.clearInterval(id); });
                document.querySelectorAll('#main-content audio, #main-content video').forEach(media => media.pause());
                nodes.forEach(node => {
                    node.querySelectorAll?.('audio,video').forEach(media => media.pause());
                    node.remove();
                });
            }
        };
        return current;
    }

    function initialize() {
        // The messenger transport must exist before its UI extensions attach.
        const entries = [...initializers.entries()].sort(([a], [b]) => {
            const priority = key => key === 'modules/messenger/views/script.js' ? -10 : key === 'assets/js/messenger-connection-ux.js' ? 10 : 0;
            return priority(a) - priority(b);
        });
        for (const [, callback] of entries) callback(scope);
    }

    wspace.page = {
        register(key, callback) {
            initializers.set(key, callback);
            if (started && !rendering) callback(scope);
        },
        beginRender() { rendering = true; },
        dispose() { scope.dispose(); scope = makeScope(); },
        initialize() { rendering = false; initialize(); },
        mayLeave() { return scope.mayLeave(); },
        visit(url, options) { return wspace.navigation.visit(url, options); },
        refresh() { return wspace.navigation.visit(location.href, { replace: true, preserveScroll: true }); },
        submit(form, submitter) { return wspace.navigation.submit(form, submitter); }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { started = true; initialize(); }, { once: true });
    } else {
        started = true;
        initialize();
    }
})();
