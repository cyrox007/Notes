import assert from 'node:assert/strict';import {createRequire} from 'node:module';import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try{const page=await browser.newPage({viewport:{width:360,height:740}});const css=(await readFile('modules/messenger/views/style.css','utf8'))+(await readFile('modules/messenger/views/visual-refresh.css','utf8'));
await page.setContent('<style>*{box-sizing:border-box}body{margin:12px}'+css+'</style><div id="root"><header class="messenger-chat__header"><button class="messenger-icon-button messenger-chat__back">←</button><div class="messenger-avatar">C</div><div class="messenger-chat__identity"><strong>Сохранённые сообщения</strong><span>личное облако</span></div><div class="messenger-chat__actions"><button class="messenger-icon-button">Поиск</button><button class="messenger-icon-button">Архив</button></div></header></div>');
await page.addScriptTag({content:(await readFile('modules/messenger/views/script.js','utf8')).replace('{literal}','').replace('{/literal}','').replace('class MessengerApp {','window.TestMessenger = class MessengerApp {')});
await page.evaluate(()=>{const app=Object.create(window.TestMessenger.prototype);app.root=document.querySelector('#root');app.setupMobileChatActions();const call=document.createElement('button');call.textContent='Звонок';call.className='messenger-icon-button';document.querySelector('.messenger-chat__actions').append(call);});
await page.waitForTimeout(50);assert.equal(await page.locator('.messenger-chat-actions-panel button').count(),3);
assert.ok(await page.locator('.messenger-chat__identity').evaluate(e=>e.getBoundingClientRect().width)>140);
assert.equal(await page.locator('.messenger-chat-actions-panel').evaluate(e=>e.inert),true);
await page.getByRole('button',{name:'Действия с чатом'}).click();await page.waitForTimeout(220);assert.equal(await page.locator('.messenger-chat-actions-panel').evaluate(e=>e.inert),false);
await page.keyboard.press('Escape');assert.equal(await page.locator('.messenger-chat-actions-panel').evaluate(e=>e.inert),true);
await page.setViewportSize({width:1000,height:740});await page.waitForTimeout(50);assert.equal(await page.locator('.messenger-chat-actions-panel').evaluate(e=>e.inert),false);assert.equal(await page.locator('.messenger-chat-actions-toggle').isVisible(),false);
await page.locator('.messenger-chat__header').evaluate(e=>e.style.width='360px');await page.waitForTimeout(60);assert.equal(await page.locator('.messenger-chat-actions-toggle').isVisible(),true);assert.equal(await page.locator('.messenger-chat-actions-panel').evaluate(e=>e.inert),true);
const seen=await page.evaluate(()=>{const app=Object.create(window.TestMessenger.prototype);return [app.formatLastSeen(null),app.formatLastSeen(Date.now()/1000),app.formatLastSeen((Date.now()-86400000)/1000)];});assert.equal(seen[0],'не в сети');assert.match(seen[1],/сегодня/);assert.match(seen[2],/вчера/);
console.log('PASS compact mobile header, title width, late call buttons, inert/Escape and desktop actions');
}finally{await browser.close();}
