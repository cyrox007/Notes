import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const sections = ['home', 'notes', 'tasks', 'calendar', 'files', 'profile', 'admin', 'messenger'];
let version = 0;
let mutations = 0;
let csrf = '';
const moduleCode = `window.wspace.page.register('fixture-module',scope=>{
 window.initializations=(window.initializations||0)+1;
 scope.listen(document,'fixture-action',()=>window.actions=(window.actions||0)+1);
 scope.onDispose(()=>window.disposals=(window.disposals||0)+1);
});`;
const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
    if (url.pathname.includes('/attachment/') || url.pathname === '/files/published/test' || url.pathname === '/messenger/media/test') {
        response.writeHead(200, { 'Content-Type': 'application/octet-stream', 'Content-Disposition': 'attachment; filename="fixture.txt"' });
        response.end('download fixture'); return;
    }
    if (url.pathname.startsWith('/assets/js/')) {
        response.setHeader('Content-Type', 'application/javascript');
        response.end(readFileSync(resolve('.' + url.pathname))); return;
    }
    if (url.pathname === '/module.js') {
        response.setHeader('Content-Type', 'application/javascript'); response.end(moduleCode); return;
    }
    if (url.pathname === '/late.js') {
        response.setHeader('Content-Type', 'application/javascript');
        setTimeout(() => response.end("window.wspace.page.register('late-module',scope=>{const button=document.querySelector('#late-button');if(button)button.addEventListener('click',()=>window.lateClicks=(window.lateClicks||0)+1);});"), 400);
        return;
    }
    if (url.pathname === '/module.css') {
        response.setHeader('Content-Type', 'text/css'); response.end('main{min-height:150px}'); return;
    }
    if (url.pathname === '/tasks/create') {
        let body = ''; for await (const chunk of request) body += chunk;
        if (!body.includes(csrf) || request.headers['x-csrf-token'] !== csrf) { console.error('Fixture CSRF mismatch', csrf, request.headers['x-csrf-token'], body.slice(0, 400)); response.writeHead(403); response.end('CSRF failed'); return; }
        mutations++; response.writeHead(303, { Location: '/tasks/' }); response.end(); return;
    }
    const section = url.pathname.split('/')[1] || 'home';
    const nonce = 'server-nonce-' + (++version);
    csrf = 'csrf-' + version;
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    response.setHeader('Content-Security-Policy', `default-src 'self'; script-src 'self' 'nonce-${nonce}'; style-src 'self' 'nonce-${nonce}'; connect-src 'self'`);
    response.end(`<!doctype html><title>${section}</title>
      <link rel="stylesheet" data-workspace-module-style href="/module.css">
      <template id="csrf-token-template"><input name="csrf_token" value="${csrf}"></template>
      <script data-workspace-runtime nonce="${nonce}">window.wspaceRuntime={basePath:''};window.wspace=window.wspace||{};window.instance=window.instance||crypto.randomUUID();</script>
      <script src="/assets/js/page-runtime.js"></script>
      <script src="/assets/js/common.js" defer></script>
      <script src="/assets/js/navigation.js" defer></script>
      <script data-workspace-module-script src="/module.js" defer></script>
      ${section === 'notes' ? '<script data-workspace-module-script src="/late.js" defer></script>' : ''}
      <body data-workspace-section="${section}"><header id="header">Оболочка</header><nav>${sections.map(name => `<a href="/${name}/" data-nav-key="${name}" class="${section === name ? 'active' : ''}">${name}</a>`).join('')}</nav>
      <main id="main-content" tabindex="-1"><h1>${section}</h1><p id="mutations">${mutations}</p>${section === 'notes' ? '<button id="late-button">Действие модуля</button>' : ''}
      <form action="/tasks/create" method="post"><input name="title" required value="Тест"><button name="action" value="create">Создать</button></form>
      <script nonce="${nonce}">window.wspace.page.register('fixture-inline',scope=>{window.inlineRuns=(window.inlineRuns||0)+1;});</script>
      <script>window.untrustedScriptExecuted=true;</script></main></body>`);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ headless: true, ...(process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {}) });
try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') console.error(message.text()); });
    await page.goto(origin + '/home/');
    const instance = await page.evaluate(() => window.instance);
    let visits = 1;
    for (const section of [...sections.slice(1), 'tasks', 'notes', 'tasks']) {
        await page.locator(`a[data-nav-key="${section}"]`).click();
        if (section === 'notes') {
            await page.waitForURL(url => url.pathname === '/notes/');
            assert.equal(await page.locator('#main-content').evaluate(main => main.inert), false, 'The editor URL became current before its controls were ready');
            const previous = await page.evaluate(() => window.lateClicks || 0);
            await page.locator('#late-button').click();
            assert.equal(await page.evaluate(() => window.lateClicks), previous + 1, 'Click was lost while a module script was loading');
        }
        await page.waitForFunction(section => document.body.dataset.workspaceSection === section && !document.documentElement.classList.contains('workspace-navigating'), section);
        visits++;
        assert.equal(await page.evaluate(() => window.instance), instance);
        assert.equal(await page.locator('h1').innerText(), section);
        assert.equal(await page.evaluate(() => window.initializations), visits);
        assert.equal(await page.evaluate(() => window.inlineRuns), visits);
        assert.equal(await page.locator('link[data-workspace-module-style]').count(), 1);
        await page.evaluate(() => document.dispatchEvent(new Event('fixture-action')));
        assert.equal(await page.evaluate(() => window.actions), visits - 1);
    }
    await page.getByRole('button', { name: 'Создать' }).evaluate(button => { button.click(); button.click(); });
    await page.waitForFunction(() => document.querySelector('#mutations')?.textContent === '1');
    assert.equal(await page.evaluate(() => window.instance), instance);
    assert.equal(mutations, 1);
    assert.equal(await page.evaluate(() => window.untrustedScriptExecuted), undefined);
    for (const path of ['/notes/attachment/test', '/notes/published/test/attachment/test', '/notes/shared/test/attachment/test', '/files/published/test', '/messenger/media/test']) {
        await page.evaluate(path => { const link = document.createElement('a'); link.id = 'fixture-download'; link.href = path; link.textContent = 'Вложение'; document.querySelector('main').append(link); }, path);
        const download = page.waitForEvent('download');
        await page.locator('#fixture-download').click();
        assert.equal((await download).suggestedFilename(), 'fixture.txt');
        assert.equal(await page.evaluate(() => window.instance), instance);
        await page.locator('#fixture-download').evaluate(link => link.remove());
    }
    await page.goBack();
    await page.waitForFunction(() => document.body.dataset.workspaceSection === 'notes');
    await page.goForward();
    await page.waitForFunction(() => document.body.dataset.workspaceSection === 'tasks');
    assert.equal(await page.evaluate(() => window.instance), instance);
    assert.deepEqual(errors, []);
    console.log('AJAX: all module navigation, lifecycle cleanup, HTML forms, CSRF rotation, CSP, back/forward passed');
} finally {
    await browser.close();
    await new Promise(resolve => server.close(resolve));
}
