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
 await page.evaluate(()=>{window.availability='downloadable';window.SpeechRecognition=class{constructor(){this.processLocally=false;}static async available(){return window.availability;}};});
 await page.addScriptTag({content:await readFile('assets/js/local-transcription.js','utf8')});
 await page.locator('summary').click();
 assert.match(await page.locator('details').innerText(),/HTTPS/);
 assert.match(await page.locator('details').innerText(),/Notes пока не устанавливает/);
 for(const [value,expected] of [['downloadable',/не установлен пакет/],['downloading',/Дождитесь завершения/],['unavailable',/не предоставляет локальное распознавание/]]) {
  await page.evaluate(v=>window.availability=v,value);
  await page.getByRole('button',{name:'Расшифровать на устройстве',exact:true}).click();
  await page.waitForFunction(()=>!document.querySelector('.local-transcription button').disabled);
  assert.match(await page.locator('[role="status"]').innerText(),expected);
 }
 assert.equal(audioReads,0,'Unsupported recognition must not fetch audio');
 console.log('[OK] Help and actionable language availability messages; no audio fetched');
} finally {await browser.close();}
