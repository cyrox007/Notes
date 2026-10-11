import assert from 'node:assert/strict';
import {createRequire} from 'node:module';import {readFile} from 'node:fs/promises';import {execFileSync} from 'node:child_process';
const require=createRequire(import.meta.url);const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const php=process.env.E2E_PHP_BINARY||'php';
const html=execFileSync(php,['-r',`class FilterView {function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function route($n){return '/'.$n;}function csrfInput(){return '';}function moduleAsset($m,$a){return '';}function layout($n,$d,$c){return $c;}} $view=new FilterView();$currentFilter='overdue';require 'modules/tasks/views/index.php';`],{encoding:'utf8'});
const styles=await Promise.all(['style.css','hardening.css','kanban.css','kanban-handle.css'].map(n=>readFile('modules/tasks/assets/'+n,'utf8')));
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try {const page=await browser.newPage();
for(const width of [320,360,390,768,1440]){
 await page.setViewportSize({width,height:900});await page.setContent('<style>*{box-sizing:border-box}body{margin:12px;font:14px Arial}'+styles.join('\n')+'</style>'+html);
 const behavior=await readFile('modules/tasks/assets/tasks-page.js','utf8');await page.addScriptTag({content:"{const root=document.querySelector('.tasks');"+behavior.slice(behavior.indexOf('        const filters ='),behavior.indexOf('        const appPath ='))+'}'});
 const failures=await page.evaluate(()=>{const panel=document.querySelector('.tasks__filter-panel');const bounds=panel.getBoundingClientRect();return [...panel.querySelectorAll('summary,.filter-btn,label,select,button')].filter(e=>{const r=e.getBoundingClientRect();return r.width&&(r.left<bounds.left-1||r.right>bounds.right+1||r.left<0||r.right>innerWidth);}).map(e=>e.outerHTML);});
 assert.deepEqual(failures,[], 'Filter element outside panel at '+width);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 assert.equal(await page.locator('.tasks__filters a').count(),8);
 await page.locator('select[name=sort]').selectOption('priority');await page.locator('select[name=direction]').selectOption('asc');
 assert.equal(await page.locator('select[name=sort]').inputValue(),'priority');
 const before=await page.locator('.tasks__filter-panel').evaluate(e=>e.getBoundingClientRect().height);await page.locator('.tasks__filter-panel summary').click();await page.waitForTimeout(50);const middle=await page.locator('.tasks__filter-panel').evaluate(e=>e.getBoundingClientRect().height);assert.ok(middle<before);await page.waitForTimeout(260);assert.equal(await page.locator('.tasks__sort-form').isVisible(),false);
 await page.locator('.tasks__filter-panel summary').click();await page.waitForTimeout(300);assert.equal(await page.locator('.tasks__sort-form').isVisible(),true);
}
console.log('PASS task filters at 320/360/390/768/1440: fields and all links stay inside panel, sorting and collapse work');
}finally{await browser.close();}
