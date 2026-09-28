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

const context = {
    console,
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
        addEventListener() {},
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
    states.some(([state, text]) => state === 'fallback' && text.includes('переподключение')),
    'временная ошибка не остаётся в рабочем fallback-состоянии'
);
assert(
    !states.some(([state]) => state === 'offline'),
    'одиночная ошибка Long Poll ошибочно переводит Messenger в offline'
);

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

console.log('[OK] Messenger Long Poll самовосстанавливается после HTTP-сбоя и зависшего запроса');
