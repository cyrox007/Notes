import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';

const view = readFileSync('modules/messenger/views/index.php', 'utf8');
const logic = readFileSync('modules/messenger/views/e2ee.js', 'utf8');
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
        'createLocalDeviceTrust','state.trust.pin','app.openDialog']) {
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
