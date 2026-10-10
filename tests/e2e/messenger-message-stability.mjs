import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try{
 const page=await browser.newPage();
 const source=(await readFile('modules/messenger/views/script.js','utf8')).replace('{literal}','').replace('{/literal}','').replace('class MessengerApp {','window.MessengerAppForTest = class MessengerApp {');
 await page.setContent('<div id="scroll"><div id="messages"></div></div>');
 await page.addScriptTag({content:source});
 await page.evaluate(()=>{
  const app=Object.create(window.MessengerAppForTest.prototype);
  Object.assign(app,{userUid:'me',currentDialog:{type:'private'},el:{messageList:document.querySelector('#messages'),messageScroll:document.querySelector('#scroll')},dayKey:()=> 'today',formatDay:()=> 'Сегодня',messages:[{uid:'video-a',message:'',message_type:'video'}],renderMessage(message){const row=document.createElement('article');row.dataset.uid=message.uid;row.innerHTML='<div class="messenger-message__bubble"><video controls></video><div class="messenger-message__meta"></div></div>';row.querySelector('.messenger-message__meta').textContent=message.message_status||'sent';return row;}});
  app.renderMessages();window.originalVideo=document.querySelector('video');window.originalRow=originalVideo.closest('article');window.fixtureApp=app;
  window.disconnections=0;new MutationObserver(records=>{for(const record of records)for(const node of record.removedNodes)if(node===originalRow||node===originalVideo||node.contains?.(originalVideo))window.disconnections++;}).observe(app.el.messageList,{childList:true,subtree:true});
  for(let i=0;i<12;i++){app.messages[0].message_status=i%2?'read':'delivered';app.renderMessages();}
  app.messages.unshift({uid:'older',message:'older'});app.renderMessages();
 });
 await page.waitForTimeout(50);
 assert.equal(await page.evaluate(()=>document.querySelector('[data-uid="video-a"] video')===originalVideo&&originalVideo.isConnected&&disconnections===0),true);
 assert.equal(await page.locator('[data-uid="video-a"] .messenger-message__meta').textContent(),'read');
 const css=(await Promise.all(['assets/css/workspace.css','modules/messenger/views/style.css','modules/messenger/views/visual-refresh.css'].map(p=>readFile(p,'utf8')))).join('\n');
 for(const width of [390,900,1440]){
  await page.setViewportSize({width,height:700});
  await page.setContent(`<style>body{margin:0}${css}</style><article class="messenger-message messenger-message--own messenger-message--actions-open" style="width:360px"><div class="messenger-message__bubble">Видео</div><div class="messenger-message__actions">${'<button class="messenger-message__action">+</button>'.repeat(9)}</div></article>`);
  await page.locator('article').hover();
  assert.equal(await page.evaluate(()=>{const actions=document.querySelector('.messenger-message__actions').getBoundingClientRect();const bubble=document.querySelector('.messenger-message__bubble').getBoundingClientRect();return actions.bottom<=bubble.top+1&&actions.left>=0&&actions.right<=innerWidth&&document.documentElement.scrollWidth<=innerWidth;}),true,`actions above message at ${width}`);
 }
 await page.setViewportSize({width:900,height:700});
 await page.setContent(`<style>${css}</style><article class="messenger-message messenger-message--own"><div class="messenger-message__bubble">Видео</div><div class="messenger-message__actions"><button class="messenger-message__action messenger-message__reply-action">Ответить</button><button class="messenger-message__action messenger-message__reaction-action">Реакция</button>${'<button class="messenger-message__action">Другое</button>'.repeat(6)}<button class="messenger-message__action messenger-message__more-actions">Ещё</button></div></article>`);
 await page.locator('article').hover();
 assert.equal(await page.locator('.messenger-message__action:visible').count(),3);
 const collapsedWidth=await page.locator('.messenger-message__bubble').evaluate(el=>el.getBoundingClientRect().width);
 await page.evaluate(()=>document.querySelector('article').classList.add('messenger-message--actions-open'));
 assert.equal(await page.locator('.messenger-message__action:visible').count(),9);
 assert.equal(await page.locator('.messenger-message__bubble').evaluate(el=>el.getBoundingClientRect().width),collapsedWidth,'Actions must not widen the message');
 await page.route('https://state.test/**',route=>route.fulfill({body:'<html><body></body></html>',contentType:'text/html'}));
 await page.goto('https://state.test/');
 const installStateFixture=async(user='one',uids=['a','b'])=>{
  await page.evaluate(()=>{document.body.innerHTML='<div id="fixture"><div id="chat-empty-state"></div><div id="chat-active"></div><textarea id="message-input"></textarea><div id="message-scroll" style="height:100px;overflow:auto"><div id="message-list"></div></div><button id="load-older-button"></button></div>';});
  await page.addScriptTag({content:source});
  await page.evaluate(({user,uids})=>{
   const root=document.querySelector('#fixture');root.dataset.userUid=user;
   const app=new window.MessengerAppForTest(root);
   const tools=document.createElement('div');tools.className='messenger-composer__tools';tools.innerHTML='<button type="button" aria-label="Файл">Файл</button><button type="button" aria-label="Микрофон">Микрофон</button>';root.append(tools);
   for(const name of ['renderDialogs','renderChatHeader','clearComposeContext','storeDialogCache','renderDialogSnapshotState','markCurrentRead','focusRequestedMessage','notifyTyping'])app[name]=()=>{};
   app.sendEvent=()=>true;
   app.renderMessages=()=>{app.el.messageList.innerHTML='<div style="height:1000px">History</div>';};
   app.bindEvents();app.applyDialogs(uids.map(uid=>({uid,type:'private'})));window.stateApp=app;
  },{user,uids});
 };
 await installStateFixture();
 await page.waitForSelector('.messenger-tools-toggle');
 assert.equal(await page.locator('.messenger-tools-panel').evaluate(el=>el.inert),true);
 await page.locator('.messenger-tools-toggle').click();
 assert.equal(await page.locator('.messenger-tools-panel').evaluate(el=>el.inert),false);
 await page.getByRole('button',{name:'Файл',exact:true}).focus();await page.keyboard.press('Escape');
 assert.equal(await page.locator('.messenger-tools-toggle').getAttribute('aria-expanded'),'false');
 assert.equal(await page.locator('.messenger-tools-toggle').evaluate(el=>el===document.activeElement),true);
 await page.evaluate(()=>{stateApp.openDialog('a');stateApp.el.input.value='Черновик';stateApp.el.messageList.innerHTML='<div style="height:1000px">History</div>';stateApp.el.messageScroll.scrollTop=123;});
 await page.reload();await installStateFixture();
 assert.equal(await page.evaluate(()=>stateApp.currentDialog.uid),'a');
 assert.equal(await page.locator('#message-input').inputValue(),'Черновик');
 assert.equal(await page.locator('#message-list').getAttribute('aria-busy'),'true');
 await page.evaluate(()=>stateApp.applyMessages({dialog_uid:'b',messages:[]}));
 assert.equal(await page.locator('#message-list').getAttribute('aria-busy'),'true','A stale response must not clear loading');
 await page.evaluate(()=>stateApp.applyMessages({dialog_uid:'a',messages:[]}));await page.waitForTimeout(50);
 assert.equal(await page.locator('#message-list').getAttribute('aria-busy'),'false');
 assert.equal(await page.locator('#message-scroll').evaluate(el=>el.scrollTop),900);
 await page.evaluate(()=>stateApp.el.messageList.firstChild.style.height='1100px');await page.waitForTimeout(50);
 assert.equal(await page.locator('#message-scroll').evaluate(el=>el.scrollTop),1000,'Late media layout must keep latest message visible');
 await page.locator('#message-scroll').evaluate(el=>el.scrollTop=123);await page.waitForTimeout(50);
 await page.evaluate(()=>stateApp.el.messageList.firstChild.style.height='1200px');await page.waitForTimeout(50);
 assert.equal(await page.locator('#message-scroll').evaluate(el=>el.scrollTop),123,'Reading history must not be interrupted');
 await page.goto('https://state.test/?dialog=b');await installStateFixture();
 assert.equal(await page.evaluate(()=>stateApp.currentDialog.uid),'b');
 await page.goto('https://state.test/');await installStateFixture('other');
 assert.equal(await page.evaluate(()=>stateApp.currentDialog),null);
 console.log('PASS: reload restores dialog and draft at latest message; loading, late media layout, manual history scroll and user isolation preserved');
 console.log('PASS: video node stays connected across receipt changes and prepend; actions above cards at 390/900/1440px');
}finally{await browser.close();}
