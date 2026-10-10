import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source = fs.readFileSync(new URL('../../assets/js/messenger-connection-ux.js', import.meta.url), 'utf8');
const element = () => ({
    dataset: {}, hidden: false, textContent: '', children: [],
    setAttribute() {}, addEventListener() {}, append(...children) { this.children.push(...children); },
});
const root = element();
const connection = element();
connection.dataset.state = 'connecting';
const text = element();
text.textContent = 'Подключение…';
const chat = element();
chat.querySelector = () => element();
chat.insertBefore = (child) => chat.children.push(child);
const elements = new Map([
    ['messenger-app', root], ['messenger-connection', connection],
    ['messenger-connection-text', text], ['chat-active', chat],
]);
let now = 0;
let timerId = 0;
const timers = new Map();
const setTimer = (fn, delay) => {
    const id = ++timerId;
    timers.set(id, { fn, at: now + delay });
    return id;
};
function advance(milliseconds) {
    now += milliseconds;
    for (const [id, timer] of [...timers]) {
        if (timer.at <= now) { timers.delete(id); timer.fn(); }
    }
}
const app = {
    connect() {},
    setConnectionState(state, value) { connection.dataset.state = state; text.textContent = value; },
};
let ready;
vm.runInNewContext(source, {
    window: {
        wspace: { messenger: app }, addEventListener() {},
        setTimeout: setTimer, clearTimeout: (id) => timers.delete(id),
        clearInterval() {},
    },
    document: {
        getElementById: (id) => elements.get(id), createElement: element,
        addEventListener: (type, fn) => { if (type === 'DOMContentLoaded') ready = fn; },
    },
    navigator: { onLine: true }, console,
});
ready();
const banner = chat.children[0];
assert.equal(banner.hidden, true, 'Обычное подключение сразу показывает тревожный баннер');
app.setConnectionState('fallback', 'Восстанавливаем синхронизацию…');
advance(2000);
assert.equal(banner.hidden, true, 'Краткая задержка показана как сбой');
app.setConnectionState('online', 'Long Poll');
advance(5000);
assert.equal(text.textContent, 'Long Poll', 'Старый таймер перезаписал восстановленное соединение');
assert.equal(banner.hidden, true);
assert.equal(connection.dataset.pending, undefined);

app.setConnectionState('fallback', 'Восстанавливаем синхронизацию…');
advance(3000);
app.setConnectionState('fallback', 'Восстанавливаем синхронизацию…');
advance(2000);
assert.equal(banner.hidden, false, 'Повторные попытки бесконечно откладывают предупреждение');
assert.equal(text.textContent, 'Восстанавливаем синхронизацию…');
app.setConnectionState('online', 'WebSocket');
assert.equal(banner.hidden, true);

app.setConnectionState('connecting', 'Подключение…');
app.setConnectionState('offline', 'Нет интернета');
assert.equal(banner.hidden, false, 'Нет интернета скрыто периодом ожидания');
advance(6000);
assert.equal(text.textContent, 'Нет интернета');
app.setConnectionState('online', 'Long Poll');
app.setConnectionState('fallback', 'Синхронизация временно приостановлена…');
assert.equal(banner.hidden, false, 'Подтверждённая сервером приостановка скрыта');
app.setConnectionState('offline', 'Сессия завершена');
assert.equal(text.textContent, 'Сессия завершена');
console.log('[OK] Короткое переподключение без предупреждения; длительный сбой, offline и сессия видны');
