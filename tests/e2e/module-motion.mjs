import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
try {
    for (const [root, css, button] of [
        ['defects-workspace', 'defects', 'defects-button'],
        ['wp-journal', 'work-permits', ''],
    ]) {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<section class="${root}"><details class="calendar-shell"><summary>Справочники</summary><div style="height:220px">Содержимое</div></details><button class="${button}">Действие</button></section>`);
        await page.addStyleTag({ path: resolve(`modules/${css}/assets/${css}.css`) });
        await page.addScriptTag({ path: resolve('assets/js/page-runtime.js') });
        await page.addScriptTag({ path: resolve('assets/js/usability-actions.js') });
        const details = page.locator('details');
        const summary = page.locator('summary');
        const height = () => details.evaluate(el => el.getBoundingClientRect().height);
        const closed = await height();
        await summary.click();
        assert.equal(await details.evaluate(el => el.getAnimations().length), 1, 'Opening must animate');
        await page.waitForTimeout(350);
        const opened = await height();
        assert(opened > closed + 150);
        await summary.focus();
        await page.keyboard.press('Enter');
        assert.equal(await details.getAttribute('open'), '', 'Content must stay open during closing');
        assert.equal(await details.evaluate(el => el.getAnimations().length), 1);
        await page.waitForTimeout(350);
        assert.equal(await details.getAttribute('open'), null);
        assert(Math.abs(await height() - closed) < 1, 'Closed height must include padding');
        await summary.click();
        await page.waitForTimeout(45);
        await summary.click();
        await page.waitForTimeout(350);
        assert.equal(await details.getAttribute('open'), null, 'Fast reversal must finish closed');
        const action = page.locator('button');
        await action.hover();
        await page.waitForTimeout(240);
        assert.notEqual(await action.evaluate(el => getComputedStyle(el).transform), 'none');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await summary.click();
        assert.equal(await details.evaluate(el => el.getAnimations().length), 0, 'Reduced motion must be immediate');
        await summary.click();
        await page.evaluate(() => { window.wspace.page.dispose(); window.wspace.page.initialize(); });
        await summary.click();
        assert.equal(await details.getAttribute('open'), '', 'AJAX reinitialization must not duplicate listeners');
        assert.deepEqual(errors, []);
        await page.close();
    }
    console.log('Module motion: opening/closing, padding, fast reversal, keyboard, hover, reduced motion and AJAX lifecycle passed');
} finally { await browser.close(); }
