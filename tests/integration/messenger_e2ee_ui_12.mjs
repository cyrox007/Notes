import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';

const view = readFileSync('modules/messenger/views/index.php', 'utf8');
const logic = readFileSync('modules/messenger/views/e2ee.js', 'utf8');
const legacy = readFileSync('modules/messenger/views/script.js', 'utf8');
const assets = readFileSync('modules/messenger/controllers/MessengerE2eeAssetsController.php','utf8');
const routes = readFileSync('modules/messenger/MessengerRuntimeProvider.php','utf8');
const css = readFileSync('modules/messenger/views/e2ee.css','utf8');

test('главный Messenger действительно подключает отдельную защищённую вкладку', () => {
    for (const name of ['chat-e2ee-button','messenger-e2ee-panel','messenger-e2ee-status',
        'messenger-e2ee-activate','messenger-e2ee-device-list','messenger-e2ee-input',
        'messenger-e2ee-send','messenger-e2ee-file']) {
        assert.ok(view.includes('id="'+name+'"'), 'Нет элемента '+name);
    }
    assert.ok(view.includes("'e2ee.css'"));
    assert.ok(view.includes("'e2ee.js'"));
    assert.ok(css.includes('[data-e2ee-active="true"] .messenger-composer'));
});

test('клиентская интеграция корректна синтаксически и не отправляет сообщения старым маршрутом',()=>{
    const source=logic.replace(/^\{literal\}\s*/, '').replace(/\s*\{\/literal\}\s*$/, '');
    assert.doesNotThrow(()=>new Script(source,{filename:'e2ee.js'}));
    for(const name of ['createE2eeConversation','sendFile','receiveFile','publishInitialKeys',
        'createLocalDeviceTrust','state.pairing.confirmIndependentDevice','app.openDialog']) {
        assert.ok(logic.includes(name), 'Не подключён '+name);
    }
    assert.equal(logic.includes('MessangerSocket:message_send'),false,
        'Нельзя отправлять E2EE-текст старым серверным обработчиком');
    assert.equal(logic.includes('MediaSocket:send'),false,
        'Нельзя отправлять E2EE-файл старой функцией загрузки');
    assert.ok(logic.includes('event.stopImmediatePropagation()'),
        'Старые обработчики вложений должны блокироваться в защищённом режиме');
});

test('сервер отдаёт только локальные проверенные модули и WebAssembly',()=>{
    assert.ok(assets.includes('in_array($filename, $allowlist, true)'));
    assert.ok(assets.includes('dirname($path) !== $folder'));
    assert.ok(assets.includes('application/wasm'));
    assert.ok(assets.includes('Cross-Origin-Resource-Policy: same-origin'));
    assert.ok(routes.includes("'/e2ee/client/{str:filename}'"));
    assert.ok(routes.includes("'/e2ee/sdk/{str:filename}'"));
    assert.ok(routes.includes("'/e2ee/sdk/pkg/{str:filename}'"));
});

test('интерфейс не может переключать существующий обычный чат в защищённый',()=>{
    assert.ok(logic.includes("selected.encryption_mode === 'e2ee'"));
    assert.ok(logic.includes("createEncryptedDialog(selected.partner.uid)"));
    assert.ok(logic.includes('await state.sdk.initAsync()'),
        'Нельзя создавать необратимый E2EE-диалог при недоступном криптодвижке');
    assert.ok(logic.includes("app.pendingOpenUid = dialogUid"));
    assert.ok(logic.includes("app.currentDialog?.encryption_mode === 'e2ee'"));
    assert.ok(logic.includes("&& app.currentDialog?.encryption_mode === 'e2ee'"));
    assert.ok(routes.includes("'/e2ee/dialogs'"));
    assert.ok(routes.includes('CSRFMiddleware::class'));
});

test('обычный редактор отключён в E2EE даже при ошибке загрузки клиентского движка',()=>{
    const source=legacy.replace(/^\{literal\}\s*/, '').replace(/\s*\{\/literal\}\s*$/, '');
    assert.doesNotThrow(()=>new Script(source,{filename:'messenger-script.js'}));
    assert.ok(legacy.includes("this.currentDialog.encryption_mode !== 'server_encrypted'"));
    assert.ok(legacy.includes('this.root.dataset.dialogMode'));
    assert.ok(css.includes('[data-dialog-mode="e2ee"] .messenger-composer'));
    assert.ok(css.includes('[data-dialog-mode="unknown"] .messenger-composer'));
});

test('ключи E2EE открываются только под межвкладочной блокировкой',()=>{
    assert.ok(assets.includes("'matrix-device-session.mjs'"),
        'Сервер должен разрешать локальный модуль блокировки');
    assert.ok(logic.includes("import(clientModule('matrix-device-session.mjs'))"));
    const acquire=logic.indexOf('state.session.acquireDeviceSession(userUid, deviceId)');
    const open=logic.indexOf('state.bootstrap.openClientMachine(');
    assert.ok(acquire>0 && open>acquire,
        'Исключительную блокировку нужно получить до открытия OlmMachine');
    assert.ok(logic.includes('if (state.loading) return;'),
        'Нельзя освобождать устройство во время инициализации');
    assert.ok(logic.includes('await state.machine?.close?.()'),
        'Криптодвижок должен закрываться до разблокировки');
    assert.ok(logic.includes('state.lease?.release()'));
    assert.ok(logic.includes('lease?.release()'));
});

test('второе E2EE-устройство подтверждается сравнением кода, а не одним нажатием',()=>{
    assert.ok(view.includes('id="messenger-e2ee-own-code"'));
    assert.ok(view.includes('id="messenger-e2ee-own-id"'));
    assert.ok(logic.includes("import(clientModule('matrix-device-pairing.mjs'))"));
    assert.ok(assets.includes("'matrix-device-pairing.mjs'"));
    assert.ok(logic.includes('state.pairing.deviceComparisonCode('));
    assert.ok(logic.includes('state.pairing.confirmIndependentDevice('));
    assert.ok(logic.includes('fetchDevices: uid => state.transport.getDevices(uid)'));
    const deviceSection = logic.split('async function refreshDevices()')[1]
        .split('function appendMessage(')[0];
    assert.equal(deviceSection.includes('window.confirm('),false,
        'Нельзя подтверждать чужое устройство одним согласием без ввода кода');
    assert.ok(logic.includes('opened.machine.identityKeys'),
        'Код своего устройства должен исходить из локального криптодвижка');
    assert.ok(logic.includes('trust.pin(userUid, deviceId, ownSigning'),
        'Свой ключ подтверждается из локальной машины, а не из серверного ответа');
});

test('старые E2EE-ключи экспортируются и импортируются только в открытой OlmMachine',()=>{
    for(const name of ['messenger-e2ee-archive-password','messenger-e2ee-archive-repeat',
        'messenger-e2ee-archive-file','messenger-e2ee-archive-save',
        'messenger-e2ee-archive-restore']) {
        assert.ok(view.includes('id="'+name+'"'),'Не найден элемент '+name);
    }
    assert.ok(assets.includes("'matrix-room-key-archive.mjs'"));
    assert.ok(logic.includes("import(clientModule('matrix-room-key-archive.mjs'))"));
    assert.ok(logic.includes('state.archiveApi.createEncryptedRoomKeyArchive('));
    assert.ok(logic.includes('state.archiveApi.restoreEncryptedRoomKeyArchive('));
    assert.ok(logic.includes('file.size > 12 * 1024 * 1024'));
    assert.ok(logic.includes('clearArchiveSecrets()'));
    assert.ok(logic.includes('state.cursor.delete(dialogUid)'));
    assert.ok(logic.includes('void refreshMessages()'));
    assert.equal(logic.includes('uploadRoomKeyArchive('),false,
        'Архив с секретами не должен отправляться на сервер Notes');
});
