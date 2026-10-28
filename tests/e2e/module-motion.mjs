import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
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
        const actualWorkPermits = root === 'wp-journal';
        const fixture = actualWorkPermits ? JSON.parse(execFileSync(process.env.E2E_PHP_BINARY || 'php', ['tests/fixtures/work-permits-layout.php'], { encoding: 'utf8' })).content : null;
        await page.setContent(fixture || `<section class="${root}"><details class="calendar-shell"><summary>Справочники</summary><div style="height:220px">Содержимое</div></details><button class="${button}">Действие</button></section>`);
        await page.addStyleTag({path:resolve('app/views/core/common.css')});
        await page.addStyleTag({ path: resolve(`modules/${css}/assets/${css}.css`) });
        await page.addStyleTag({ path: resolve('assets/css/workspace.css') });
        await page.addScriptTag({ path: resolve('assets/js/page-runtime.js') });
        await page.addScriptTag({ path: resolve('assets/js/usability-actions.js') });
        if (actualWorkPermits) {
            const registry = page.locator('[aria-labelledby="wp-registry-title"]');
            const before = await registry.boundingBox();
            await page.locator('[data-wp-list]').evaluate(el => { el.innerHTML = '<p>За последний месяц черновиков нет.</p>'; el.removeAttribute('aria-busy'); });
            assert(Math.abs((await registry.boundingBox()).height - before.height) < 1, 'Empty response must not shift the registry');
        }
        const details = page.locator('details').first();
        const summary = details.locator('summary').first();
        assert.equal(await summary.evaluate(el => getComputedStyle(el, '::after').content), '""', 'Chevron must not be a text glyph');
        const height = () => details.evaluate(el => el.getBoundingClientRect().height);
        const closed = await height();
        await summary.click();
        assert.equal(await details.evaluate(el => el.getAnimations().length), 1, 'Opening must animate');
        await page.waitForTimeout(350);
        const opened = await height();
        assert(opened > closed + 150);
        if (actualWorkPermits) {
            const add = details.getByRole('button', { name: 'Добавить', exact: true });
            assert((await add.boundingBox()).height <= 44, 'Reference action must not stretch to the label height');
        }
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
        await page.waitForFunction(() => document.querySelector('details').getAnimations().length === 0);
        assert.equal(await details.getAttribute('open'), null, 'Fast reversal must finish closed');
        const action = actualWorkPermits ? page.locator('.wp-journal__shortcuts a').first() : page.locator('button');
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
        if (actualWorkPermits) {
            await page.locator('details').nth(1).evaluate(el => { el.open = true; });
            const copy = page.locator('[data-wp-copy-job]');
            assert.equal(await copy.evaluate(el => el.closest('label')), null, 'Action must not belong to the textarea label');
            await copy.hover();
            assert.equal(await page.locator('[data-wp-template-description] textarea').evaluate(el => el.matches(':hover')), false, 'Action hover must not highlight textarea');
        }
        await page.close();
    }
    console.log('Module motion: opening/closing, padding, fast reversal, keyboard, hover, reduced motion and AJAX lifecycle passed');
} finally { await browser.close(); }
