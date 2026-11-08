import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { writeFile } from 'node:fs/promises';

const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18093') + (process.env.E2E_BASE_PATH || '/workspace');
const marker = 'Messenger-12-' + Date.now();
const browser = await chromium.launch({ headless: true });
const contexts = [await browser.newContext(), await browser.newContext()];
const pages = await Promise.all(contexts.map(context => context.newPage()));
const errors = [];

async function signIn(page, username) {
    page.on('pageerror', error => { errors.push(error.message); console.log('[Ошибка страницы]', error.message); });
    page.on('console', message => { if (message.type() === 'warning' || message.type() === 'error') console.log('[Консоль]', message.type(), message.text().slice(0, 500)); });
    page.on('response', async response => {
        if (!response.url().includes('/messenger/realtime/action')) return;
        const action = new URLSearchParams(response.request().postData() || '').get('action') || '?';
        let result = {};
        try { result = await response.json(); } catch (_) { /* Необязательный ответ. */ }
        const codes = (result.events || []).map(event => event.action || '?');
        console.log('Действие Messenger:', action, 'HTTP', response.status(),
            'результат', result.status || '?', 'события', codes.join(','));
    });
    await page.goto(base + '/auth/login/');
    await page.locator('#login').fill(username);
    await page.locator('#password').fill(process.env.E2E_PASSWORD);
    await Promise.all([
        page.waitForURL(url => !url.pathname.includes('/auth/login')),
        page.getByRole('button', { name: 'Войти', exact: true }).click(),
    ]);
    await page.goto(base + '/messenger/');
    await page.locator('#messenger-app').waitFor();
    await page.waitForFunction(() => document.querySelector('#new-chat-button')?.disabled === false);
}

async function displayed(page, text) {
    await page.locator('#message-list').getByText(text, { exact: false })
        .first().waitFor({ timeout: 30000 });
}

try {
    const [alice, bob] = pages;
    await Promise.all([
        signIn(alice, process.env.E2E_USER),
        signIn(bob, process.env.E2E_TARGET_USER),
    ]);
    await alice.locator('#new-chat-button').click();
    await alice.locator('#new-chat-dialog .messenger-contact')
        .filter({ hasText: '@' + process.env.E2E_TARGET_USER })
        .locator('input[type="checkbox"]').check();
    await alice.locator('#create-chat-button').click();
    await alice.waitForFunction(() => document.querySelector('#chat-active')?.hidden === false, null, { timeout: 30000 });
    await alice.waitForFunction(() => document.querySelector('#message-list')?.getAttribute('aria-busy') === 'false', null, { timeout: 30000 });

    const first = marker + '-отправитель';
    await alice.locator('#message-input').fill(first);
    assert.equal(await alice.locator('#message-input').inputValue(), first, 'Набранный текст исчез до нажатия кнопки');
    console.log('[Перед отправкой]', await alice.evaluate(() => ({
        visible: !document.querySelector('#chat-active')?.hidden,
        draftLength: document.querySelector('#message-input')?.value.length,
        sendDisabled: document.querySelector('#message-send-button')?.disabled,
        selectedChat: document.querySelector('.messenger-dialog-item--active')?.textContent?.trim().slice(0, 80)
    })));
    await alice.locator('#message-send-button').click();
    await alice.waitForTimeout(800);
    console.log('[После отправки]', await alice.evaluate(() => ({
        draftLength: document.querySelector('#message-input')?.value.length,
        sendDisabled: document.querySelector('#message-send-button')?.disabled,
        html: document.querySelector('#message-list')?.textContent?.slice(0, 150),
        notifications: document.body.innerText.slice(-500)
    })));
    if (errors.length > 0) console.log('[Ошибки]', errors);
    await displayed(alice, first);
    assert.equal(await alice.locator('#message-input').inputValue(), '',
        'После подтверждённого сохранения черновик не очищен');
    console.log('[OK] Первый участник получил подтверждение сохранённого сообщения');

    await bob.locator('.messenger-dialog-item').first().waitFor({ timeout: 30000 });
    await bob.locator('.messenger-dialog-item').first().click();
    await bob.waitForFunction(() => document.querySelector('#message-list')?.getAttribute('aria-busy') === 'false', null, { timeout: 30000 });
    await displayed(bob, first);
    console.log('[OK] Второй участник получил первое сообщение');

    const second = marker + '-адресат';
    await bob.locator('#message-input').fill(second);
    await bob.locator('#message-send-button').click();
    await displayed(bob, second);
    await displayed(alice, second);
    console.log('[OK] Двусторонний обмен подтверждён');

    await Promise.all([alice.reload(), bob.reload()]);
    await displayed(alice, first);
    await displayed(alice, second);
    await displayed(bob, first);
    await displayed(bob, second);
    assert.deepEqual(errors, [], 'Ошибка JavaScript в настоящем браузере');

    await writeFile('/tmp/notes-messenger-12-marker', marker, { mode: 0o600 });
    console.log('[OK] Оба сообщения сохранились после повторного открытия страницы');
} finally {
    await Promise.all(contexts.map(context => context.close()));
    await browser.close();
}
