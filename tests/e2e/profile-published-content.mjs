import { addWorkspaceScript } from './page-runtime-fixture.mjs';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try {
 const page=await browser.newPage({viewport:{width:390,height:800}});
 const css=await readFile('modules/profile/assets/hub.css','utf8');
 await page.route('http://profile.test/',route=>route.fulfill({body:'<style>:root{--ui-surface:white;--ui-text:#123;--ui-border:#ddd}'+css+'</style><button data-public-view="/notes/published/note">Посмотреть</button>'}));
 let revoked=false;
 await page.route('**/notes/published/note',async route=>{await new Promise(r=>setTimeout(r,150));await route.fulfill({status:revoked?404:200,contentType:'application/json',body:JSON.stringify(revoked?{error:'Заметка больше не опубликована'}:{title:'Заметка',owner:'owner',content:'<script>window.injected=true</script>',attachments:[{file_name:'file.txt',url:'/notes/published/note/attachment/file'}],subtasks:[{title:'Step',is_completed:1}]})});});
 await page.goto('http://profile.test/');
 await addWorkspaceScript(page, {content:await readFile('modules/profile/assets/profile.js','utf8')});
 await page.locator('[data-public-view]').click();
 await page.locator('[aria-busy="true"]').waitFor();
 await page.locator('.profile-public-view__text').waitFor();
 assert.equal(await page.evaluate(()=>window.injected),undefined);
 assert.equal(await page.locator('.profile-public-view__text').textContent(),'<script>window.injected=true</script>');
 assert.equal(await page.locator('dialog a[download]').count(),1);
 assert.equal(await page.locator('dialog textarea,dialog input').count(),0);
 assert.ok(await page.locator('dialog').evaluate(e=>e.getBoundingClientRect().right<=innerWidth));
 await page.keyboard.press('Escape');await page.waitForTimeout(250);assert.equal(await page.locator('dialog').count(),0);
 revoked=true;await page.locator('[data-public-view]').click();await page.getByText('Заметка больше не опубликована',{exact:true}).waitFor();
 await page.getByRole('button',{name:'Закрыть'}).click();await page.waitForTimeout(250);assert.equal(await page.locator('dialog').count(),0);
 console.log('PASS published modal loading, text escaping, attachments, read-only layout, Escape and revoked access');
} finally {await browser.close();}
