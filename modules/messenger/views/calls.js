(() => {
 'use strict';
 document.addEventListener('DOMContentLoaded',()=>{
  const app=window.wspace?.messenger;
  if(!app) return;
  const path=p=>window.wspace?.path?window.wspace.path(p):p;
  const actions=document.querySelector('.messenger-chat__actions');
  if(!actions) return;
  const dialog=document.createElement('dialog');dialog.className='workspace-call';
  dialog.innerHTML='<h2>Звонок</h2><p class="workspace-call__status" role="status"></p><div class="workspace-call__videos"><video class="workspace-call__remote" autoplay playsinline></video><video class="workspace-call__local" autoplay playsinline muted></video></div><div class="workspace-call__controls"><button type="button" data-call-action="accept">Ответить</button><button type="button" data-call-action="mute">Микрофон</button><button type="button" data-call-action="camera">Камера</button><button type="button" data-call-action="end">Завершить</button></div><p class="workspace-call__hint">Прямое соединение между участниками. Если сеть блокирует его, звонок не сможет соединиться.</p>';
  document.body.appendChild(dialog);
  const status=dialog.querySelector('[role=status]'), local=dialog.querySelector('.workspace-call__local'),remote=dialog.querySelector('.workspace-call__remote');
  const accept=dialog.querySelector('[data-call-action=accept]'),mute=dialog.querySelector('[data-call-action=mute]'),camera=dialog.querySelector('[data-call-action=camera]');
  let call=null,pc=null,stream=null,epoch=0,polling=false,lastPoll=Date.now(),retries=0,restarting=false,deadline=null,disconnectTimer=null,remoteDescription='';
  const supported=()=>window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.RTCPeerConnection;
  async function request(method,data){
   const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
   try {
   const response=await fetch(path('/messenger/calls'),{method,signal:controller.signal,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest',...(data?{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}:{})},...(data?{body:new URLSearchParams(data).toString()}:{})});
   const payload=await response.json();if(!response.ok||!payload.success) throw new Error(payload.message||'Сигнализация недоступна');return payload;
   } finally {clearTimeout(timeout);}
  }
  const signal=(action,sdp='')=>request('POST',{id:call.id,dialog_uid:call.dialog_uid,mode:call.mode,action,sdp});
  function display(text){status.textContent=text;}
  function cleanup(text='Звонок завершён'){
   epoch++;clearTimeout(deadline);clearTimeout(disconnectTimer);deadline=disconnectTimer=null;
   if(pc){pc.onconnectionstatechange=null;pc.close();} pc=null;
   stream?.getTracks().forEach(t=>t.stop());stream=null;local.srcObject=remote.srcObject=null;call=null;remoteDescription='';retries=0;restarting=false;
   app.root.dataset.callActive='false';dialog.close();if(text) app.showToast(text);
  }
  async function end(){
   const current=call;
   cleanup();
   if(current){try{await request('POST',{id:current.id,dialog_uid:current.dialog_uid,mode:current.mode,action:current.state==='ringing'&&current.role==='callee'?'reject':'hangup'});}catch(_) { /* The server lease expires if offline. */ }}
  }
  function show(incoming){
   app.root.dataset.callActive='true';
   accept.hidden=!incoming;mute.hidden=incoming;camera.hidden=incoming||call.mode!=='video';
   local.hidden=call.mode!=='video';remote.classList.toggle('workspace-call__remote--audio',call.mode!=='video');
   mute.setAttribute('aria-pressed','false');camera.setAttribute('aria-pressed','false');mute.textContent='Выключить микрофон';camera.textContent='Выключить камеру';
   dialog.querySelector('h2').textContent=call.mode==='video'?'Видеозвонок':'Аудиозвонок';
   if(!dialog.open)dialog.showModal();
  }
  function startDeadline(){clearTimeout(deadline);deadline=setTimeout(()=>{if(pc?.connectionState!=='connected')void end().then(()=>app.showToast('Не удалось установить прямое соединение. Возможно, сеть блокирует звонки.'));},25000);}
  async function description(type,options){
   const active=pc, token=epoch;
   await active.setLocalDescription(type==='offer'?await active.createOffer(options):await active.createAnswer());
   if(active.iceGatheringState!=='complete') await new Promise(resolve=>{
    const done=()=>{clearTimeout(timer);active.removeEventListener('icegatheringstatechange',change);resolve();};
    const change=()=>{if(active.iceGatheringState==='complete')done();};
    const timer=setTimeout(done,5000);active.addEventListener('icegatheringstatechange',change);
   });
   if(token!==epoch||pc!==active)throw new Error('Звонок отменён');
   return active.localDescription.sdp;
  }
  async function restart(){
   if(!call||!pc||call.role!=='caller'||restarting)return;
   if(retries>=2){await end();app.showToast('Не удалось восстановить прямое соединение');return;}
   restarting=true;retries++;display('Восстанавливаем соединение…');
   try{await signal('offer',await description('offer',{iceRestart:true}));remoteDescription='';startDeadline();}
   catch(error){cleanup(error.message);}finally{restarting=false;}
  }
  async function prepare(){
   const token=epoch;
   const acquired=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:call.mode==='video'?{width:{ideal:640},height:{ideal:480},frameRate:{ideal:24,max:30}}:false});
   if(token!==epoch){acquired.getTracks().forEach(t=>t.stop());throw new Error('Звонок отменён');}
   stream=acquired;local.srcObject=stream;
   pc=new RTCPeerConnection({iceServers:[]}); // No external STUN/TURN, SDK or signaling service.
   stream.getTracks().forEach(track=>pc.addTrack(track,stream));
   pc.ontrack=event=>{remote.srcObject=event.streams[0]||new MediaStream([event.track]);void remote.play().catch(()=>display('Нажмите на видео, чтобы включить звук'));};
   pc.onconnectionstatechange=()=>{
    if(!pc)return;
    if(pc.connectionState==='connected'){clearTimeout(deadline);clearTimeout(disconnectTimer);display('Соединение установлено');}
    if(pc.connectionState==='disconnected'){display('Связь прервана. Восстанавливаем…');clearTimeout(disconnectTimer);disconnectTimer=setTimeout(()=>void restart(),5000);}
    if(pc.connectionState==='failed'){display('Соединение прервано. Восстанавливаем…');startDeadline();void restart();}
   };
   startDeadline();
  }
  for(const mode of ['audio','video']){
   const button=document.createElement('button');button.type='button';button.className='messenger-icon-button';button.dataset.callMode=mode;
   button.textContent=mode==='audio'?'☎':'▣';button.title=mode==='audio'?'Аудиозвонок':'Видеозвонок';button.setAttribute('aria-label',button.title);actions.appendChild(button);
   button.addEventListener('click',async()=>{
    if(app.root.dataset.voiceRecording==='true'||app.root.dataset.videoRecording==='true'){app.showToast('Завершите текущую запись');return;}
    if(call){app.showToast('Сначала завершите текущий звонок');return;}
    if(!supported()){app.showToast('Для звонков нужен HTTPS и браузер с поддержкой камеры, микрофона и WebRTC');return;}
    if(app.currentDialog?.type!=='private'){app.showToast('Выберите личный диалог');return;}
    call={id:crypto.randomUUID(),dialog_uid:app.currentDialog.uid,mode,role:'caller',state:'ringing'};epoch++;show(false);display('Подготовка звонка…');
    try{await prepare();await signal('offer',await description('offer'));display('Вызываем собеседника…');}
    catch(error){if(call)void end();app.showToast(error.message||'Не удалось начать звонок');}
   });
  }
  accept.addEventListener('click',async()=>{
   if(!call||pc)return;
   if(!supported()){app.showToast('Браузер не поддерживает звонки или сайт открыт без HTTPS');return;}
   accept.disabled=true;display('Подключаемся…');
   try{await prepare();const offer=call.description;await pc.setRemoteDescription({type:'offer',sdp:offer});remoteDescription=offer;await signal('answer',await description('answer'));call.state='active';show(false);}
   catch(error){const failed=call;cleanup(error.message||'Не удалось ответить');if(failed)void request('POST',{id:failed.id,dialog_uid:failed.dialog_uid,mode:failed.mode,action:'reject'}).catch(()=>{});}finally{accept.disabled=false;}
  });
  mute.addEventListener('click',()=>{const tracks=stream?.getAudioTracks()||[];const muted=tracks.some(t=>t.enabled);tracks.forEach(t=>t.enabled=!muted);mute.setAttribute('aria-pressed',String(muted));mute.textContent=muted?'Включить микрофон':'Выключить микрофон';});
  camera.addEventListener('click',()=>{const tracks=stream?.getVideoTracks()||[];const off=tracks.some(t=>t.enabled);tracks.forEach(t=>t.enabled=!off);camera.setAttribute('aria-pressed',String(off));camera.textContent=off?'Включить камеру':'Выключить камеру';});
  dialog.querySelector('[data-call-action=end]').addEventListener('click',()=>void end());
  dialog.addEventListener('cancel',event=>{event.preventDefault();void end();});remote.addEventListener('click',()=>void remote.play());
  async function poll(){
   if(polling||app.sessionUnavailable)return;polling=true;const token=epoch;
   try{
    const payload=await request('GET');if(token!==epoch)return;lastPoll=Date.now();
    if(!call){const incoming=payload.calls.find(c=>c.role==='callee'&&c.state==='ringing');if(incoming){call=incoming;epoch++;show(true);display('Входящий звонок');}}
    else{
     const update=payload.calls.find(c=>c.id===call.id);
     if(update){
      if(!['ringing','active'].includes(update.state)){cleanup(update.state==='rejected'?'Звонок отклонён':update.state==='expired'?'Время ожидания звонка истекло':'Собеседник завершил звонок');return;}
      call={...call,...update};
      if(pc&&update.description&&update.description!==remoteDescription){
       if(call.role==='caller'&&pc.signalingState==='have-local-offer'){await pc.setRemoteDescription({type:'answer',sdp:update.description});remoteDescription=update.description;}
       else if(call.role==='callee'&&call.state==='active'&&pc.signalingState==='stable'){await pc.setRemoteDescription({type:'offer',sdp:update.description});remoteDescription=update.description;await signal('answer',await description('answer'));}
      }
     }
    }
   }catch(error){if(call){display('Проверяем соединение с Notes…');if(Date.now()-lastPoll>30000)cleanup('Сигнализация звонка недоступна');}}
   finally{polling=false;}
  }
  void poll();const pollTimer=setInterval(()=>void poll(),2000);
  window.addEventListener('pagehide',()=>{clearInterval(pollTimer);if(call){const token=document.querySelector('#csrf-token-template')?.content.querySelector('input')?.value;const data=new URLSearchParams({id:call.id,dialog_uid:call.dialog_uid,action:'hangup',mode:call.mode,...(token?{csrf_token:token}:{})});navigator.sendBeacon?.(path('/messenger/calls'),data);}cleanup('');});
  document.addEventListener('wspace:messenger-session-unavailable',()=>cleanup('Сессия завершена'));
 });
})();
