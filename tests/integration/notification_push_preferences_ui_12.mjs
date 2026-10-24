// Поведенческая проверка переключателя Push без сети и привязки настоящего устройства.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const source = fs.readFileSync(new URL('assets/js/notification-center.js', root), 'utf8');
const pushSource = fs.readFileSync(new URL('assets/js/pwa-push.js', root), 'utf8');

class TestElement {
    constructor(tag) {
        this.tag = tag;
        this.children = [];
        this.attributes = {};
        this.listeners = new Map();
        this.dataset = {};
        this.textContent = '';
        this.checked = false;
        this.disabled = false;
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    setAttribute(name, value) {
        this.attributes[name] = value;
    }

    addEventListener(name, callback) {
        this.listeners.set(name, callback);
    }
}

function walk(element) {
    return [element, ...element.children.flatMap(walk)];
}

function pushSwitch(card) {
    const labels = walk(card).filter((item) => item.tag === 'label');
    return labels.find((label) => walk(label).some(
        (item) => item.tag === 'strong' && item.textContent === 'Push на устройствах'
    )) ?? null;
}

const marker = "    if (document.readyState === 'loading') {";
assert.equal(source.split(marker).length, 2, 'невозможно найти единственную точку входа сценария');
const instrumented = source.replace(
    marker,
    '    globalThis.__notesPushTest = { renderPreferenceCard, browserHint };\n' + marker
);
const context = {
    document: {
        readyState: 'loading',
        createElement: (tag) => new TestElement(tag),
        addEventListener: () => {},
    },
    window: {},
};
vm.runInNewContext(instrumented, context, { filename: 'notification-center.js' });
const { renderPreferenceCard } = context.__notesPushTest;
assert.equal(typeof renderPreferenceCard, 'function');

const subscription = {
    category: 'tasks.deadlines',
    external_enabled: false,
    channels: { browser: false, email: false },
    effective_channels: { browser: false, email: false },
};
const ready = { browser: { available: true }, email: { available: false } };
const card = renderPreferenceCard(subscription, ready, {}, {});
const label = pushSwitch(card);
assert(label, 'Push должен появиться как самостоятельный переключатель категории');
let control = walk(label).find((item) => item.tag === 'input');
assert(control && control.type === 'checkbox');
assert.equal(control.disabled, false, 'подключённое устройство должно разрешать включение категории');
assert.equal(control.checked, false, 'внешняя доставка остаётся выключенной без согласия');
assert.equal(control.listeners.has('change'), true, 'переключатель должен отправлять изменение на сервер');
assert(walk(label).some((item) => item.tag === 'small'
    && item.textContent.includes('Устройства подключены')));

const unavailable = renderPreferenceCard(subscription, {
    browser: { available: false }, email: { available: false },
}, {}, {});
const unavailableLabel = pushSwitch(unavailable);
control = walk(unavailableLabel).find((item) => item.tag === 'input');
assert.equal(control.disabled, true, 'неподключённый Push нельзя включить как работающий');
assert(walk(unavailableLabel).some((item) => item.tag === 'small'
    && item.textContent.includes('Сначала включите Push на устройстве')));

const subscribed = renderPreferenceCard({
    ...subscription,
    external_enabled: true,
    channels: { browser: true },
    effective_channels: { browser: true },
}, ready, {}, {});
control = walk(pushSwitch(subscribed)).find((item) => item.tag === 'input');
assert.equal(control.checked, true, 'ранее сохранённая подписка должна отображаться включённой');

const disconnected = renderPreferenceCard({
    ...subscription,
    channels: { browser: true },
    effective_channels: { browser: false },
}, { browser: { available: false } }, {}, {});
control = walk(pushSwitch(disconnected)).find((item) => item.tag === 'input');
assert.equal(control.disabled, false, 'после отвязки устройства пользователь должен суметь выключить старую подписку');

assert(!source.includes('Browser Push · позже'), 'устаревшая пометка «позже» недопустима');
assert(source.includes("document.addEventListener('wspace:push-device-updated'"));
assert(source.includes('state.pendingPreferencesRefresh = true'));
assert(source.includes('if (state.pendingPreferencesRefresh)'));
assert.equal(pushSource.split("new CustomEvent('wspace:push-device-updated')").length - 1, 2,
    'подключение и отключение устройства должны обновлять доступность категорий');

console.log('Push доступен по категориям, сохраняет согласие и обновляется после смены устройств');
