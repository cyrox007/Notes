(() => {
 'use strict';
 const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
 let running=false;
 async function transcribe(url,lang,onText,signal){
  if(running)throw new Error('Дождитесь завершения текущей расшифровки');
  if(!window.isSecureContext)throw new Error('Для расшифровки откройте сайт по HTTPS без предупреждения о сертификате. Если такого адреса нет, обратитесь к администратору сайта.');
  if(!Recognition||typeof Recognition.available!=='function')throw new Error('Этот браузер не предоставляет локальное распознавание. Обновите браузер или попробуйте другой с поддержкой распознавания на устройстве. Запись можно прослушать без расшифровки.');
  const recognition=new Recognition();
  if(!('processLocally' in recognition))throw new Error('Этот браузер не поддерживает распознавание на устройстве. Попробуйте другой браузер с этой возможностью. Облачное распознавание в Notes отключено.');
  recognition.processLocally=true;
  if(signal?.aborted)throw new DOMException('Отменено','AbortError');
  let availabilityTimer=null,abortAvailability=null;
  let availability;
  try{
   availability=await Promise.race([
    Recognition.available({langs:[lang],processLocally:true}),
    new Promise((_,reject)=>{
     availabilityTimer=setTimeout(()=>reject(new Error('Браузер не ответил на проверку локального языка. Аудио никуда не отправлено.')),5000);
     abortAvailability=()=>reject(new DOMException('Отменено','AbortError'));
     signal?.addEventListener('abort',abortAvailability,{once:true});
    })
   ]);
  }finally{clearTimeout(availabilityTimer);if(abortAvailability)signal?.removeEventListener('abort',abortAvailability);}
  if(availability!=='available')throw new Error(availability==='downloading'?'Браузер загружает языковой пакет. Дождитесь завершения и повторите расшифровку.':availability==='downloadable'?'Для выбранного языка не установлен пакет распознавания браузера. Notes пока не умеет устанавливать его. Язык интерфейса и клавиатуры этого не исправит. Если в браузере нет установки пакета, расшифровка здесь пока недоступна.':'Браузер не предоставляет локальное распознавание выбранного языка. Попробуйте другой браузер с поддержкой этого языка. Менять язык записи стоит только если речь действительно на другом языке.');
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
    recognition.onerror=event=>{
     const explanations={
      'not-allowed':'Браузер запретил распознавание. Проверьте разрешения сайта и ограничения браузера; на рабочем компьютере обратитесь к системному администратору.',
      'service-not-allowed':'Распознавание запрещено настройками браузера или политикой организации. Проверьте их или обратитесь к системному администратору.',
      'language-not-supported':'Пакет выбранного языка недоступен. Проверьте поддержку этого языка в браузере. Notes пока не умеет устанавливать языковые пакеты.',
      'no-speech':'Речь не обнаружена. Прослушайте запись, проверьте выбранный язык и слышимость голоса.',
      'audio-capture':'Браузер не смог прочитать звук записи. Проверьте, воспроизводится ли она, и повторите попытку.',
      'network':'Браузер сообщил об ошибке своего сервиса распознавания. Повторите попытку или попробуйте другой браузер с локальным распознаванием.',
      'aborted':'Браузер прервал распознавание. Повторите попытку.'
     };
     finish(new Error(explanations[event.error]||`Браузер не смог расшифровать запись (код: ${event.error}). Повторите попытку; если ошибка повторяется, сообщите этот код администратору.`));
    };
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
  const help=document.createElement('details');help.className='local-transcription__help';
  const summary=document.createElement('summary');summary.textContent='Что нужно для расшифровки?';
  const requirements=document.createElement('p');requirements.textContent='Нужны HTTPS без предупреждения о сертификате, браузер с локальным распознаванием записей и установленный пакет языка речи. Поддержка зависит от браузера и языка; обновление браузера не гарантирует её появления. Для записи микрофон нужен, для расшифровки готового файла говорить в него не нужно.';
  const packages=document.createElement('p');packages.textContent='Выберите язык, на котором говорят в записи, и нажмите «Расшифровать на устройстве». Notes проверит доступность. Установка языка Windows, клавиатуры или интерфейса браузера не заменяет пакет распознавания. Notes пока не устанавливает такие пакеты; если браузер их не предоставляет, запись остаётся доступна для прослушивания.';
  const privacy=document.createElement('p');privacy.textContent='Распознавание выполняется средствами браузера на вашем устройстве. Notes не отправляет звук во внешние сервисы распознавания. Доступны записи до 10 минут и 25 МБ. Полученный текст нужно проверить.';
  help.append(summary,requirements,packages,privacy);
  panel.append(language,button,cancel,insert,status,help,text);audio.parentElement.append(panel);
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
