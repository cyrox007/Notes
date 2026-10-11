import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const php=process.env.E2E_PHP_BINARY||'php';
function render(archive=false,shared=false){
 const fixture=`class V {function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function route($n,$p=[]){return '/'.$n.'/'.($p['uid']??'');}function csrfInput(){return '';}function moduleAsset($m,$a){return '';}function layout($n,$d,$c){return $c;}function partial($n,$d){extract($d);$view=$this;ob_start();require 'modules/tasks/views/elements/task_item/index.php';return ob_get_clean();}}$view=new V();$currentFilter='${archive?'archive':'all'}';$tasks=[];foreach(['pending','studying','in_progress','review','completed'] as $status){$tasks[]=['id'=>count($tasks)+1,'uid'=>$status,'title'=>'Task '.$status,'status'=>$status,'priority'=>'medium','archived_at'=>${archive?"'2026-10-11'":'null'}];}$boardTasks=$tasks;$selectedBoard=['uid'=>'board','can_write'=>true,'can_manage'=>false];$policies=[];require 'modules/tasks/views/${shared?'boards':'index'}.php';`;
 return execFileSync(php,['-r',fixture],{encoding:'utf8'});
}
const styles=await Promise.all(['style.css','hardening.css','kanban.css','boards.css'].map(n=>readFile('modules/tasks/assets/'+n,'utf8')));
const script=await readFile('modules/tasks/assets/tasks-kanban.js','utf8');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try {
 const page=await browser.newPage();await page.route('https://fixture.test/**',route=>route.fulfill({body:'fixture'}));await page.goto('https://fixture.test');
 for(const width of [390,1440]){
  await page.setViewportSize({width,height:900});
  await page.setContent('<style>body{margin:12px}*{box-sizing:border-box}'+styles.join('\n')+'</style>'+render());
  await page.addScriptTag({content:script});
  assert.deepEqual(await page.locator('.tasks-board__column h2').allTextContents(),['Запланировано','Изучение','В работе','Проверка','Завершено']);
  for(const status of ['pending','studying','in_progress','review','completed']){
   assert.equal(await page.locator('.tasks-board__column--'+status+' .task-item').count(),1);
   await page.locator('.tasks-board__quick-add[data-status="'+status+'"]').click();
   assert.equal(await page.locator('#create-task-modal input[name=status]').inputValue(),status);
  }
  assert.equal(await page.locator('.task-archive-form input[name=archived][value="1"]').count(),5);
  await page.setContent('<style>'+styles.join('\n')+'</style>'+render(true));await page.addScriptTag({content:script});
  assert.equal(await page.locator('.tasks-board').count(),0);
  assert.equal(await page.locator('.task-archive-form input[name=archived][value="0"]').count(),5);
  assert.equal(await page.locator('.task-status-toggle:disabled').count(),5);
  assert.equal(await page.locator('.task-complete-toggle:disabled').count(),5);
 }
 await page.setViewportSize({width:1920,height:1080});
 await page.setContent('<style>*{box-sizing:border-box}body{margin:0}.workspace-region{margin-left:204px}.content-wrapper{width:min(100%,1320px);margin:auto;padding:22px 24px}'+styles.join('\n')+'</style><div class="workspace-region"><main class="content-wrapper">'+render()+'</main></div>');
 await page.addScriptTag({content:script});
 assert.equal(Math.round((await page.locator('.content-wrapper').boundingBox()).width),1716);
 assert.ok((await page.locator('.tasks-board__column').first().boundingBox()).width>=300);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
 await page.setContent(render(false,true));
 assert.equal(await page.locator('.task-board-column').count(),5);
 assert.equal(await page.locator('.task-board-archive').count(),1);
 assert.equal(await page.locator('.task-board-archive').getAttribute('open'),null);
 assert.equal(await page.locator('.task-board-column .task-board-card').count(),5);
 await page.setContent(render(true,true));await page.locator('.task-board-archive summary').click();
 assert.equal(await page.locator('.task-board-column .task-board-card').count(),0);
 assert.equal(await page.locator('.task-board-archive .task-board-card[draggable=false]').count(),5);
 assert.equal(await page.locator('.task-board-archive button').filter({hasText:'Восстановить'}).count(),5);
 console.log('PASS five workflow columns and quick-add, separate archive restore controls, disabled archived status, shared archive disclosure');
}finally{await browser.close();}
