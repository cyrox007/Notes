import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const browser = await chromium.launch({ headless: true,
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
try {
    const page = await browser.newPage();
    let starts = 0, steps = 0, confirmations = 0;
    page.on('dialog', async dialog => { confirmations++; await dialog.accept(); });
    await page.route('http://updater.test/**', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/start') {
            starts++;
            await new Promise(resolve => setTimeout(resolve, 100));
            return route.fulfill({ json: { success: true, result: { status: 'in_progress',
                transaction_id: 'browser-test-transaction', continuation_token: 'test-only',
                continuation_url: '/step' } } });
        }
        if (pathname === '/step') {
            steps++;
            return route.fulfill({ json: { success: true, result: { status: 'committed', progress: 100 } } });
        }
        return route.fulfill({ contentType: 'text/html', body: `<main class="admin-page">
            <div data-update-progress hidden><strong data-update-progress-title></strong>
            <p data-update-progress-message></p><progress data-update-progress-bar></progress></div>
            <form data-update-install-form data-update-web-mode="true" data-update-web-confirm="Install?"
                data-update-start-url="/start" data-update-step-url="/step">
                <button type="submit">Install</button></form></main>` });
    });
    await page.goto('http://updater.test/');
    await page.evaluate(() => {
        window.wspace = { page: { register(_key, initialize) {
            const scope = { listen: (target, name, callback) => target.addEventListener(name, callback),
                onInit: callback => callback(), setTimeout: () => {}, refresh: () => {} };
            initialize(scope); initialize(scope);
        } } };
    });
    await page.addScriptTag({ path: path.join(root, 'assets/js/update-web-runner.js') });
    await page.addScriptTag({ path: path.join(root, 'modules/admin/assets/admin-updates.js') });
    await page.evaluate(() => {
        const form = document.querySelector('form');
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await page.waitForFunction(() => document.querySelector('[data-update-progress-title]').textContent === 'Установка завершена');
    assert.equal(starts, 1); assert.equal(steps, 1); assert.equal(confirmations, 1);
    await page.evaluate(async () => {
        const form = document.querySelector('form');
        await Promise.all([window.wspace.updateWebRunner.run(form), window.wspace.updateWebRunner.run(form)]);
    });
    assert.equal(starts, 2); assert.equal(steps, 2);
    console.log('PASS: duplicate handlers and submissions start once; concurrent runners share one transaction');
} finally { await browser.close(); }
