import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const base = (process.env.E2E_ORIGIN || 'http://127.0.0.1:18093') + (process.env.E2E_BASE_PATH || '/workspace');
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
const stamp = Date.now();
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/auth/login/');
    await page.locator('#login').fill(process.env.E2E_USER);
    await page.locator('#password').fill(process.env.E2E_PASSWORD);
    await Promise.all([
        page.waitForURL(url => !url.pathname.includes('/auth/login')),
        page.getByRole('button', { name: 'Войти', exact: true }).click(),
    ]);
    await page.evaluate(() => { window.ajaxDocumentMarker = crypto.randomUUID(); window.ajaxLoads = 0; document.addEventListener('wspace:page-loaded', () => window.ajaxLoads++); });
    const marker = await page.evaluate(() => window.ajaxDocumentMarker);
    async function action(callback) {
        const before = await page.evaluate(() => window.ajaxLoads);
        await callback();
        await page.waitForFunction(before => window.ajaxLoads > before, before);
        assert.equal(await page.evaluate(() => window.ajaxDocumentMarker), marker, 'Action reloaded the browser document');
    }
    async function visit(key) {
        await action(() => page.locator(`.sidebar__menu [data-nav-key="${key}"]`).click());
    }
    await visit('tasks');
    await page.locator('#open-create-task').click();
    await page.locator('#title').fill(`AJAX task ${stamp}`);
    await action(() => page.locator('#create-task-modal button[type="submit"]').click());
    await page.locator('.task-item').filter({ hasText: `AJAX task ${stamp}` }).waitFor();
    await visit('notes');
    await page.locator('.notes__create input[name="notename"]').fill(`AJAX note ${stamp}`);
    await action(() => page.locator('.notes__create button[type="submit"]').click());
    const editor = page.locator('form.note__edit');
    await editor.waitFor();
    await editor.locator('textarea[name="content"]').fill(`AJAX saved content ${stamp}`);
    await editor.getByRole('button', { name: 'Сохранить текст', exact: true }).click();
    await page.waitForFunction(() => document.querySelector('.note-draft-status')?.textContent === 'Все изменения сохранены на сервере');
    assert.equal(await page.evaluate(() => window.ajaxDocumentMarker), marker);
    await visit('calendar');
    await page.locator('[data-calendar-create]').click();
    const calendarEditor = page.locator('[data-calendar-editor]');
    await calendarEditor.waitFor();
    await calendarEditor.locator('[data-field="title"]').fill(`AJAX event ${stamp}`);
    await calendarEditor.locator('[data-calendar-save]').click();
    await calendarEditor.waitFor({ state: 'hidden' });
    await visit('files');
    await page.locator('#btn-create-folder').click();
    await page.locator('#folder-name-input').fill(`AJAX folder ${stamp}`);
    await page.locator('#modal-create-folder .modal-ok').click();
    await page.locator('.file-manager__item').filter({ hasText: `AJAX folder ${stamp}` }).waitFor();
    assert.equal(await page.evaluate(() => window.ajaxDocumentMarker), marker);
    await visit('profile');
    await page.locator('.profile__edit_user-info').click();
    await page.locator('#user-name').fill('AjaxUpdated');
    await action(() => page.locator('form[name="changeProfile"] button[type="submit"]').click());
    await page.getByText('AjaxUpdated', { exact: false }).first().waitFor();
    await visit('admin');
    await action(() => page.locator('.sidebar__utility[href*="/admin/settings"]').click());
    await page.locator('#max_upload_mb').fill('12');
    await action(() => page.getByRole('button', { name: 'Сохранить лимит загрузки', exact: true }).click());
    assert.equal(await page.locator('#max_upload_mb').inputValue(), '12');
    for (const key of ['messenger', 'tasks', 'messenger', 'notes', 'home', 'calendar', 'profile']) await visit(key);
    assert.deepEqual(errors, []);
    console.log('Real application AJAX lifecycle: every module, task/note/event/folder/profile/settings mutations and repeated messenger transitions passed');
} finally {
    await browser.close();
}
