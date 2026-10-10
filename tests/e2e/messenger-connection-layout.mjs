import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({headless:true,...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{channel:'chromium'})});
try {
 const page=await browser.newPage();
 const css=(await Promise.all(['assets/css/workspace.css','modules/messenger/views/style.css','modules/messenger/views/visual-refresh.css','assets/css/messenger-connection-ux.css'].map(p=>readFile(p,'utf8')))).join('\n');
 for(const width of [240,280,320]) {
  await page.setContent(`<style>${css}</style><section class="messenger-app" style="display:block;width:${width}px"><header class="messenger-list__header"><div><h2 class="messenger-list__title">Чаты</h2><div class="messenger-connection" data-state="connecting" data-pending="true"><span class="messenger-connection__dot"></span><span id="messenger-connection-text">Восстанавливаем синхронизацию…</span><button class="messenger-connection__retry">Повторить</button></div></div><button class="messenger-icon-button">☆</button><button class="messenger-icon-button">+</button></header></section>`);
  const result=await page.evaluate(()=>{const status=document.querySelector('.messenger-connection');const buttons=[...document.querySelectorAll('.messenger-icon-button')];return {right:status.getBoundingClientRect().right,buttonLeft:buttons[0].getBoundingClientRect().left,buttonWidths:buttons.map(b=>b.getBoundingClientRect().width),animation:getComputedStyle(document.querySelector('.messenger-connection__dot')).animationName,textOverflow:getComputedStyle(document.querySelector('#messenger-connection-text')).textOverflow};});
  assert.ok(result.right<=result.buttonLeft,JSON.stringify({width,...result}));
  assert.ok(result.buttonWidths.every(w=>w>=38));
  assert.equal(result.animation,'none');
  assert.equal(result.textOverflow,'ellipsis');
 }
 console.log('[OK] Connection notice fits narrow headers; pending indicator does not blink');
} finally {await browser.close();}
