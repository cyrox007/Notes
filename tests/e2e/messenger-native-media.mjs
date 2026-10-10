import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {readFile} from 'node:fs/promises';
const require=createRequire(import.meta.url);
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const origin=process.env.E2E_NATIVE_MEDIA_ORIGIN||'http://127.0.0.1:18116';
const browser=await chromium.launch({headless:true,args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--autoplay-policy=no-user-gesture-required'],...(process.env.E2E_BROWSER_EXECUTABLE?{executablePath:process.env.E2E_BROWSER_EXECUTABLE}:{})});
try{
 const contexts=await Promise.all([1,2].map(async id=>{const c=await browser.newContext({permissions:['camera','microphone']});await c.addCookies([{name:'fixture_user',value:String(id),url:origin}]);return c;}));
 contexts.forEach(c=>{c.setDefaultTimeout(15000);c.setDefaultNavigationTimeout(15000);});
 const pages=await Promise.all(contexts.map(c=>c.newPage()));
 const errors=[],externalRequests=[];pages.forEach(p=>p.on('request',r=>{if(new URL(r.url()).origin!==origin)externalRequests.push(r.url());}));pages.forEach(p=>p.on('pageerror',e=>errors.push(e.message)));
 await Promise.all(pages.map(p=>p.goto(origin)));
 for(const mode of ['audio','video']){
  await pages[0].locator(`[data-call-mode=${mode}]`).click();
  await pages[1].locator('[data-call-action=accept]').waitFor({state:'visible',timeout:15000});
  await pages[1].locator('[data-call-action=accept]').click();
  for(const page of pages){
   await page.locator('.workspace-call__status').filter({hasText:'Соединение установлено'}).waitFor({timeout:30000});
   await page.waitForFunction(()=>document.querySelector('.workspace-call__remote').srcObject?.getAudioTracks().some(t=>t.readyState==='live'));
   if(mode==='video')await page.waitForFunction(()=>document.querySelector('.workspace-call__remote').videoWidth>0);
   await page.evaluate(()=>{window.fixtureTracks=document.querySelector('.workspace-call__local').srcObject.getTracks();});
  }
  await pages[0].locator('[data-call-action=mute]').click();
  assert.equal(await pages[0].evaluate(()=>fixtureTracks.find(t=>t.kind==='audio').enabled),false);
  await pages[0].locator('[data-call-action=end]').click();
  for(const page of pages){await page.locator('dialog.workspace-call').first().waitFor({state:'hidden',timeout:10000});assert.equal(await page.evaluate(()=>fixtureTracks.every(t=>t.readyState==='ended')),true);}
  console.log(`PASS: ${mode} call, remote media and track cleanup`);
 }
 const voicePage=pages[0];let voiceUploads=0,voiceSends=0;
 await voicePage.route('**/messenger/voice-upload',async route=>{voiceUploads++;await route.fulfill({json:{success:true,attachment:{uid:'fixture-voice',media_kind:'voice'}}});});
 await voicePage.route('**/messenger/recorded/fixture-voice/send',async route=>{voiceSends++;await route.fulfill({status:voiceSends===1?503:200,json:voiceSends===1?{success:false,message:'test voice outage'}:{success:true}});});
 await voicePage.locator('#message-voice-button').click();await voicePage.locator('.messenger-voice-recorder').waitFor({state:'visible'});await voicePage.waitForTimeout(1100);await voicePage.locator('.messenger-voice-recorder__send').click();
 await voicePage.locator('.messenger-voice-recovery').waitFor({state:'visible'});assert.equal(voiceUploads,1);
 await voicePage.getByRole('button',{name:'Повторить отправку голосового'}).click();await voicePage.locator('.messenger-voice-recovery').waitFor({state:'hidden'});assert.equal(voiceUploads,1);assert.equal(voiceSends,2);
 console.log('PASS: voice confirmation retry');
 // A real MediaRecorder recording survives a failed send and retries the same attachment.
 const page=pages[0];let uploads=0,sends=0;let recorded=null;
 await page.route('**/messenger/upload',async route=>{uploads++;recorded=route.request().postDataBuffer();await route.fulfill({json:{success:true,attachment:{uid:'fixture-video',media_kind:'video'}}});});
 await page.route('**/messenger/recorded/fixture-video/send',async route=>{sends++;await route.fulfill({status:sends===1?503:200,json:sends===1?{success:false,message:'test outage'}:{success:true}});});
 await page.locator('#message-video-button').click();await page.locator('[data-video=start]').click();
 await page.locator('[data-video=stop]').waitFor({state:'visible'});await page.waitForTimeout(1700);await page.locator('[data-video=stop]').click();
 await page.locator('[data-video=send]').waitFor({state:'visible'});assert.equal(await page.locator('[data-video=download]').isVisible(),true);
 await page.locator('[data-video=send]').click();await page.locator('.workspace-video-recording [role=status]').filter({hasText:'Запись не потеряна'}).waitFor();
 assert.equal(uploads,1);assert.ok(recorded.length>1000);await page.locator('[data-video=send]').click();await page.locator('.workspace-video-recording').waitFor({state:'hidden'});assert.equal(uploads,1);assert.equal(sends,2);
 console.log('PASS: video confirmation retry');
 // Local STT fails closed without an installed on-device language, before reading audio.
 await page.addScriptTag({content:await readFile('assets/js/local-transcription.js','utf8')});
 const available=await page.evaluate(async()=>{
  try{await window.wspace.localTranscription('/does-not-exist.wav','ru-RU');return 'unexpected-success';}catch(error){return error.message;}
 });
 assert.notEqual(available,'unexpected-success');
 // Drive the local-only STT adapter with a deterministic engine; this tests plumbing, not model accuracy.
 const stt=await contexts[0].newPage();await stt.goto(origin);
 let audioReads=0;
 const wav=Buffer.alloc(44+16000);wav.write('RIFF');wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(8000,24);wav.writeUInt32LE(16000,28);wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(16000,40);
 await stt.route('**/fixture.wav',async route=>{audioReads++;await route.fulfill({contentType:'audio/wav',body:wav});});
 await stt.evaluate(()=>{
  window.fixtureAvailability='unavailable';window.fixtureRecognition={};
  window.SpeechRecognition=class{
   constructor(){this.processLocally=false;}
   static async available(options){window.fixtureRecognition.options=options;return window.fixtureAvailability;}
   start(track){window.fixtureRecognition.local=this.processLocally;window.fixtureRecognition.track=track.kind;queueMicrotask(()=>{this.onstart?.();this.onresult?.({resultIndex:0,results:[Object.assign([{transcript:'Проверка локальной расшифровки'}],{isFinal:true})]});});}
   stop(){this.onend?.();}abort(){}
  };
 });
 await stt.addScriptTag({content:await readFile('assets/js/local-transcription.js','utf8')});
 const unsupported=await stt.evaluate(async()=>{try{await wspace.localTranscription('/fixture.wav','ru-RU');}catch(error){return error.message;}});
 assert.match(unsupported,/не поддерживается/);assert.equal(audioReads,0,'no audio read when the on-device language is unavailable');
 const transcript=await stt.evaluate(async()=>{window.fixtureAvailability='available';return await wspace.localTranscription('/fixture.wav','ru-RU');});
 assert.equal(transcript,'Проверка локальной расшифровки');assert.equal(audioReads,1);
 const engine=await stt.evaluate(()=>fixtureRecognition);assert.equal(engine.local,true);assert.equal(engine.options.processLocally,true);assert.equal(engine.track,'audio');
 await stt.close();
 assert.deepEqual(errors,[]);assert.deepEqual(externalRequests,[],'product flow must not use external services');
 console.log('PASS: real direct audio/video peer connections, mute, track cleanup, real video recording, retained retry and local-only transcription availability');
}finally{await browser.close();}
