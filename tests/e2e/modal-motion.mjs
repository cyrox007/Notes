import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');

const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
try {
    for (const reducedMotion of ['no-preference', 'reduce']) {
        const page = await browser.newPage({ reducedMotion });
        await page.setContent('<button id="opener">Открыть</button><dialog id="dialog"><button>Закрыть</button></dialog><div id="custom" hidden><div class="calendar-editor__panel">Форма</div></div>');
        await page.addScriptTag({ path: resolve('assets/js/modal-motion.js') });
        await page.evaluate(() => {
            window.closedEvents = 0;
            const dialog = document.querySelector('#dialog');
            dialog.addEventListener('close', () => { window.closedEvents++; });
            document.querySelector('#opener').focus();
            window.wspace.modalMotion.openDialog(dialog);
            window.wspace.modalMotion.closeDialog(dialog);
            window.wspace.modalMotion.closeDialog(dialog);
        });
        assert.equal(await page.locator('#dialog').evaluate(element => element.open), reducedMotion !== 'reduce');
        await page.waitForFunction(() => !document.querySelector('#dialog').open);
        await page.waitForFunction(() => window.closedEvents === 1);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'opener');

        await page.evaluate(() => {
            const element = document.querySelector('#custom');
            window.cleanupCount = 0;
            window.wspace.modalMotion.open(element);
            window.wspace.modalMotion.close(element, () => { element.hidden = true; window.cleanupCount++; });
            window.wspace.modalMotion.open(element);
        });
        await page.waitForTimeout(300);
        assert.equal(await page.locator('#custom').evaluate(element => element.hidden || element.inert), false);
        assert.equal(await page.evaluate(() => window.cleanupCount), reducedMotion === 'reduce' ? 1 : 0);

        await page.evaluate(() => window.wspace.modalMotion.openDialog(document.querySelector('#dialog')));
        await page.keyboard.press('Escape');
        await page.waitForFunction(() => !document.querySelector('#dialog').open);
        assert.equal(await page.locator('#dialog').evaluate(element => element.inert), false);

        // Application cancellation (e.g. unsent recording confirmation) wins.
        await page.evaluate(() => {
            const guarded = document.createElement('dialog');
            guarded.id = 'guarded'; guarded.textContent = 'Несохранённая запись';
            guarded.addEventListener('cancel', event => event.preventDefault());
            document.body.append(guarded);
            window.wspace.modalMotion.openDialog(guarded);
        });
        await page.keyboard.press('Escape');
        await page.waitForTimeout(260);
        assert.equal(await page.locator('#guarded').evaluate(element => element.open), true);
        await page.close();
    }
    console.log('Modal exits: deferred close, reduced motion, deduplication, reopen, Escape, focus and cancellation passed');
} finally {
    await browser.close();
}
