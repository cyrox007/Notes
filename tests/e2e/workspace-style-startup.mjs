import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile,mkdir} from 'node:fs/promises';
import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const html = execFileSync(process.env.E2E_PHP_EXECUTABLE || 'php', ['tests/e2e/fixtures/workspace-style-page.php'], {encoding:'utf8'});
const browser = await chromium.launch({headless:true, ...(process.env.E2E_BROWSER_EXECUTABLE ? {executablePath:process.env.E2E_BROWSER_EXECUTABLE} : {})});
try {
    for (const width of [1440,390]) for (const theme of ['light','dark']) {
        const context = await browser.newContext({viewport:{width,height:900}});
        await context.addInitScript(value => localStorage.setItem('workspace.theme', value), theme);
        const page = await context.newPage();
        let releaseScripts;
        const scriptsReady = new Promise(resolve => {releaseScripts = resolve;});
        await page.route('http://workspace.test/**', async route => {
            const pathname = new URL(route.request().url()).pathname;
            if (pathname === '/nested/admin/settings') {
                return route.fulfill({contentType:'text/html',body:html});
            }
            const file = pathname.replace(/^\/nested\//,'');
            if (file.endsWith('.js')) {
                await scriptsReady;
                const body = ['assets/js/common.js','assets/js/theme-mode.js'].includes(file) ? await readFile(file,'utf8') : '';
                return route.fulfill({contentType:'application/javascript',body});
            }
            if (/^(assets|modules)\/[\w./-]+$/.test(file) && !file.includes('..')) {
                try { return await route.fulfill({body:await readFile(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':undefined}); }
                catch {}
            }
            return route.fulfill({status:404,body:''});
        });
        await page.goto('http://workspace.test/nested/admin/settings',{waitUntil:'commit'});
        const button = page.locator('.admin-action--primary').first();
        await button.waitFor({state:'visible'});
        const appearance = async () => button.evaluate(el => {
            const style=getComputedStyle(el);
            return {background:style.backgroundImage,color:style.color,fill:style.backgroundColor,border:style.borderRadius,section:document.body.dataset.workspaceSection,theme:document.documentElement.dataset.theme};
        });
        const first = await appearance();
        assert.equal(first.section,'admin');
        assert.equal(first.theme,theme);
        assert.equal(await page.locator('.sidebar__utility[aria-current="page"]').count(),1);
        releaseScripts();
        await page.waitForLoadState('load');
        assert.deepEqual(await appearance(),first,'Deferred scripts must not repaint controls into a different theme');
        assert.notEqual(first.border,'0px','Controls must retain their custom appearance');
        assert.ok(await page.locator('.admin-storage-capacity').innerText().then(text=>text.includes('20 ГБ')&&text.includes('не хватит')));
        assert.equal(await page.locator('body').evaluate(el=>el.scrollWidth<=innerWidth),true,'No horizontal overflow');
        if (process.env.E2E_SCREENSHOT_DIR) {
            await mkdir(process.env.E2E_SCREENSHOT_DIR,{recursive:true});
            await page.screenshot({path:process.env.E2E_SCREENSHOT_DIR+'/settings-'+theme+'-'+width+'.png',fullPage:true});
        }
        await context.close();
    }
    console.log('PASS: actual server-rendered settings retain first-paint control styles before and after delayed scripts, both themes and screen sizes');
} finally {await browser.close();}
