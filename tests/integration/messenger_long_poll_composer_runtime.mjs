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
if (!source.includes(bootstrapMarker)) {
    fail('не найден bootstrap MessengerApp');
}
source = source.replace(
    bootstrapMarker,
    "globalThis.__MessengerApp = MessengerApp;\n    " + bootstrapMarker
);

let activeElements = {};
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
        location: {
            search: '',
            protocol: 'https:',
            host: 'workspace.example.test',
        },
        wspace: {
            path: (value) => value,
        },
        setTimeout,
        clearTimeout,
        confirm: () => true,
    },
    document: {
        hidden: false,
        body: { append() {} },
        getElementById(id) {
            return activeElements[id] ?? null;
        },
        addEventListener() {},
        createElement() {
            return {
                classList: { add() {}, remove() {} },
                append() {},
                setAttribute() {},
                style: {},
            };
        },
    },
};
context.globalThis = context;
context.WebSocket = { OPEN: 1 };

vm.createContext(context);
vm.runInContext(source, context, { filename: scriptPath });

const MessengerApp = context.__MessengerApp;
if (typeof MessengerApp !== 'function') {
    fail('не удалось получить настоящий класс MessengerApp');
}

function makeApp(text = 'Неотправленный текст') {
    const input = {
        value: text,
        style: {},
        scrollHeight: 24,
        focus() {},
        setSelectionRange() {},
        addEventListener() {},
    };
    const send = {
        disabled: false,
        addEventListener() {},
    };
    activeElements = {
        'message-input': input,
        'message-send-button': send,
    };

    const rootElement = {
        dataset: {
            userUid: 'user-1',
            userName: 'Пользователь',
            socketUrl: '',
            socketTicket: '',
        },
        classList: { add() {}, remove() {} },
    };

    const app = new MessengerApp(rootElement);
    app.currentDialog = { uid: 'dialog-1' };
    app.replyTo = { uid: 'reply-1' };
    app.socket = null;
    app.socketAuthorized = false;
    app.longPollActive = false;

    const toasts = [];
    app.showToast = (message) => toasts.push(String(message));

    return { app, input, send, toasts };
}

function response(status, payload) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async json() {
            return payload;
        },
    };
}

async function expectFailure(label, fetchImpl) {
    const { app, input, send, toasts } = makeApp();
    context.fetch = fetchImpl;

    await app.submitComposer();

    assert(input.value === 'Неотправленный текст', label + ': текст черновика потерян');
    assert(app.replyTo?.uid === 'reply-1', label + ': reply context потерян');
    assert(send.disabled === false, label + ': кнопка отправки осталась заблокирована');
    assert(
        toasts.includes('Резервный канал временно недоступен'),
        label + ': пользователь не получил сообщение об отказе'
    );
}

await expectFailure('HTTP 403', async () => response(403, { status: 'error' }));
await expectFailure('HTTP 503', async () => response(503, { status: 'error' }));
await expectFailure('сетевая ошибка', async () => {
    throw new Error('network down');
});
await expectFailure(
    'ошибка обработчика внутри HTTP 200',
    async () => response(200, { status: 'error', message: 'handler failed' })
);

{
    const { app, input, send } = makeApp();
    context.fetch = async () => response(200, { status: 'ok', events: [] });

    await app.submitComposer();

    assert(input.value === '', 'успешная HTTP отправка должна очистить подтверждённый черновик');
    assert(app.replyTo === null, 'успешная HTTP отправка должна очистить подтверждённый reply context');
    assert(send.disabled === false, 'после успешной отправки кнопка должна быть доступна');
}

{
    const { app, input } = makeApp();
    let resolveRequest;
    let requests = 0;

    context.fetch = () => {
        requests++;
        return new Promise((resolve) => {
            resolveRequest = resolve;
        });
    };

    const first = app.submitComposer();
    await Promise.resolve();
    const duplicate = app.submitComposer();
    await duplicate;

    assert(requests === 1, 'повторный submit во время pending-запроса создал дубликат');

    input.value = 'Новый текст после отправки';
    resolveRequest(response(200, { status: 'ok', events: [] }));
    await first;

    assert(
        input.value === 'Новый текст после отправки',
        'поздний успешный ответ стёр новый текст пользователя'
    );
    assert(
        app.replyTo?.uid === 'reply-1',
        'поздний успешный ответ не должен очищать context нового черновика'
    );
}

console.log('[OK] Messenger HTTP fallback сохраняет неподтверждённый черновик');
