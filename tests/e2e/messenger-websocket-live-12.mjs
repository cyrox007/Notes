import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { createRequire } from 'node:module';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18093')
    + (process.env.E2E_BASE_PATH || '/workspace');
const marker = (await readFile('/tmp/notes-messenger-12-marker', 'utf8')).trim();
assert.ok(marker.startsWith('Messenger-12-'), 'Нет маркера исходной браузерной проверки');
const wsText = marker + '-websocket';
const browser = await chromium.launch({ headless: true });
const contexts = await Promise.all([0, 1, 2].map(() => browser.newContext()));
const [alice, bob, outsider] = await Promise.all(contexts.map(context => context.newPage()));

async function enterMessenger(page, username) {
    await page.goto(base + '/auth/login/');
    await page.locator('#login').fill(username);
    await page.locator('#password').fill(process.env.E2E_PASSWORD);
    await Promise.all([
        page.waitForURL(url => !url.pathname.includes('/auth/login')),
        page.getByRole('button', { name: 'Войти', exact: true }).click()
    ]);
    await page.goto(base + '/messenger/');
    await page.locator('#messenger-app').waitFor();
    await page.waitForFunction(() => document.querySelector('#new-chat-button')?.disabled === false);
}

async function openAuditSocket(page) {
    await page.evaluate(() => {
        const root = document.querySelector('#messenger-app');
        const url = root?.dataset.socketUrl || '';
        const ticket = root?.dataset.socketTicket || '';
        if (!url.startsWith('ws://') || ticket.length < 20) {
            throw new Error('Браузер не получил адрес WebSocket и подписанный билет');
        }
        const socket = new WebSocket(url + (url.includes('?') ? '&' : '?')
            + 'ticket=' + encodeURIComponent(ticket));
        const audit = { socket, events: [], closed: false };
        window.__wsAudit = audit;
        socket.addEventListener('message', event => {
            const data = JSON.parse(event.data);
            audit.events.push(data);
            if (data.action === 'Ping' && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ action: 'PingSocket:index', data: { ping: 'Pong' } }));
            }
        });
        socket.addEventListener('close', () => { audit.closed = true; });
        socket.addEventListener('error', () => { audit.connectionError = true; });
    });
    await page.waitForFunction(() => window.__wsAudit?.events
        .some(event => event.action === 'Authorized'), null, { timeout: 20000 });
    assert.equal(await page.evaluate(() => window.__wsAudit.connectionError || false), false,
        'Веб-сокет сообщил об ошибке соединения');
}

async function sendAndWait(page, action, data, expectedAction) {
    const before = await page.evaluate(({ action, data }) => {
        const audit = window.__wsAudit;
        if (!audit || audit.socket.readyState !== WebSocket.OPEN) {
            throw new Error('WebSocket не подключён');
        }
        const index = audit.events.length;
        audit.socket.send(JSON.stringify({ action, data }));
        return index;
    }, { action, data });
    const event = await page.waitForFunction(({ index, expectedAction }) =>
        window.__wsAudit?.events.slice(index).find(item => item.action === expectedAction) || null,
    { index: before, expectedAction }, { timeout: 20000 });
    return event.jsonValue();
}

async function receivedAfter(page, offset, action) {
    const event = await page.waitForFunction(({ offset, action }) =>
        window.__wsAudit?.events.slice(offset).find(item => item.action === action) || null,
    { offset, action }, { timeout: 20000 });
    return event.jsonValue();
}

try {
    await Promise.all([
        enterMessenger(alice, process.env.E2E_USER),
        enterMessenger(bob, process.env.E2E_TARGET_USER),
        enterMessenger(outsider, process.env.E2E_OUTSIDER_USER)
    ]);
    await Promise.all([openAuditSocket(alice), openAuditSocket(bob), openAuditSocket(outsider)]);
    console.log('[OK] Три независимых браузера авторизованы на настоящем WebSocket');

    const aliceDialogs = await sendAndWait(alice, 'MessangerSocket:get_dialogs', {}, 'get_dialogs');
    assert.equal(aliceDialogs.dialogs.length, 1, 'У отправителя отсутствует проверочный диалог');
    const dialogUid = aliceDialogs.dialogs[0].uid;
    const outsiderDialogs = await sendAndWait(outsider, 'MessangerSocket:get_dialogs', {}, 'get_dialogs');
    assert.equal(outsiderDialogs.dialogs.length, 0, 'Постороннему виден чужой диалог');

    const initial = await sendAndWait(alice, 'MessangerSocket:load',
        { dialog_uid: dialogUid }, 'get_messages');
    assert.equal(initial.messages.length, 3, 'WebSocket не прочитал исходную сохранённую историю');
    const file = initial.messages.find(message => message.message_type === 'file');
    const attachmentUid = file?.meta_data?.attachment_uid;
    assert.match(attachmentUid || '', /^[0-9a-f-]{36}$/i,
        'Вложение отсутствует в истории Messenger');

    const outsiderRead = await sendAndWait(outsider, 'MessangerSocket:load',
        { dialog_uid: dialogUid }, 'error');
    assert.equal(outsiderRead.code, 'forbidden', 'Постороннему разрешено читать чужую историю');
    const outsiderWrite = await sendAndWait(outsider, 'MessangerSocket:message_send',
        { dialog_uid: dialogUid, message: 'Доступ постороннего' }, 'error');
    assert.equal(outsiderWrite.code, 'forbidden', 'Постороннему разрешена отправка в чужой диалог');
    const outsiderMedia = await sendAndWait(outsider, 'MediaSocket:send',
        { attachment_uid: attachmentUid, caption: 'Чужое вложение' }, 'error');
    assert.equal(outsiderMedia.code, 'forbidden', 'Постороннему разрешено отправить чужое вложение');

    const mediaStatus = await outsider.evaluate(async uid => {
        const response = await fetch('/workspace/messenger/media/' + encodeURIComponent(uid),
            { credentials: 'same-origin', cache: 'no-store' });
        return response.status;
    }, attachmentUid);
    assert.equal(mediaStatus, 404, 'Посторонний может скачать вложение');
    console.log('[OK] Запрет чтения, записи и доступа к вложению проверен через реальный транспорт');

    const bobOffset = await bob.evaluate(() => window.__wsAudit.events.length);
    const confirmed = await sendAndWait(alice, 'MessangerSocket:message_send',
        { dialog_uid: dialogUid, message: wsText }, 'send_message');
    const delivered = await receivedAfter(bob, bobOffset, 'send_message');
    assert.equal(confirmed.message.message, wsText, 'Отправителю вернулось неверное сообщение');
    assert.equal(delivered.message.uid, confirmed.message.uid,
        'Получатель не получил ту же сохранённую запись');
    console.log('[OK] Сообщение передано через WebSocket и получено обоими участниками');

    await alice.evaluate(() => window.__wsAudit.socket.close(1000, 'Контрольное отключение'));
    await alice.waitForFunction(() => window.__wsAudit?.closed === true, null, { timeout: 15000 });
    await openAuditSocket(alice);
    const restored = await sendAndWait(alice, 'MessangerSocket:load',
        { dialog_uid: dialogUid }, 'get_messages');
    assert.equal(restored.messages.filter(message => message.message === wsText).length, 1,
        'После повторного соединения появилось дублированное сообщение');
    const bobRestored = await sendAndWait(bob, 'MessangerSocket:load',
        { dialog_uid: dialogUid }, 'get_messages');
    assert.equal(bobRestored.messages.filter(message => message.message === wsText).length, 1,
        'Получатель не восстановил сообщение из базы');
    assert.equal(restored.messages.length, 4, 'После повторного соединения потеряна история');

    await writeFile('/tmp/notes-messenger-12-websocket-marker', marker, { mode: 0o600 });
    console.log('[OK] Переподключение, история без дубликатов и защита чужого диалога подтверждены');
} finally {
    await Promise.all(contexts.map(context => context.close()));
    await browser.close();
}
