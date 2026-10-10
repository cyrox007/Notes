import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{})});
try{
 const page=await browser.newPage();
 await page.setContent(`<html><body><main class="admin-page"><section class="admin-panel-card"><div class="admin-users-table-wrap"><table class="admin-users-table"><tbody><tr><td data-label="Пользователь">Test user</td><td data-label="Действия"><div class="admin-user-actions"><details open class="admin-user-editor"><summary class="admin-action">Редактировать</summary><form class="admin-user-editor__form">${['Логин','Email','Имя','Отчество','Фамилия','Телефон','Новый пароль'].map(label=>`<label>${label}<input value="Example value"></label>`).join('')}<button class="admin-action">Сохранить</button></form></details><form><button class="admin-action">Блокировать</button></form></div></td></tr></tbody></table></div></section></main></body></html>`);
 for(const file of ['app/views/core/common.css','app/views/core/controls.css','assets/css/workspace-ui-1.0.css','assets/css/workspace-brand-1.0.14.css','modules/admin/assets/style.css','assets/css/workspace-dark-1.0.16.css'])await page.addStyleTag({content:await readFile(file,'utf8')});
 for(const width of [1440,390])for(const theme of ['light','dark']){
  await page.setViewportSize({width,height:900});await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
  const geometry=await page.locator('.admin-user-editor__form').evaluate(form=>{
   const bounds=form.getBoundingClientRect(),style=getComputedStyle(form);
   return {display:style.display,columns:style.gridTemplateColumns.split(' ').length,
    widths:[...form.querySelectorAll('input')].map(input=>input.getBoundingClientRect().width),
    contained:[...form.querySelectorAll('input,button')].every(el=>{const b=el.getBoundingClientRect();return b.left>=bounds.left&&b.right<=bounds.right+1&&b.bottom<=bounds.bottom+1;}),
    inRow:bounds.bottom<=form.closest('tr').getBoundingClientRect().bottom+1};
  });
  assert.equal(geometry.display,'grid');assert.equal(geometry.columns,width>760?2:1);
  assert.ok(geometry.widths.every(value=>value>=100));assert.equal(geometry.contained,true);assert.equal(geometry.inRow,true);
 }
 console.log('PASS: user editor grid, readable fields and in-flow containment on desktop/mobile in both themes');
}finally{await browser.close();}
