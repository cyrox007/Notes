import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { mkdirSync } from 'node:fs';
import assert from 'node:assert/strict';
const { chromium } = createRequire(import.meta.url)('playwright');
const browser = await chromium.launch({headless:true,...(process.env.E2E_CHROMIUM_PATH ? {executablePath:process.env.E2E_CHROMIUM_PATH} : {})});
try {
 for (const module of ['work-permits','defects','spaces','admin-spaces']) {
  const fixture=JSON.parse(execFileSync(process.env.E2E_PHP_BINARY||'php',['tests/fixtures/work-permits-layout.php',module],{encoding:'utf8'}));
  for(const width of [1440,390]) for(const theme of ['light','dark']) {
   const page=await browser.newPage({viewport:{width,height:1000}});
   const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent(fixture.content);
   await page.addStyleTag({path:resolve('app/views/core/common.css')});
   for(const file of fixture.styles) await page.addStyleTag({path:resolve('.'+file)});
   await page.addStyleTag({path:resolve('app/views/core/controls.css')});
   await page.addStyleTag({path:resolve('assets/css/workspace.css')});
   await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
   await page.addScriptTag({path:resolve('assets/js/page-runtime.js')});
   await page.addScriptTag({path:resolve('assets/js/usability-actions.js')});
   const shortcut=page.locator('a[href="#filespace-permissions"]');
   if(await shortcut.count()) {
    await shortcut.click();
    assert.equal(await page.locator('#filespace-permissions').getAttribute('open'),'');
    assert.equal(await page.locator('#filespace-permissions').evaluate(el=>el.getAnimations().length),1,'Permissions must animate via shortcut');
    await page.waitForTimeout(330);
   }
   for(const details of await page.locator('details').all()) {
    if(!await details.isVisible())continue;
    await details.locator(':scope > summary').click();
    assert.equal(await details.evaluate(el=>el.getAnimations().length),1,`${module} disclosure has no animation`);
    await page.waitForTimeout(320);
   }
   const button=page.locator('.ui-button:visible:not(:disabled)').first();
   if(await button.count()){
    await button.hover();await page.waitForTimeout(210);
    assert.notEqual(await button.evaluate(el=>getComputedStyle(el).transform),'none',`${module} hover missing`);
   }
   const bad=await page.locator('.ui-form input:not([type=hidden]):visible,.ui-form select:visible').evaluateAll(nodes=>nodes.filter(el=>!['checkbox','radio','file'].includes(el.type)&&!el.multiple).filter(el=>el.getBoundingClientRect().height>42).map(el=>el.name));
   assert.deepEqual(bad,[],`${module} stretched controls at ${width}px`);
   assert.deepEqual(errors,[]);
   if(process.env.E2E_SCREENSHOT_DIR){mkdirSync(process.env.E2E_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:resolve(process.env.E2E_SCREENSHOT_DIR,`${module}-${theme}-${width}.png`),fullPage:true});}
   await page.close();
  }
 }
 console.log('Shared controls: real module views, desktop/mobile, light/dark, all disclosures, shortcut actions, hover and control sizes passed');
} finally {await browser.close();}
