// Проверка фоновой PWA без настоящих сетевых запросов или телефонных данных.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const source = fs.readFileSync(new URL('service-worker.js', root), 'utf8');
const manifest = JSON.parse(fs.readFileSync(new URL('manifest.webmanifest', root), 'utf8'));
const pushUI = fs.readFileSync(new URL('assets/js/pwa-push.js', root), 'utf8');
const handlers = new Map();
const displayed = [];
const navigations = [];
const scope = 'https://notes.example.test/notes/';
let opened = null;
let focused = false;
const tab = {
    url: scope + 'tasks',
    async navigate(url) { navigations.push(url); return tab; },
    async focus() { focused = true; return tab; },
};

const worker = {
    location: { origin: 'https://notes.example.test' },
    registration: {
        scope,
        async showNotification(title, options) { displayed.push({ title, ...options }); },
    },
    clients: {
        async matchAll() { return [tab]; },
        async openWindow(url) { opened = url; return { url }; },
        async claim() {},
    },
    async skipWaiting() {},
    addEventListener(name, listener) { handlers.set(name, listener); },
};
vm.runInNewContext(source, { self: worker, URL, Promise }, { filename: 'service-worker.js' });
assert(handlers.has('push') && handlers.has('notificationclick'));
assert(!handlers.has('fetch'), 'нельзя кэшировать страницы или API');

let background;
handlers.get('push')({
    data: { json: () => ({ id: 17, path: '/notes/messenger/12', body: 'Секрет' }) },
    waitUntil(promise) { background = promise; },
});
await background;
assert.equal(displayed[0].title, 'Notes');
assert(!displayed[0].body.includes('Секрет'), 'текст из внешнего события не должен показываться');
assert.equal(displayed[0].data.url, scope + 'messenger/12');

let clicked;
handlers.get('notificationclick')({
    notification: { data: { url: displayed[0].data.url }, close() {} },
    waitUntil(promise) { clicked = promise; },
});
await clicked;
assert.deepEqual(navigations, [scope + 'messenger/12']);
assert(focused, 'должна открыться существующая вкладка');

focused = false;
handlers.get('notificationclick')({
    notification: { data: { url: 'https://attacker.example.org/steal' }, close() {} },
    waitUntil(promise) { clicked = promise; },
});
await clicked;
assert.equal(navigations.at(-1), scope, 'открытие постороннего домена запрещено');

handlers.get('push')({
    data: { json: () => ({ id: 30, path: '//attacker.example.org/x' }) },
    waitUntil(promise) { background = promise; },
});
await background;
assert.equal(displayed.at(-1).data.url, scope, 'сетевой путь без домена Notes запрещён');
assert.equal(opened, null, 'внешняя вкладка не должна открываться');

assert.equal(manifest.display, 'standalone');
assert(manifest.icons.length > 0);
assert(manifest.shortcuts.some(x => x.name === 'Задачи'));
assert(manifest.shortcuts.some(x => x.name === 'Сообщения'));
assert(pushUI.includes('Notification.requestPermission()'));
assert(pushUI.includes('/push/subscribe') && pushUI.includes('/push/unsubscribe'));
assert(!pushUI.includes('localStorage.setItem'), 'приватные Push-ключи не сохраняются в браузерном хранилище');
console.log('Проверен PWA Push: безопасный переход, закрытый браузер, установка и явная привязка');
