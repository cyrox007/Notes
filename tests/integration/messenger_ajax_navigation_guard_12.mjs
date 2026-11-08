import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const runtime = readFileSync('assets/js/page-runtime.js', 'utf8');
const messenger = readFileSync('modules/messenger/views/script.js', 'utf8');
const connection = readFileSync('assets/js/messenger-connection-ux.js', 'utf8');

const listeners = new Map();
const alerts = [];
const requests = [];
const document = {
    readyState: 'loading',
    addEventListener(name, listener) { listeners.set(name, listener); },
};

const window = {
    wspace: {
        feedback: {
            toast(message) { alerts.push(message); },
        },
    },
};
const sandbox = {
    window,
    document,
    AbortController,
    DOMException,
    Event,
    Request,
    console,
    location: { href: 'https://notes.example.test/messenger/' },
};
vm.runInNewContext(runtime, sandbox, { filename: 'assets/js/page-runtime.js' });
listeners.get('DOMContentLoaded')?.();

let scope;
window.wspace.page.register('проверка-переходов', current => { scope = current; });
assert.ok(scope, 'не получена текущая область страницы');

function defer() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { resolve, promise };
}

const background = defer();
window.fetch = (url, options) => {
    requests.push({ url, options });
    return background.promise;
};

const reading = scope.fetch('/messenger/realtime/action', {
    method: 'POST',
    workspaceReadOnly: true,
    body: 'action=MessangerSocket%3Aget_dialogs',
});

assert.equal(await window.wspace.page.mayLeave(), true, 'фоновое чтение блокирует переход');
assert.equal(
    Object.hasOwn(requests[0].options, 'workspaceReadOnly'),
    false,
    'внутренний признак ошибочно передан в нативный fetch'
);
background.resolve({ ok: true });
await reading;

const mutation = defer();
window.fetch = (url, options) => {
    requests.push({ url, options });
    return mutation.promise;
};
const saving = scope.fetch('/messenger/realtime/action', {
    method: 'POST',
    body: 'action=MessangerSocket%3Amessage_send',
});
assert.equal(await window.wspace.page.mayLeave(), false, 'сохранение не блокирует уход');
assert.ok(alerts.at(-1)?.includes('завершения сохранения'), 'нет объяснения блокировки');
mutation.resolve({ ok: true });
await saving;
assert.equal(await window.wspace.page.mayLeave(), true, 'после сохранения навигация не восстановлена');

const readonly = messenger.match(/workspaceReadOnly:\s*\[([\s\S]*?)\]\.includes\(action\)/);
assert.ok(readonly, 'Messenger не помечает фоновые запросы чтения');
const actions = [...readonly[1].matchAll(/'([^']+)'/g)].map(match => match[1]);
assert.deepEqual(actions, [
    'MessangerSocket:get_dialogs',
    'MessangerSocket:load',
    'DialogStateSocket:list',
    'ReceiptSocket:list',
], 'в список чтения попало неизвестное или изменяющее действие');
assert.ok(
    connection.includes('workspaceReadOnly: true') && connection.includes('/messenger/socket-ticket'),
    'обновление билета продолжает блокировать навигацию'
);

console.log('[OK] Фоновое чтение Messenger не блокирует переход; сохранение блокирует до подтверждения');
