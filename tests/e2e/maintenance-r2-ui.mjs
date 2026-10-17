import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const html = execFileSync(process.env.E2E_PHP_BINARY || 'php', ['-r', `
$view = new class {
    function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    function moduleAsset($module, $asset) { return $asset; }
    function layout($name, $data, $content) { return $content; }
};
include 'modules/calendar/views/index.php';
`], { encoding: 'utf8' });
const writes = [];
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname.startsWith('/api/calendar/')) {
        if (request.method === 'POST') {
            let body = ''; for await (const chunk of request) body += chunk;
            writes.push(JSON.parse(body));
        }
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ events: [], blocks: [], items: [] }));
    } else if (url.pathname === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end('<!doctype html><html data-theme="dark"><head>' +
            '<link rel="stylesheet" href="/modules/calendar/assets/calendar.css">' +
            '<link rel="stylesheet" href="/modules/calendar/assets/calendar-planning.css">' +
            '<link rel="stylesheet" href="/assets/css/workspace.css"></head><body>' + html +
            '<script src="/assets/js/page-runtime.js"></script><script src="/assets/js/modal-motion.js"></script>' +
            '<script src="/modules/calendar/assets/calendar.js"></script>' +
            '<script src="/modules/calendar/assets/calendar-planning.js"></script></body></html>');
    } else if (/^\/(assets|modules)\/[a-zA-Z0-9/.-]+\.(css|js)$/.test(url.pathname) && !url.pathname.includes('..')) {
        response.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript');
        response.end(readFileSync(resolve('.' + url.pathname)));
    } else { response.writeHead(404); response.end(); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
try {
    for (const timezoneId of ['Europe/Moscow', 'Asia/Kolkata', 'America/New_York', 'UTC']) {
        const page = await browser.newPage({ timezoneId });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin);
        await page.locator('[data-calendar-create]').click();
        const eventZone = page.locator('select[data-field="timezone"]');
        assert.equal(await eventZone.inputValue(), await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone));
        assert.ok(await eventZone.locator('option').count() > 400);
        await eventZone.selectOption('Asia/Tokyo');
        await page.locator('[data-field="title"]').fill('Timezone regression');
        await page.locator('[data-calendar-form] button[type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('[data-calendar-editor]').hidden);
        assert.equal(writes.at(-1).timezone, 'Asia/Tokyo');
        await page.locator('[data-calendar-block-create]').click();
        const blockZone = page.locator('select[data-block-field="timezone"]');
        assert.equal(await blockZone.inputValue(), await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone));
        await blockZone.selectOption('UTC');
        await page.locator('[data-block-field="title"]').fill('Block timezone regression');
        await page.locator('[data-block-form] button[type="submit"]').click();
        await page.waitForFunction(() => document.querySelector('[data-calendar-block-editor]').hidden);
        assert.equal(writes.at(-1).timezone, 'UTC');
        assert.deepEqual(errors, []);
        await page.close();
    }
    const page = await browser.newPage();
    await page.goto(origin);
    await page.setContent('<html data-theme="dark"><body class="workspace-viewport--messenger"><div class="messenger-app"><aside class="messenger-list"></aside><section class="messenger-chat"><div class="messenger-history"></div></section></div></body></html>');
    for (const path of ['modules/messenger/views/style.css', 'modules/messenger/views/visual-refresh.css', 'assets/css/workspace.css']) await page.addStyleTag({ path: resolve(path) });
    const colors = await page.evaluate(() => ['.messenger-app', '.messenger-list', '.messenger-chat'].map(selector => getComputedStyle(document.querySelector(selector)).backgroundColor));
    for (const color of colors) {
        const channels = color.match(/\d+/g).map(Number).slice(0, 3);
        assert.ok(Math.max(...channels) - Math.min(...channels) < 10, `Expected a neutral dark surface: ${color}`);
        assert.ok(Math.max(...channels) < 50, `Expected a dark surface: ${color}`);
    }
    console.log('r2: event/block timezone defaults and save payloads in four browser zones; neutral messenger surfaces passed');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
