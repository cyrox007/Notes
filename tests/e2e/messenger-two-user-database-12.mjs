import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18093')
    + (process.env.E2E_BASE_PATH || '/workspace');
const usernameA = process.env.E2E_USER;
const usernameB = process.env.E2E_TARGET_USER;
const password = process.env.E2E_PASSWORD;

if (!usernameA || !usernameB || !password) {
    throw new Error('Не заданы учётные записи для испытания Messenger');
}

const browser = await chromium.launch({ headless: true });
const contexts = [];
const timestamp = Date.now();
const observed = [];

async function openAccount(username) {
    const context = await browser.newContext();
    contexts.push(context);
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.setDefaultNavigationTimeout(30000);
    page.on('pageerror', (error) => observed.push(`Ошибка страницы: ${error.message}`));
    page.on('response', async (response) => {
        if (!response.url().includes('/messenger/realtime/action')) return;
        try {
            const data = await response.json();
            if (data?.status === 'ok') return;
            observed.push(`Отказ HTTP ${response.status()}: ${data?.message || 'без причины'}`);
        } catch {
            // Незавершённый фоновый запрос может быть отменён при переходе страницы.
            // Это не означает сбоя отправки и не должно давать ложный провал.
        }
    });

    await page.goto(base + '/auth/login/');
    await page.locator('#login').fill(username);
    await page.locator('#password').fill(password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.includes('/auth/login')),
        page.getByRole('button', { name: 'Войти', exact: true }).click(),
    ]);
    await page.goto(base + '/messenger/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.locator('#messenger-app').waitFor();
    const uid = await page.locator('#messenger-app').getAttribute('data-user-uid');
    assert.ok(uid, 'Не получен идентификатор пользователя Messenger');
    return { page, uid };
}

async function action(page, actionName, data) {
    const result = await page.evaluate(async ({ actionName, data }) => {
        const address = window.wspace?.path?.('/messenger/realtime/action')
            || '/messenger/realtime/action';
        const token = window.wspace?.security?.getCSRFToken?.() || '';
        const response = await fetch(address, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': token,
            },
            body: new URLSearchParams({
                action: actionName,
                data: JSON.stringify(data),
            }),
        });
        return { http: response.status, body: await response.json() };
    }, { actionName, data });
    assert.equal(result.http, 200, `HTTP для ${actionName}: ${JSON.stringify(result)}`);
    assert.equal(result.body.status, 'ok', `Отказ ${actionName}: ${JSON.stringify(result.body)}`);
    return result.body.events || [];
}

function verifyDatabase(dialogUid, messages) {
    const output = execFileSync('php', [
        'tests/e2e/messenger-two-user-db-12.php',
        dialogUid,
        JSON.stringify(messages),
    ], { encoding: 'utf8', timeout: 15000 });
    process.stdout.write(output);
}

try {
    // Запускаем независимые сеансы последовательно, чтобы длительные запросы
    // одного клиента не мешали первоначальной загрузке второго.
    const a = await openAccount(usernameA);
    const b = await openAccount(usernameB);
    assert.notEqual(a.uid, b.uid);

    const created = await action(a.page, 'MessangerSocket:create_dialog', {
        type: 'private',
        participants: [b.uid],
    });
    const dialogUid = created.find((event) => event.action === 'dialog_created')?.dialog?.uid;
    assert.ok(dialogUid, 'Сервер не подтвердил создание личного диалога');

    const textFromApi = `Проверка записи через HTTP ${timestamp}`;
    const submitted = await action(a.page, 'MessangerSocket:message_send', {
        dialog_uid: dialogUid,
        message: textFromApi,
    });
    const stored = submitted.find((event) => event.action === 'send_message')?.message;
    assert.ok(stored?.uid, 'Не получено подтверждение сохранённого сообщения');
    assert.equal(stored.message, textFromApi);
    verifyDatabase(dialogUid, [{ uid: stored.uid, text: textFromApi, sender: a.uid }]);
    console.log('[OK] HTTP: подтверждение, запись в MySQL и обратное чтение шифротекста');

    for (const user of [a, b]) {
        await user.page.goto(base + '/messenger/?dialog=' + encodeURIComponent(dialogUid), {
            waitUntil: 'domcontentloaded',
            timeout: 30000,
        });
        await user.page.locator('#chat-active').waitFor({ state: 'visible', timeout: 20000 });
        await user.page.locator('#message-list').getByText(textFromApi, { exact: false })
            .waitFor({ timeout: 20000 });
    }
    console.log('[OK] Сообщение отображается у двух независимых пользователей');

    const textFromInterface = `Проверка редактора ${timestamp}`;
    await a.page.locator('#message-input').fill(textFromInterface);
    await a.page.locator('#message-send-button').click();
    await a.page.waitForFunction(() => document.querySelector('#message-input')?.value === '', null, {
        timeout: 20000,
    });
    await Promise.all([a, b].map((user) =>
        user.page.locator('#message-list').getByText(textFromInterface, { exact: false })
            .waitFor({ timeout: 20000 })
    ));

    const listing = await action(b.page, 'MessangerSocket:load', { dialog_uid: dialogUid });
    const loaded = listing.find((event) => event.action === 'get_messages')?.messages || [];
    const second = loaded.find((message) => message.message === textFromInterface);
    assert.ok(second?.uid, 'Второе сообщение отсутствует в истории получателя');
    verifyDatabase(dialogUid, [
        { uid: stored.uid, text: textFromApi, sender: a.uid },
        { uid: second.uid, text: textFromInterface, sender: a.uid },
    ]);
    console.log('[OK] Интерфейс: отправка, получение без перезагрузки, два сообщения в MySQL');

    // Перезапускаем страницы по очереди: параллельные Long Poll способны
    // временно занять HTTP-процессы и задержать одновременные переходы.
    for (const user of [a, b]) {
        await user.page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
        await user.page.locator('#chat-active').waitFor({ state: 'visible', timeout: 20000 });
        for (const message of [textFromApi, textFromInterface]) {
            await user.page.locator('#message-list').getByText(message, { exact: false })
                .waitFor({ timeout: 20000 });
        }
    }
    console.log('[OK] После повторной загрузки история обоих пользователей сохранилась');

    const read = await action(b.page, 'MessangerSocket:mark_read', {
        dialog_uid: dialogUid,
        message_uid: second.uid,
    });
    assert.ok(read.some((event) => event.action === 'read_update'), 'Нет подтверждения прочтения');
    verifyDatabase(dialogUid, [
        { uid: stored.uid, text: textFromApi, sender: a.uid },
        { uid: second.uid, text: textFromInterface, sender: a.uid },
    ]);
    assert.deepEqual(observed, [], 'Во время испытаний обнаружены ошибки: ' + observed.join('; '));
    console.log('[OK] Прочтение не нарушает сохранность; дубликатов нет');
} catch (error) {
    if (observed.length) {
        console.error('Диагностика браузера и транспорта:', observed.join('; '));
    }
    throw error;
} finally {
    await Promise.all(contexts.map((context) => context.close()));
    await browser.close();
}
