import { createServer } from 'node:http';
import { readFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const fixture = JSON.parse(execFileSync(process.env.E2E_PHP_BINARY || 'php', ['tests/fixtures/admin-notification-layout.php'], { encoding: 'utf8' }));
const shellStyles = {
    '/shell/common.css': 'app/views/core/common.css',
    '/shell/header.css': 'app/views/^shared/header/style.css',
    '/shell/sidebar.css': 'app/views/^shared/sidebar/style.css',
};
let writes = 0;
const server = createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (request.method !== 'GET') { writes++; response.writeHead(405); response.end(); return; }
    if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
    const asset = shellStyles[url.pathname] || (/^\/(?:assets|modules)\/[a-zA-Z0-9/.-]+\.(css|js)$/.test(url.pathname) && !url.pathname.includes('..') ? '.' + url.pathname : null);
    if (asset) {
        response.setHeader('Content-Type', asset.endsWith('.css') ? 'text/css' : 'application/javascript');
        response.end(readFileSync(resolve(asset))); return;
    }
    const notificationPage = url.pathname === '/admin/notifications';
    const styles = notificationPage ? fixture.styles : ['/modules/admin/assets/style.css'];
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin</title>
      ${Object.keys(shellStyles).map(href => `<link rel="stylesheet" href="${href}">`).join('')}
      ${styles.map(href => `<link rel="stylesheet" data-workspace-module-style href="${href}">`).join('')}
      <link rel="stylesheet" href="/assets/css/workspace.css">
      <script>window.wspaceRuntime={basePath:''};</script><script src="/assets/js/page-runtime.js"></script>
      <script src="/assets/js/common.js" defer></script><script src="/assets/js/navigation.js" defer></script></head>
      <body data-workspace-section="admin"><div class="wrapper"><aside class="sidebar" id="workspaceSidebar">
      <nav class="sidebar__menu"><a data-nav-key="admin" href="/admin/modules">Модули</a><a href="/admin/notifications" id="notifications-link">Каналы уведомлений</a></nav></aside>
      <div class="wrapper__content"><header class="navbar">Workspace<button data-sidebar-toggle>Меню</button></header>
      <main id="main-content" class="content-wrapper" tabindex="-1">${notificationPage ? fixture.content : '<section><h1>Модули</h1></section>'}</main></div></div></body></html>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
try {
    for (const theme of ['light', 'dark']) for (const [width, columns, panels] of [[1440, 3, 2], [1024, 2, 1], [390, 1, 1]]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(origin + '/admin/notifications');
        await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
        async function checkLayout() {
            const layout = await page.evaluate(() => {
                const root = document.querySelector('[data-notification-administration]');
                const fields = root.querySelector('.admin-notification-fields');
                const main = document.querySelector('main').getBoundingClientRect();
                return {
                    fields: getComputedStyle(fields).gridTemplateColumns.split(' ').length,
                    panels: getComputedStyle(root).gridTemplateColumns.split(' ').length,
                    overflow: document.documentElement.scrollWidth > innerWidth + 1,
                    clippedInputs: [...root.querySelectorAll('input:not([type="hidden"]),select')].some(input => {
                        const rect = input.getBoundingClientRect();
                        return rect.left < main.left - 1 || rect.right > main.right + 1;
                    }),
                };
            });
            assert.deepEqual(layout, { fields: columns, panels, overflow: false, clippedInputs: false }, `${theme} ${width}px`);
        }
        await checkLayout();
        await page.evaluate(() => scrollTo(0, 1500));
        await page.waitForFunction(() => scrollY > 1000);
        assert.equal(await page.locator('.navbar').evaluate(element => Math.round(element.getBoundingClientRect().top)), 0, 'Header moved while scrolling');
        if (width > 900) assert.equal(await page.locator('.sidebar').evaluate(element => Math.round(element.getBoundingClientRect().top)), 0, 'Sidebar moved while scrolling');
        await page.goto(origin + '/admin/modules');
        await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
        if (width <= 900) await page.locator('[data-sidebar-toggle]').click();
        await page.locator('#notifications-link').click();
        await page.waitForURL('**/admin/notifications');
        assert.equal(await page.locator('link[data-workspace-module-style]').count(), 2);
        await checkLayout();
        await page.goBack();
        await page.waitForURL('**/admin/modules');
        await page.waitForFunction(() => document.querySelector('h1')?.textContent === 'Модули' && !document.documentElement.classList.contains('workspace-navigating'));
        assert.equal(await page.locator('link[data-workspace-module-style]').count(), 1);
        await page.goForward();
        await page.waitForURL('**/admin/notifications');
        await page.waitForFunction(() => !!document.querySelector('[data-notification-administration]') && !document.documentElement.classList.contains('workspace-navigating'));
        await checkLayout();
        if (process.env.E2E_SCREENSHOT_DIR) {
            mkdirSync(process.env.E2E_SCREENSHOT_DIR, { recursive: true });
            await page.screenshot({ path: resolve(process.env.E2E_SCREENSHOT_DIR, `notifications-${theme}-${width}.png`), fullPage: true });
        }
        assert.deepEqual(errors, []);
        await page.close();
    }
    assert.equal(writes, 0);
    console.log('Notification layout: desktop/tablet/mobile, light/dark, pinned shell, direct/AJAX/history navigation and contained fields passed');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
