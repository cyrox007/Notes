import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try {
 const page=await browser.newPage();let audioReads=0;
 await page.route('https://help.test/**',async route=>{if(route.request().url().endsWith('.wav'))audioReads++;await route.fulfill({contentType:'text/html',body:'<div class="attachment-item--voice"><audio src="/recording.wav" preload="none"></audio></div>'});});
 await page.goto('https://help.test/');
 await page.addStyleTag({content:(await readFile('assets/css/workspace.css','utf8'))+'\n'+(await readFile('assets/css/local-transcription.css','utf8'))});
 await page.setViewportSize({width:390,height:700});
 await page.evaluate(()=>{window.availability='downloadable';window.SpeechRecognition=class{constructor(){this.processLocally=false;}static async available(){return window.availability;}};});
 await page.addScriptTag({content:await readFile('assets/js/local-transcription.js','utf8')});
 assert.equal(await page.getByRole('button',{name:'Почему не работает?'}).count(),0);
 for(const [value,expected] of [['downloadable',/не установлен пакет/],['downloading',/Дождитесь завершения/],['unavailable',/не предоставляет локальное распознавание/]]) {
  await page.evaluate(v=>window.availability=v,value);
  await page.getByRole('button',{name:'Расшифровать на устройстве',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('.local-transcription button').disabled);
  assert.equal(await page.locator('[role="status"]').innerText(),'Не удалось расшифровать запись.');
  await page.getByRole('button',{name:'Почему не работает?',exact:true}).click();
  const dialog=page.getByRole('dialog');
  assert.match(await dialog.locator('.local-transcription-dialog__reason').innerText(),expected);
  assert.match(await dialog.innerText(),/Chrome на компьютере, версия 139/);
  assert.match(await dialog.innerText(),/Русского языка.*нет/);
  assert.match(await dialog.innerText(),/HTTPS/);
  assert.equal(await dialog.evaluate(el=>el.getBoundingClientRect().width<=innerWidth&&el.scrollWidth<=el.clientWidth),true);
  await page.keyboard.press('Escape');
  assert.equal(await page.getByRole('dialog').count(),0);
  assert.equal(await page.getByRole('button',{name:'Почему не работает?',exact:true}).evaluate(el=>document.activeElement===el),true);
 }
 assert.equal(audioReads,0,'Unsupported recognition must not fetch audio');
 console.log('[OK] Help only on failure, current reason and browser guidance, Escape and focus return; no audio fetched');
} finally {await browser.close();}
