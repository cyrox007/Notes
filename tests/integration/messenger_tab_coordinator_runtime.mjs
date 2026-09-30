import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const source = fs.readFileSync(
    path.join(root, 'assets', 'js', 'messenger-tab-coordinator.js'),
    'utf8'
);

function fail(message) {
    console.error('[FAIL] ' + message);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) fail(message);
}

const context = {
    console,
    Date,
    Math,
    JSON,
    setTimeout,
    clearTimeout,
    globalThis: null,
    window: {
        addEventListener() {},
        removeEventListener() {},
    },
    document: {
        visibilityState: 'visible',
        addEventListener() {},
        removeEventListener() {},
    },
};
context.globalThis = context;
vm.createContext(context);
vm.runInContext(source, context, { filename: 'messenger-tab-coordinator.js' });

const Coordinator = context.wspaceMessengerTabCoordinator?.MessengerTabCoordinator;
assert(typeof Coordinator === 'function', 'класс координатора не экспортирован');

const values = new Map();
const storage = {
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); },
};

const channels = new Map();
function channelFactory(name) {
    const peers = channels.get(name) || new Set();
    const channel = {
        onmessage: null,
        postMessage(data) {
            peers.forEach((peer) => {
                if (peer === channel) return;
                peer.onmessage?.({ data });
            });
        },
        close() {
            peers.delete(channel);
        },
    };
    peers.add(channel);
    channels.set(name, peers);
    return channel;
}

let nextTimer = 1;
const timers = new Map();
function setTimer(callback, delay) {
    const id = nextTimer++;
    timers.set(id, { callback, delay });
    return id;
}
function clearTimer(id) {
    timers.delete(id);
}

let now = 1000;
const receivedA = [];
const receivedB = [];
const leadershipA = [];
const leadershipB = [];

const common = {
    storage,
    channelFactory,
    now: () => now,
    setTimer,
    clearTimer,
    isVisible: () => true,
    eventWindow: context.window,
    eventDocument: context.document,
    leaseTtlMs: 12000,
    renewMs: 4000,
};

const a = new Coordinator({
    ...common,
    tabId: 'tab-a',
    onLeadershipChange: (leader) => leadershipA.push(leader),
    onMessage: (payload) => receivedA.push(payload),
});
const b = new Coordinator({
    ...common,
    tabId: 'tab-b',
    onLeadershipChange: (leader) => leadershipB.push(leader),
    onMessage: (payload) => receivedB.push(payload),
});

a.start();
b.start();

assert(a.isLeader() === true, 'первая вкладка не получила transport lease');
assert(b.isLeader() === false, 'вторая вкладка одновременно стала лидером');
assert(
    JSON.parse(storage.getItem('wspace:messenger-global-transport-owner')).owner === 'tab-a',
    'lease не принадлежит первой вкладке'
);

a.publish({ type: 'dialogs', dialogs: [{ uid: 'dialog-1', unread_count: 2 }] });
assert(receivedB.length === 1, 'вторая вкладка не получила состояние от лидера');
assert(receivedB[0].dialogs[0].unread_count === 2, 'межвкладочное состояние повреждено');

a.stop();
b.refresh();

assert(b.isLeader() === true, 'вторая вкладка не приняла transport после закрытия лидера');
assert(
    JSON.parse(storage.getItem('wspace:messenger-global-transport-owner')).owner === 'tab-b',
    'lease не перешёл новой вкладке'
);

b.publish({ type: 'dialogs', dialogs: [{ uid: 'dialog-1', unread_count: 3 }] });
assert(receivedA.length === 0, 'остановленная вкладка продолжает получать broadcast');

b.stop();

const hiddenLeadership = [];
const hidden = new Coordinator({
    ...common,
    tabId: 'tab-hidden',
    isVisible: () => false,
    onLeadershipChange: (leader) => hiddenLeadership.push(leader),
});
hidden.start();
assert(hidden.isLeader() === true, 'скрытая единственная вкладка не сохранила Messenger transport');
hidden.handleVisibility();
assert(hidden.isLeader() === true, 'visibilitychange ошибочно выключил transport фоновой вкладки');
hidden.stop();

const fallbackLeadership = [];
const fallback = new Coordinator({
    storage: null,
    channelFactory: null,
    tabId: 'fallback',
    setTimer,
    clearTimer,
    isVisible: () => true,
    eventWindow: context.window,
    eventDocument: context.document,
    onLeadershipChange: (leader) => fallbackLeadership.push(leader),
});
fallback.start();
assert(fallback.isLeader() === true, 'без BroadcastChannel/localStorage transport ошибочно отключён');
fallback.stop();

console.log('[OK] Глобальный Messenger transport выбирает одного лидера, сохраняется в фоне и передаёт состояние между вкладками');
