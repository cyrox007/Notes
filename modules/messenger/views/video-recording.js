(() => {
 'use strict';
 document.addEventListener('DOMContentLoaded',()=>{
  const app=window.wspace?.messenger,tools=document.querySelector('.messenger-composer__tools');if(!app||!tools)return;
  const path=p=>window.wspace?.path?window.wspace.path(p):p;
  const button=document.createElement('button');button.type='button';button.className='messenger-icon-button';button.id='message-video-button';button.textContent='◉';button.title='Записать видеосообщение';button.setAttribute('aria-label',button.title);tools.append(button);
  const dialog=document.createElement('dialog');dialog.className='workspace-call workspace-video-recording';
  dialog.innerHTML='<h2>Видеосообщение</h2><video playsinline muted autoplay></video><p role="status">Запись до 60 секунд.</p><div class="workspace-call__controls"><button type="button" data-video="start">Начать запись</button><button type="button" data-video="stop" hidden>Остановить</button><button type="button" data-video="send" hidden>Отправить</button><button type="button" data-video="cancel">Закрыть</button><a data-video="download" hidden download>Скачать запись</a></div>';
  document.body.append(dialog);
  const preview=dialog.querySelector('video'),status=dialog.querySelector('[role=status]'),start=dialog.querySelector('[data-video=start]'),stop=dialog.querySelector('[data-video=stop]'),send=dialog.querySelector('[data-video=send]'),download=dialog.querySelector('[data-video=download]');
  let recorder=null,stream=null,chunks=[],blob=null,objectURL=null,attachment=null,dialogUid=null,reply=null,timer=null,starting=false,uploading=false,controller=null,xhr=null,generation=0,discard=false,actualMime='';
  const formats=['video/webm;codecs=vp8,opus','video/webm;codecs=vp9,opus','video/mp4;codecs=avc1.42E01E,mp4a.40.2','video/mp4','video/webm'];
  function release(){stream?.getTracks().forEach(t=>t.stop());stream=null;clearInterval(timer);timer=null;app.root.dataset.videoRecording='false';}
  function reset(){generation++;discard=true;if(recorder?.state==='recording')recorder.stop();release();recorder=null;preview.srcObject=null;preview.removeAttribute('src');preview.load();if(objectURL)URL.revokeObjectURL(objectURL);objectURL=null;blob=null;chunks=[];attachment=null;start.hidden=false;stop.hidden=send.hidden=download.hidden=true;}
  function close(){if(uploading){status.textContent='Отменяем отправку…';controller?.abort();xhr?.abort();return;}if(blob&&!window.confirm('Удалить неотправленную видеозапись?'))return;reset();dialog.close();}
  button.addEventListener('click',()=>{
   if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){app.showToast('Для записи видео нужен HTTPS и браузер с поддержкой камеры');return;}
   if(!app.currentDialog?.uid){app.showToast('Сначала выберите диалог');return;}
   if(app.root.dataset.voiceRecording==='true'||app.root.dataset.callActive==='true'){app.showToast('Завершите запись или звонок');return;}
   dialogUid=app.currentDialog.uid;reply=app.replyTo?.uid||'';discard=false;status.textContent='Запись до 60 секунд. Перед отправкой можно просмотреть.';dialog.showModal();
  });
  start.addEventListener('click',async()=>{
   if(starting||recorder?.state==='recording')return;starting=true;start.disabled=true;const token=generation;
   try{
    const mime=formats.find(value=>MediaRecorder.isTypeSupported(value));if(!mime)throw new Error('Браузер не поддерживает совместимый формат видеозаписи');
    const acquired=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true},video:{facingMode:{ideal:'user'},width:{ideal:640,max:1280},height:{ideal:480,max:720},frameRate:{ideal:24,max:30}}});
    if(token!==generation||!dialog.open){acquired.getTracks().forEach(t=>t.stop());return;}
    stream=acquired;preview.srcObject=stream;preview.controls=false;preview.muted=true;void preview.play().catch(()=>{});
    recorder=new MediaRecorder(stream,{mimeType:mime,videoBitsPerSecond:650000,audioBitsPerSecond:48000});actualMime=recorder.mimeType||mime;
    chunks=[];discard=false;let size=0;const began=Date.now();app.root.dataset.videoRecording='true';
    recorder.ondataavailable=event=>{if(event.data.size>0){chunks.push(event.data);size+=event.data.size;}if(size>=8*1024*1024&&recorder?.state==='recording')recorder.stop();};
    recorder.onerror=()=>{status.textContent='Запись прервалась. Доступный фрагмент можно сохранить.';if(recorder?.state==='recording')recorder.stop();};
    recorder.onstop=()=>{
     release();if(discard||token!==generation)return;blob=new Blob(chunks,{type:actualMime});chunks=[];
     if(blob.size===0){status.textContent='Получилась пустая запись. Повторите запись.';start.hidden=false;stop.hidden=true;return;}
     objectURL=URL.createObjectURL(blob);preview.srcObject=null;preview.src=objectURL;preview.controls=true;preview.autoplay=false;preview.muted=false;preview.load();
     stop.hidden=true;send.hidden=download.hidden=false;download.href=objectURL;download.download=`video-${Date.now()}.${actualMime.includes('mp4')?'mp4':'webm'}`;status.textContent='Просмотрите запись перед отправкой.';
    };
    recorder.start(500);start.hidden=true;stop.hidden=false;
    timer=setInterval(()=>{const seconds=Math.floor((Date.now()-began)/1000);status.textContent=`Запись: ${seconds} / 60 сек`;if(seconds>=60&&recorder?.state==='recording')recorder.stop();},250);
   }catch(error){release();status.textContent=error.name==='NotAllowedError'?'Разрешите доступ к камере и микрофону.':error.message;}
   finally{starting=false;start.disabled=false;}
  });
  stop.addEventListener('click',()=>{if(recorder?.state==='recording')recorder.stop();});
  function upload(){return new Promise((resolve,reject)=>{
   const form=new FormData();form.set('dialog_uid',dialogUid);form.set('file',new File([blob],download.download,{type:actualMime}));
   xhr=new XMLHttpRequest();xhr.open('POST',path('/messenger/upload'));xhr.responseType='json';xhr.timeout=90000;
   xhr.upload.onprogress=event=>{if(event.lengthComputable)status.textContent=`Загрузка: ${Math.round(event.loaded/event.total*100)}%`;};
   xhr.onload=()=>{if(xhr.status>=200&&xhr.status<300&&xhr.response?.success)resolve(xhr.response.attachment);else reject(new Error(xhr.response?.message||`Ошибка загрузки ${xhr.status}`));};
   xhr.onerror=xhr.ontimeout=()=>reject(new Error('Не удалось загрузить запись.'));xhr.onabort=()=>reject(new Error('Отправка отменена.'));xhr.send(form);
  });}
  send.addEventListener('click',async()=>{
   if(!blob||uploading)return;uploading=true;send.disabled=true;controller=new AbortController();
   try{
    if(!attachment)attachment=await upload();
    if(attachment?.media_kind!=='video')throw new Error('Сервер не распознал видеозапись');
    status.textContent='Подтверждаем отправку…';
    const response=await fetch(path(`/messenger/recorded/${encodeURIComponent(attachment.uid)}/send`),{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({reply_to_uid:reply}),signal:controller.signal});
    const payload=await response.json();if(!response.ok||!payload.success)throw new Error(payload.message||'Не удалось подтвердить отправку');
    if(app.currentDialog?.uid===dialogUid)app.sendEvent('MessangerSocket:load',{dialog_uid:dialogUid});app.sendEvent('MessangerSocket:get_dialogs',{});
    reset();dialog.close();app.showToast('Видеосообщение отправлено');
   }catch(error){status.textContent=`${error.name==='AbortError'?'Отправка отменена.':error.message} Запись не потеряна: повторите отправку или скачайте её.`;send.textContent='Повторить отправку';}
   finally{uploading=false;send.disabled=false;controller=null;xhr=null;}
  });
  dialog.querySelector('[data-video=cancel]').addEventListener('click',close);dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  window.addEventListener('pagehide',()=>{controller?.abort();xhr?.abort();reset();});
 });
})();
