import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({headless:true, ...(process.env.E2E_BROWSER_EXECUTABLE ? {executablePath:process.env.E2E_BROWSER_EXECUTABLE} : {})});
try {
 const page = await browser.newPage({viewport:{width:1440,height:900}});
 const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 let saves=0, reject=false;
 await page.route('http://tasks.test/**', async route=>{
  saves++;
  await route.fulfill({status:reject?500:200,contentType:'application/json',body:JSON.stringify(reject?{success:false,error:'test rejection'}:{success:true})});
 });
 await page.goto("http://tasks.test/"); saves=0;
 await page.setContent(`<html data-theme="light"><body data-workspace-section="tasks"><section class="tasks">
 <div class="tasks__controls"></div><div class="tasks__stats">${['pending','in_progress','completed','overdue'].map(s=>`<div class="stat-${s}"><span class="stat-value">${s==='pending'?1:0}</span></div>`).join('')}</div>
 <div class="tasks__list"><div class="task-item" data-task-id="test" data-status="pending"><div class="task-header"><h3 class="task-title">Video regression</h3><input type="checkbox" class="task-complete-toggle" data-task-id="test"></div>
 <select class="task-status-toggle" data-task-id="test">${['pending','in_progress','completed','cancelled'].map(s=>`<option>${s}</option>`).join('')}</select><span class="task-status-label"></span>
 <button type="button" class="btn-sm">Добавить категорию</button><button type="button" class="btn-primary">Сохранить</button><div class="task-subtasks"><button type="button" class="btn-sm">+ Добавить</button></div></div></div></section></body></html>`);
 for(const path of ['app/views/core/common.css','modules/tasks/assets/style.css','modules/tasks/assets/hardening.css','modules/tasks/assets/kanban.css','app/views/core/controls.css','assets/css/workspace-ui-1.0.css','assets/css/workspace-brand-1.0.14.css','assets/css/workspace-dark-1.0.16.css']) await page.addStyleTag({content:await readFile(path,'utf8')});
 await page.evaluate(()=>{window.wspace={path:p=>'http://tasks.test'+p};window.alert=()=>{};});
 for(const path of ['assets/js/usability-actions.js','modules/tasks/assets/tasks-page.js','modules/tasks/assets/tasks-kanban.js']) await page.addScriptTag({content:await readFile(path,'utf8')});
 const card=page.locator('.task-item');
 const stableHint=await page.evaluate(()=>{
  const zone=document.querySelector('.tasks-board__dropzone[data-status="in_progress"]');
  const rect=zone.getBoundingClientRect();
  const point={bubbles:true,clientX:rect.left+10,clientY:rect.top+10};
  zone.dispatchEvent(new DragEvent('dragover',point));
  const hint=zone.querySelector('.tasks-board__drop-placeholder');
  const observer=new MutationObserver(()=>{});observer.observe(zone,{childList:true});
  for(let i=0;i<10;i++){
   zone.dispatchEvent(new DragEvent('dragover',point));
   zone.dispatchEvent(new DragEvent('dragleave',point));
  }
  const result={same:hint===zone.querySelector('.tasks-board__drop-placeholder'),mutations:observer.takeRecords().length,active:zone.classList.contains('tasks-board__dropzone--active'),pointerEvents:getComputedStyle(hint).pointerEvents};
  observer.disconnect();
  zone.dispatchEvent(new DragEvent('dragleave',{bubbles:true,clientX:rect.right+10,clientY:rect.bottom+10}));
  result.exited=!zone.querySelector('.tasks-board__drop-placeholder');
  return result;
 });
 assert.deepEqual(stableHint,{same:true,mutations:0,active:true,pointerEvents:'none',exited:true},'drop hint remains stable across child drag transitions');
 async function move(status){
  await page.evaluate(status=>{
   const transfer=new DataTransfer();
   document.querySelector('.tasks-board__drag-handle').dispatchEvent(new DragEvent('dragstart',{bubbles:true,dataTransfer:transfer}));
   document.querySelector(`.tasks-board__dropzone[data-status="${status}"]`).dispatchEvent(new DragEvent('drop',{bubbles:true,dataTransfer:transfer}));
   // Deliberately omit dragend: browsers can lose it when the dragged node moves.
  },status);
  await page.waitForFunction(()=>document.querySelector('.task-item').dataset.saveState !== 'saving');
 }
 await move('in_progress');
 assert.equal(saves,1,'exactly one request per move');
 assert.equal(await card.getAttribute('data-status'),'in_progress');
 assert.equal(await card.evaluate(n=>n.classList.contains('task-item--dragging')),false,'drop clears translucent drag state without dragend');
 await move('completed');
 assert.equal(saves,2);
 assert.equal(await page.locator('.stat-completed .stat-value').textContent(),'1');
 assert.equal(await page.locator('.stat-in_progress .stat-value').textContent(),'0');
 assert.equal(await page.locator('.task-complete-toggle').isChecked(),true);
 reject=true; await move('pending');
 assert.equal(saves,3);
 assert.equal(await card.getAttribute('data-status'),'completed','failed save rolls card back');
 assert.equal(await page.locator('.stat-completed .stat-value').textContent(),'1');
 assert.equal(await page.locator('.stat-pending .stat-value').textContent(),'0');
 for(const theme of ['light','dark']){
  await page.evaluate(theme=>document.documentElement.dataset.theme=theme,theme);
  await page.waitForTimeout(250);
  const styles=await page.locator('.btn-sm').first().evaluate(n=>{const s=getComputedStyle(n);return {radius:s.borderRadius,bg:s.backgroundColor,color:s.color};});
  assert.equal(styles.radius,'8px');assert.notEqual(styles.bg,'rgba(0, 0, 0, 0)');
  if(theme==='dark'){
   assert.equal(await page.locator('body').evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(18, 19, 20)');
   assert.equal(await page.locator('.btn-primary').evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(41, 122, 160)');
   assert.equal(await page.locator('.btn-primary').evaluate(n=>getComputedStyle(n).color),'rgb(255, 255, 255)');
  }
  if(process.env.E2E_ARTIFACT_DIR){await mkdir(process.env.E2E_ARTIFACT_DIR,{recursive:true});await page.screenshot({path:`${process.env.E2E_ARTIFACT_DIR}/tasks-${theme}.png`,fullPage:true});}
 }
 await page.evaluate(()=>{
  const root=document.createElement('section');root.className='task-boards-page';
  root.innerHTML='<section class="task-board-column" data-status="pending"><div class="task-board-column__items"><article class="task-board-card" draggable="true" data-task-uid="shared"><div class="task-board-card__title"><strong>Shared task</strong></div></article></div></section><section class="task-board-column" data-status="completed"><div class="task-board-column__items"></div></section>';
  document.body.appendChild(root);
 });
 await page.addStyleTag({content:await readFile('modules/tasks/assets/boards.css','utf8')});
 await page.addScriptTag({content:await readFile('modules/tasks/assets/task-boards.js','utf8')});
 const sharedHint=await page.evaluate(()=>{
  const card=document.querySelector('.task-board-card');
  const transfer=new DataTransfer();card.dispatchEvent(new DragEvent('dragstart',{bubbles:true,dataTransfer:transfer}));
  const column=document.querySelector('.task-board-column[data-status="completed"]');
  const rect=column.getBoundingClientRect(), point={bubbles:true,clientX:rect.left+5,clientY:rect.top+5};
  column.dispatchEvent(new DragEvent('dragover',point));
  const items=column.querySelector('.task-board-column__items'), hint=items.querySelector('.task-board-drop-placeholder');
  const observer=new MutationObserver(()=>{});observer.observe(items,{childList:true});
  for(let i=0;i<10;i++){column.dispatchEvent(new DragEvent('dragover',point));column.dispatchEvent(new DragEvent('dragleave',point));}
  const result={same:hint===items.querySelector('.task-board-drop-placeholder'),mutations:observer.takeRecords().length,active:column.dataset.dragOver,pointerEvents:getComputedStyle(hint).pointerEvents};
  observer.disconnect();card.dispatchEvent(new DragEvent('dragend',{bubbles:true,dataTransfer:transfer}));return result;
 });
 assert.deepEqual(sharedHint,{same:true,mutations:0,active:'true',pointerEvents:'none'},'shared board hint is also stable');
 assert.deepEqual(errors,[]);
 console.log('PASS: native task save, move without dragend, rollback, counters, stable personal/shared drop hints and Dark 2026 controls');
} finally {await browser.close();}
