<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$root=dirname(__DIR__,2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$directory=getenv('E2E_CALL_STORAGE');
if(!$directory){http_response_code(500);exit('Set E2E_CALL_STORAGE');}
if($path==='/messenger/calls'){
 require_once $root.'/modules/messenger/services/MessengerCallService.php';
 $service=new App\Services\MessengerCallService($directory,static function(int $id,string $dialog):array{if($dialog!=='private-a'||!in_array($id,[1,2],true))throw new DomainException('No access');return [1,2];});
 header('Content-Type: application/json');
 try{$id=(int)($_COOKIE['fixture_user']??0);$result=$_SERVER['REQUEST_METHOD']==='POST'?['call'=>$service->signal($id,(string)($_POST['dialog_uid']??''),(string)($_POST['id']??''),(string)($_POST['action']??''),(string)($_POST['mode']??'audio'),(string)($_POST['sdp']??''))]:['calls'=>$service->poll($id)];echo json_encode(['success'=>true]+$result);}
 catch(Throwable $error){http_response_code(403);echo json_encode(['success'=>false,'message'=>$error->getMessage()]);}exit;
}
$assets=['/calls.js'=>'modules/messenger/views/calls.js','/calls.css'=>'modules/messenger/views/calls.css','/video.js'=>'modules/messenger/views/video-recording.js','/transcription.js'=>'assets/js/local-transcription.js'];
if($path==='/voice.js'){header('Content-Type: application/javascript');echo str_replace(['{literal}','{/literal}'],'',file_get_contents($root.'/modules/messenger/views/voice.js'));exit;}
if(isset($assets[$path])){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'application/javascript'));readfile($root.'/'.$assets[$path]);exit;}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head><link rel="stylesheet" href="/calls.css"><script>
window.wspace={path:p=>p,messenger:{root:null,currentDialog:{uid:'private-a',type:'private'},showToast:text=>{window.lastToast=text;},sendEvent:()=>true,stopTyping:()=>{},clearComposeContext:()=>{},openDialog:()=>{},renderMessage:()=>document.createElement('div')}};
document.addEventListener('DOMContentLoaded',()=>{window.wspace.messenger.root=document.querySelector('.messenger-app');window.wspace.messenger.el={input:document.querySelector('textarea')};});
</script><script src="/calls.js" defer></script><script src="/video.js" defer></script><script src="/voice.js" defer></script></head><body><section class="messenger-app"><div class="messenger-chat__actions"></div><footer class="messenger-composer"><div class="messenger-composer__tools"></div><textarea></textarea></footer></section></body></html>
