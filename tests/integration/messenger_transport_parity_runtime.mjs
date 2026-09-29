import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');

function fail(message) {
    console.error('[FAIL] ' + message);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) fail(message);
}

const clientFiles = [
    'modules/messenger/views/script.js',
    'modules/messenger/views/activity.js',
    'modules/messenger/views/dialog-actions.js',
    'modules/messenger/views/receipts.js',
    'modules/messenger/views/media.js',
    'modules/messenger/views/forwarding.js',
    'modules/messenger/views/reactions.js',
    'modules/messenger/views/voice.js',
    'modules/messenger/views/group.js',
    'modules/messenger/views/search.js',
    'modules/messenger/views/storage-files.js',
];

const serverSource = fs.readFileSync(
    path.join(root, 'modules/messenger/socket/NativeMessengerServer.php'),
    'utf8'
);
const controllerSource = fs.readFileSync(
    path.join(root, 'modules/messenger/controllers/MessengerRealtimeController.php'),
    'utf8'
);
const globalSource = fs.readFileSync(
    path.join(root, 'assets/js/messenger-global-notifications.js'),
    'utf8'
);

const routeBlockMatch = serverSource.match(
    /private const ALLOWED_ROUTES = \[(.*?)\n\s*\];/s
);
assert(routeBlockMatch, 'не найден единый allow-list realtime действий Messenger');
const routeBlock = routeBlockMatch[1];

const actions = new Set();
for (const file of clientFiles) {
    const source = fs.readFileSync(path.join(root, file), 'utf8');

    if (file !== 'modules/messenger/views/script.js') {
        assert(
            !source.includes('.socket.send('),
            file + ' обходит единый sendEvent и становится WebSocket-only'
        );
        assert(
            !source.includes('WebSocket.OPEN'),
            file + ' напрямую зависит от WebSocket вместо транспортной границы'
        );
    }

    for (const match of source.matchAll(/['"]([A-Za-z][A-Za-z0-9]*Socket:[a-z_]+)['"]/g)) {
        actions.add(match[1]);
    }
}

assert(actions.size >= 20, 'из клиентских модулей извлечено подозрительно мало realtime-действий');

for (const action of [...actions].sort()) {
    const [className, methodName] = action.split(':');
    const classMarker = "'" + className + "'";
    const methodMarker = "'" + methodName + "'";
    const classPosition = routeBlock.indexOf(classMarker);
    assert(classPosition >= 0, 'класс ' + className + ' отсутствует в общем allow-list');

    const nextClass = routeBlock.indexOf("],", classPosition);
    const classSlice = routeBlock.slice(
        classPosition,
        nextClass >= 0 ? nextClass + 2 : routeBlock.length
    );
    assert(
        classSlice.includes(methodMarker),
        action + ' используется клиентом, но недоступно общему dispatcher'
    );
}

assert(
    controllerSource.includes('dispatchTransportMessage($connection, $message, $connections, \'long_poll\')'),
    'HTTP action не использует тот же dispatcher, что WebSocket'
);
assert(
    controllerSource.includes("['MessangerSocket:get_dialogs', []]")
        && controllerSource.includes("['DialogStateSocket:list', []]")
        && controllerSource.includes("['ReceiptSocket:list', ['dialog_uid' => $dialogUid]]"),
    'Long Poll snapshot не восстанавливает диалоги, состояния и delivery/read receipts'
);
assert(
    globalSource.includes('unread_count')
        && globalSource.includes('showDialogUpdate(dialog)')
        && globalSource.includes('/messenger/realtime/poll'),
    'глобальный Long Poll не закрепляет уведомления и счётчик непрочитанных'
);

console.log(
    '[OK] Все клиентские realtime-действия Messenger доступны через общий Long Poll/WebSocket dispatcher: '
    + actions.size
);
