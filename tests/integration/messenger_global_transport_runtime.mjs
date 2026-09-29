import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const scriptPath = path.join(root, 'assets', 'js', 'messenger-global-notifications.js');
const source = fs.readFileSync(scriptPath, 'utf8');

function fail(message) {
    console.error('[FAIL] ' + message);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) fail(message);
}

const documentListeners = new Map();
const windowListeners = new Map();
const timers = new Map();
const order = [];
let nextTimer = 1;
let pollSignal = null;
let socketInstance = null;

function addListener(target, type, handler) {
    if (!target.has(type)) target.set(type, []);
    target.get(type).push(handler);
}

function dispatch(target, type) {
    (target.get(type) || []).forEach((handler) => handler({ type }));
}

const link = {
    href: '/messenger/',
    title: '',
    setAttribute(name, value) {
        this[name] = String(value);
    },
    addEventListener() {},
};
const badge = {
    hidden: true,
    textContent: '',
    setAttribute() {},
};

class HangingWebSocket {
    static OPEN = 1;
    static CONNECTING = 0;

    constructor(url) {
        order.push('websocket');
        this.url = url;
        this.readyState = HangingWebSocket.CONNECTING;
        this.listeners = new Map();
        socketInstance = this;
    }

    addEventListener(type, handler) {
        this.listeners.set(type, handler);
    }

    send() {}
    close() {
        this.readyState = 3;
    }
}

const context = {
    console,
    URL,
    URLSearchParams,
    AbortController,
    WebSocket: HangingWebSocket,
    navigator: { onLine: true },
    setTimeout: (callback, delay) => {
        const id = nextTimer++;
        timers.set(id, { callback, delay });
        return id;
    },
    clearTimeout: (id) => timers.delete(id),
    window: {
        location: { href: 'https://workspace.example.test/notes/' },
        wspace: {
            path: (value) => value,
            socketConfig: {
                url: 'wss://workspace.example.test/ws',
            },
        },
        setTimeout: (callback, delay) => {
            const id = nextTimer++;
            timers.set(id, { callback, delay });
            return id;
        },
        clearTimeout: (id) => timers.delete(id),
        addEventListener(type, handler) {
            addListener(windowListeners, type, handler);
        },
    },
    document: {
        visibilityState: 'visible',
        querySelector(selector) {
            return selector.includes('data-nav-key="messenger"') ? link : null;
        },
        getElementById(id) {
            if (id === 'messenger-unread-badge') return badge;
            if (id === 'messenger-app') return null;
            return null;
        },
        addEventListener(type, handler) {
            addListener(documentListeners, type, handler);
        },
    },
};
context.globalThis = context;
context.window.window = context.window;

context.fetch = async (url, options = {}) => {
    const value = String(url);
    if (value.includes('/messenger/realtime/poll')) {
        order.push('poll');
        pollSignal = options.signal;
        return new Promise(() => {});
    }

    if (value.includes('/messenger/socket-ticket')) {
        order.push('ticket');
        return {
            ok: true,
            status: 200,
            async json() {
                return { status: 'ok', ticket: 'ticket-for-hanging-websocket' };
            },
        };
    }

    throw new Error('Неожиданный HTTP-запрос глобального Messenger: ' + value);
};

vm.createContext(context);
vm.runInContext(source, context, { filename: scriptPath });
dispatch(documentListeners, 'DOMContentLoaded');

await Promise.resolve();
await Promise.resolve();
await Promise.resolve();

assert(order[0] === 'poll', 'глобальный Messenger пытается WebSocket до запуска Long Poll');
assert(order.includes('ticket'), 'глобальный Messenger не пытается получить WebSocket ticket в фоне');
assert(order.includes('websocket'), 'глобальный Messenger не запускает WebSocket как ускоритель');
assert(
    order.indexOf('poll') < order.indexOf('ticket')
        && order.indexOf('poll') < order.indexOf('websocket'),
    'глобальный Long Poll должен стартовать раньше WebSocket fast path'
);
assert(pollSignal instanceof AbortSignal, 'глобальный Long Poll не создал abort-aware HTTP request');
assert(pollSignal.aborted === false, 'глобальный Long Poll неожиданно остановлен на старте');
assert(socketInstance?.readyState === HangingWebSocket.CONNECTING, 'тест не воспроизводит зависший WebSocket handshake');

context.document.visibilityState = 'hidden';
dispatch(documentListeners, 'visibilitychange');

assert(
    pollSignal.aborted === false,
    'скрытие единственной вкладки остановило глобальный Long Poll без другого владельца transport'
);

dispatch(windowListeners, 'pagehide');
assert(pollSignal.aborted === true, 'уход со страницы не остановил глобальный Long Poll');

console.log('[OK] Глобальный Messenger запускает Long Poll первым и сохраняет transport в фоновой вкладке');
