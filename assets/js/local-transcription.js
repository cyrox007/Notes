(() => {
 'use strict';
 const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
 let running=false;
 async function transcribe(url,lang,onText,signal){
  if(running)throw new Error('Дождитесь завершения текущей расшифровки');
  if(!window.isSecureContext||!Recognition||typeof Recognition.available!=='function')throw new Error('Локальная расшифровка недоступна в этом браузере. Аудио никуда не отправлено.');
  const recognition=new Recognition();
  if(!('processLocally' in recognition))throw new Error('Браузер не поддерживает локальное распознавание. Внешнее распознавание отключено.');
  recognition.processLocally=true;
  const availability=await Recognition.available({langs:[lang],processLocally:true});
  if(availability!=='available')throw new Error(availability==='downloadable'||availability==='downloading'?'Локальный языковой пакет не установлен. Расшифровка в этом браузере пока недоступна.':'Локальное распознавание выбранного языка не поддерживается браузером.');
  const target=new URL(url,location.href);
  if(target.origin!==location.origin)throw new Error('Разрешены только записи этой установки Notes');
  if(signal?.aborted)throw new DOMException('Отменено','AbortError');
  if(running)throw new Error('Дождитесь завершения текущей расшифровки');
  running=true;
  let context=null,source=null,stopTimer=null,timeout=null;
  try{
   const response=await fetch(target,{credentials:'same-origin',signal});
   if(!response.ok)throw new Error('Запись недоступна');
   if(Number(response.headers.get('Content-Length'))>25*1024*1024)throw new Error('Запись слишком большая для локальной расшифровки');
   const bytes=await response.arrayBuffer();if(bytes.byteLength>25*1024*1024)throw new Error('Запись слишком большая для локальной расшифровки');
   context=new (window.AudioContext||window.webkitAudioContext)();
   const buffer=await context.decodeAudioData(bytes);if(buffer.duration>600)throw new Error('Для локальной расшифровки выберите запись до 10 минут');
   if(signal?.aborted)throw new DOMException('Отменено','AbortError');
   await context.resume();source=context.createBufferSource();source.buffer=buffer;
   const destination=context.createMediaStreamDestination();source.connect(destination);
   const track=destination.stream.getAudioTracks()[0];if(!track||track.readyState!=='live')throw new Error('Браузер не поддерживает распознавание аудиозаписей');
   recognition.lang=lang;recognition.continuous=true;recognition.interimResults=true;recognition.maxAlternatives=1;
   return await new Promise((resolve,reject)=>{
    const segments=new Map();let ended=false,done=false;
    const text=()=>[...segments.entries()].sort((a,b)=>a[0]-b[0]).map(([,s])=>s).join(' ').trim();
    const finish=(error)=>{if(done)return;done=true;signal?.removeEventListener('abort',abort);if(error)reject(error);else resolve(text());};
    const abort=()=>{recognition.abort();finish(new DOMException('Отменено','AbortError'));};signal?.addEventListener('abort',abort,{once:true});
    recognition.onresult=event=>{for(let i=event.resultIndex;i<event.results.length;i++){if(event.results[i].isFinal)segments.set(i,event.results[i][0].transcript);}onText?.(text());};
    recognition.onerror=event=>finish(new Error(`Локальная расшифровка: ${event.error}. Внешние сервисы не используются.`));
    recognition.onend=()=>finish(!ended?new Error('Распознавание прервалось. Полученный фрагмент сохранён в поле ниже.'):text()===''?new Error('Речь не распознана. Проверьте язык и качество записи.'):null);
    recognition.onstart=()=>{if(signal?.aborted){abort();return;}source.start();};
    source.onended=()=>{ended=true;stopTimer=setTimeout(()=>recognition.stop(),900);};
    timeout=setTimeout(()=>{recognition.abort();finish(new Error('Превышено время локальной расшифровки'));},Math.ceil(buffer.duration*1000)+15000);
    // Audio is routed into a local track, never played through speakers or sent to a server.
    try{recognition.start(track);}catch(error){finish(error);}
   });
  }finally{
   clearTimeout(stopTimer);clearTimeout(timeout);try{recognition.abort();}catch(_){}
   try{source?.stop();}catch(_){}if(context)await context.close();running=false;
  }
 }
 window.wspace=window.wspace||{};window.wspace.localTranscription=transcribe;
 function attach(audio){
  if(audio.dataset.localTranscription==='1')return;audio.dataset.localTranscription='1';
  const panel=document.createElement('div');panel.className='local-transcription';
  const language=document.createElement('select');language.setAttribute('aria-label','Язык записи');
  for(const [value,label] of [['ru-RU','Русский'],['en-US','English']]){const option=document.createElement('option');option.value=value;option.textContent=label;language.append(option);}
  const button=document.createElement('button');button.type='button';button.textContent='Расшифровать на устройстве';
  const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Отмена';cancel.hidden=true;
  const status=document.createElement('p');status.setAttribute('role','status');status.textContent='Аудио обрабатывается только на этом устройстве.';
  const text=document.createElement('textarea');text.readOnly=false;text.hidden=true;text.rows=4;text.setAttribute('aria-label','Расшифровка записи');
  const insert=document.createElement('button');insert.type='button';insert.hidden=true;
  const editor=audio.closest('[data-note-editor-013]')?.querySelector('textarea[name="content"]')||document.querySelector('.messenger-composer textarea');
  insert.textContent=audio.closest('[data-note-editor-013]')?'Вставить в заметку':'Вставить в сообщение';
  insert.addEventListener('click',()=>{if(!editor||!text.value.trim())return;editor.setRangeText(text.value,editor.selectionStart,editor.selectionEnd,'end');editor.dispatchEvent(new Event('input',{bubbles:true}));editor.focus();});
  panel.append(language,button,cancel,insert,status,text);audio.parentElement.append(panel);
  let controller=null;
  button.addEventListener('click',async()=>{
   controller=new AbortController();button.disabled=true;language.disabled=true;cancel.hidden=false;status.textContent='Проверяем локальное распознавание…';
   try{
    const url=audio.currentSrc||audio.src||audio.querySelector('source')?.src;
    if(!url)throw new Error('Запись не найдена');
    const result=await transcribe(url,language.value,value=>{text.hidden=false;text.value=value;status.textContent='Расшифровываем на устройстве…';},controller.signal);
    text.value=result;text.hidden=false;insert.hidden=!editor;status.textContent='Расшифровка готова. Проверьте текст перед использованием.';
   }catch(error){status.textContent=error.name==='AbortError'?'Расшифровка отменена.':error.message;}
   finally{button.disabled=false;language.disabled=false;cancel.hidden=true;controller=null;}
  });
  cancel.addEventListener('click',()=>controller?.abort());
 }
 function scan(){document.querySelectorAll('.messenger-media--voice audio,.attachment-item--voice audio').forEach(attach);}
 function boot(){scan();let queued=false;new MutationObserver(()=>{if(!queued){queued=true;queueMicrotask(()=>{queued=false;scan();});}}).observe(document.body,{childList:true,subtree:true});}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
