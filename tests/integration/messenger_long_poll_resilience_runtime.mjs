import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const scriptPath = path.join(root, 'modules', 'messenger', 'views', 'script.js');

function fail(message) {
    console.error('[FAIL] ' + message);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) fail(message);
}

let source = fs.readFileSync(scriptPath, 'utf8')
    .replace(/^\{literal\}\s*/u, '')
    .replace(/\s*\{\/literal\}\s*$/u, '');

const bootstrapMarker = "document.addEventListener('DOMContentLoaded'";
if (!source.includes(bootstrapMarker)) fail('не найден MessengerApp');
source = source.replace(
    bootstrapMarker,
    "globalThis.__MessengerApp = MessengerApp;\n    " + bootstrapMarker
);

const elements = {
    'messenger-connection': { dataset: {}, addEventListener() {} },
    'messenger-connection-text': { textContent: '' },
    'message-send-button': { disabled: false, addEventListener() {} },
};

const dispatchedEvents = [];
const documentListeners = new Map();
const context = {
    console,
    CustomEvent: class CustomEvent {
        constructor(type, options = {}) {
            this.type = type;
            this.detail = options.detail;
        }
    },
    URL,
    URLSearchParams,
    Intl,
    AbortController,
    navigator: { onLine: true },
    setTimeout,
    clearTimeout,
    requestAnimationFrame: (callback) => callback(),
    window: {
        location: { search: '', protocol: 'https:', host: 'workspace.example.test' },
        wspace: { path: (value) => value },
        setTimeout,
        clearTimeout,
        addEventListener() {},
    },
    document: {
        hidden: false,
        getElementById(id) { return elements[id] ?? null; },
        addEventListener(type, handler) {
            if (!documentListeners.has(type)) documentListeners.set(type, []);
            documentListeners.get(type).push(handler);
        },
        dispatchEvent(event) {
            const type = event?.type || '';
            dispatchedEvents.push(type);
            (documentListeners.get(type) || []).forEach((handler) => handler(event));
            return true;
        },
        createElement() {
            return {
                classList: { add() {}, remove() {} },
                append() {},
                setAttribute() {},
                style: {},
            };
        },
        body: { append() {} },
    },
};
context.globalThis = context;
context.WebSocket = { OPEN: 1 };

vm.createContext(context);
vm.runInContext(source, context, { filename: scriptPath });

const MessengerApp = context.__MessengerApp;
if (typeof MessengerApp !== 'function') fail('не удалось получить MessengerApp');

const rootElement = {
    dataset: { userUid: 'user-1', userName: 'Пользователь', socketUrl: '', socketTicket: '' },
    classList: { add() {}, remove() {} },
};

class HangingWebSocket {
    static OPEN = 1;
    static CONNECTING = 0;

    constructor(url) {
        this.url = url;
        this.readyState = HangingWebSocket.CONNECTING;
        this.listeners = new Map();
    }

    addEventListener(type, handler) {
        this.listeners.set(type, handler);
    }

    send() {}
    close() {}
}

context.WebSocket = HangingWebSocket;

const coldStartRoot = {
    dataset: {
        userUid: 'user-1',
        userName: 'Пользователь',
        socketUrl: '/messenger-ws',
        socketTicket: 'ticket-never-opens',
    },
    classList: { add() {}, remove() {} },
};
const coldStartApp = new MessengerApp(coldStartRoot);
const coldStartStates = [];
let coldStartPollStarts = 0;
coldStartApp.setConnectionState = (state, text) => coldStartStates.push([state, text]);
coldStartApp.resumeLongPoll = () => {
    coldStartPollStarts += 1;
};
coldStartApp.connect();

assert(coldStartApp.longPollActive === true, 'холодный старт ждёт WebSocket перед включением Long Poll');
assert(coldStartPollStarts === 1, 'Long Poll не стартовал синхронно при зависшем WebSocket handshake');
assert(coldStartApp.socket instanceof HangingWebSocket, 'WebSocket не запускается параллельно с Long Poll');
assert(
    coldStartStates.some(([state, text]) => state === 'online' && text === 'В сети'),
    'холодный старт не показывает обычное состояние «В сети» при рабочем Long Poll'
);

context.WebSocket = { OPEN: 1 };

const app = new MessengerApp(rootElement);
const states = [];
app.setConnectionState = (state, text) => states.push([state, text]);
app.longPollRetryDelay = () => 0;
app.longPollActive = true;
app.longPollGeneration = 1;

let fetchCalls = 0;
context.fetch = async () => {
    fetchCalls += 1;
    if (fetchCalls === 1) {
        return {
            ok: false,
            status: 503,
            async json() { return { status: 'error' }; },
        };
    }

    return {
        ok: true,
        status: 200,
        async json() {
            app.longPollActive = false;
            return {
                status: 'ok',
                changed: false,
                cursor: 'a'.repeat(64),
                events: [],
            };
        },
    };
};

await app.runLongPoll(1);

assert(fetchCalls === 2, 'после временного HTTP 503 Long Poll не запустил следующий запрос');
assert(
    states.some(([state, text]) => state === 'fallback' && text.includes('Восстанавливаем синхронизацию')),
    'временная ошибка не остаётся в рабочем fallback-состоянии'
);
assert(
    !states.some(([state]) => state === 'offline'),
    'одиночная ошибка Long Poll ошибочно переводит Messenger в offline'
);

const suspendedApp = new MessengerApp(rootElement);
const suspendedStates = [];
suspendedApp.setConnectionState = (state, text) => suspendedStates.push([state, text]);
suspendedApp.longPollActive = true;
suspendedApp.longPollGeneration = 1;

let suspendedFetchCalls = 0;
context.fetch = async () => {
    suspendedFetchCalls += 1;
    if (suspendedFetchCalls === 1) {
        return {
            ok: true,
            status: 200,
            async json() {
                return {
                    status: 'ok',
                    transport: 'long_poll',
                    changed: false,
                    cursor: '',
                    events: [],
                    suspended: true,
                    retry_after_ms: 1,
                };
            },
        };
    }

    return {
        ok: true,
        status: 200,
        async json() {
            suspendedApp.longPollActive = false;
            return {
                status: 'ok',
                changed: false,
                cursor: 'c'.repeat(64),
                events: [],
            };
        },
    };
};

await suspendedApp.runLongPoll(1);

assert(
    suspendedFetchCalls === 2,
    'временно приостановленный Long Poll не повторил запрос после готовности сервера'
);
assert(
    suspendedStates.some(([state, text]) => state === 'fallback' && text.includes('Синхронизация временно приостановлена')),
    'suspended Long Poll не показывает рабочее состояние ожидания'
);

const expiredApp = new MessengerApp(rootElement);
const expiredStates = [];
const expiredToasts = [];
expiredApp.setConnectionState = (state, text) => expiredStates.push([state, text]);
expiredApp.showToast = (message) => expiredToasts.push(String(message));
expiredApp.longPollActive = true;
expiredApp.longPollGeneration = 1;

context.fetch = async () => ({
    ok: false,
    status: 401,
    async json() { return { status: 'error' }; },
});

await expiredApp.runLongPoll(1);

assert(expiredApp.longPollActive === false, 'Long Poll продолжает запросы после завершения сессии');
assert(
    expiredStates.some(([state, text]) => state === 'offline' && text.includes('Сессия завершена')),
    'завершение сессии не отражено в состоянии Messenger'
);
assert(
    expiredToasts.some((message) => message.includes('Сессия завершена')),
    'пользователь не получил понятное сообщение о завершении сессии'
);
assert(expiredApp.sessionUnavailable === true, 'завершённая сессия не стала конечным состоянием transport');
assert(
    dispatchedEvents.includes('wspace:messenger-session-unavailable'),
    'Long Poll не сообщил connection UX о завершении сессии'
);

const actionExpiredApp = new MessengerApp(rootElement);
actionExpiredApp.longPollActive = true;
actionExpiredApp.longPollGeneration = 1;
actionExpiredApp.setConnectionState = () => {};
actionExpiredApp.showToast = () => {};
context.fetch = async () => ({
    ok: false,
    status: 403,
    async json() { return { status: 'error' }; },
});
const actionConfirmed = await actionExpiredApp.sendHttpEventConfirmed('MessangerSocket:get_dialogs', {});
assert(actionConfirmed === false, 'HTTP action ошибочно подтверждён после завершения сессии');
assert(actionExpiredApp.sessionUnavailable === true, 'HTTP action 403 не остановил transport');
assert(actionExpiredApp.longPollActive === false, 'HTTP action 403 оставил Long Poll активным');

const updateApp = new MessengerApp(rootElement);
updateApp.setConnectionState = () => {};
updateApp.longPollActive = true;
updateApp.longPollGeneration = 7;
updateApp.reconnectTimer = context.window.setTimeout(() => {}, 60000);

let socketClosed = false;
updateApp.socket = {
    readyState: 1,
    close() {
        socketClosed = true;
    },
};
updateApp.socketAuthorized = true;
updateApp.bindEvents();

context.document.dispatchEvent(new context.CustomEvent('wspace:update-install-start'));

assert(updateApp.transportSuspended === true, 'начало updater не приостановило Messenger transport');
assert(updateApp.longPollActive === false, 'начало updater оставило Long Poll активным');
assert(updateApp.socketAuthorized === false, 'начало updater оставило WebSocket авторизованным');
assert(updateApp.socket === null, 'начало updater оставило ссылку на WebSocket');
assert(socketClosed === true, 'начало updater не закрыло WebSocket');
assert(updateApp.reconnectTimer === null, 'начало updater оставило reconnect timer');
updateApp.scheduleReconnect();
updateApp.startLongPoll();
assert(updateApp.reconnectTimer === null, 'приостановленный transport снова запланировал WebSocket reconnect');
assert(updateApp.longPollActive === false, 'приостановленный transport снова запустил Long Poll');

const watchdogApp = new MessengerApp(rootElement);
const watchdogStates = [];
watchdogApp.setConnectionState = (state, text) => watchdogStates.push([state, text]);
watchdogApp.longPollRetryDelay = () => 0;
watchdogApp.longPollActive = true;
watchdogApp.longPollGeneration = 1;

let watchdogFetchCalls = 0;
const nativeSetTimeout = setTimeout;
context.window.setTimeout = (callback, delay) => {
    if (delay === 32000) {
        queueMicrotask(callback);
        return 9001;
    }
    return nativeSetTimeout(callback, 0);
};
context.window.clearTimeout = clearTimeout;

context.fetch = (url, options) => {
    watchdogFetchCalls += 1;
    if (watchdogFetchCalls === 1) {
        return new Promise((resolve, reject) => {
            options.signal.addEventListener('abort', () => {
                const error = new Error('watchdog abort');
                error.name = 'AbortError';
                reject(error);
            }, { once: true });
        });
    }

    return Promise.resolve({
        ok: true,
        status: 200,
        async json() {
            watchdogApp.longPollActive = false;
            return {
                status: 'ok',
                changed: false,
                cursor: 'b'.repeat(64),
                events: [],
            };
        },
    });
};

await watchdogApp.runLongPoll(1);

assert(watchdogFetchCalls === 2, 'зависший Long Poll не был перезапущен watchdog');
assert(
    watchdogStates.some(([state]) => state === 'fallback'),
    'watchdog не сохранил рабочий fallback-транспорт во время восстановления'
);

console.log('[OK] Messenger Long Poll стартует до WebSocket, восстанавливается после сбоев, останавливается перед updater и корректно завершает transport при окончании сессии');
