<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/modules/messenger/services/MessengerCallService.php';
use App\Services\MessengerCallService;
$directory=sys_get_temp_dir().'/notes-calls-'.bin2hex(random_bytes(6));
$members=static function(int $user,string $dialog):array {
    $ids=$dialog==='private-a'?[1,2]:($dialog==='private-b'?[2,3]:[]);
    if(!in_array($user,$ids,true))throw new DomainException('No access');
    return $ids;
};
$service=new MessengerCallService($directory,$members);
function callAssert(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);}
function denied(callable $run):void {try{$run();}catch(DomainException|InvalidArgumentException){return;}throw new RuntimeException('Expected denial');}
$sdp="v=0\r\ns=test\r\n";
try {
 $id='call-0000000000001';
 $created=$service->signal(1,'private-a',$id,'offer','audio',$sdp);
 callAssert($created['state']==='ringing'&&$created['description']==='','Caller must not receive own SDP');
 callAssert($service->poll(2)[0]['description']===$sdp,'Callee receives offer');
 callAssert($service->poll(3)===[],'Third party cannot enumerate calls');
 denied(fn()=>$service->signal(3,'private-a',$id,'answer','audio',$sdp));
 denied(fn()=>$service->signal(1,'private-a',$id,'answer','audio',$sdp));
 denied(fn()=>$service->signal(3,'private-b','call-0000000000002','offer','video',$sdp));
 denied(fn()=>$service->signal(2,'private-b',$id,'hangup','audio',''));
 $answer=$service->signal(2,'private-a',$id,'answer','audio',$sdp.'a=answer');
 callAssert($answer['state']==='active','Answer activates call');
 denied(fn()=>$service->signal(2,'private-a',$id,'answer','audio',$sdp.'a=second-tab'));
 callAssert($service->poll(1)[0]['description']===$sdp.'a=answer','Caller receives answer');
 $restart=$service->signal(1,'private-a',$id,'offer','audio',$sdp.'a=restart');
 callAssert($restart['description']===''&&$restart['revision']===3,'Restart clears stale answer');
 $service->signal(1,'private-a',$id,'hangup','audio','');
 callAssert($service->poll(2)[0]['state']==='ended','Hangup reaches peer');
 callAssert($service->signal(2,'private-a',$id,'answer','audio',$sdp)['state']==='ended','Late answer cannot revive call');
 $id2='call-0000000000002';$service->signal(1,'private-a',$id2,'offer','video',$sdp);
 callAssert($service->signal(2,'private-a',$id2,'reject','video','')['state']==='rejected','Rejection reaches caller');
 $id3='call-0000000000003';$service->signal(1,'private-a',$id3,'offer','video',$sdp);
 $state=json_decode(file_get_contents($directory.'/state.json'),true);$state[$id3]['created']=time()-50;file_put_contents($directory.'/state.json',json_encode($state));
 $rows=$service->poll(1);callAssert(end($rows)['state']==='expired','Unanswered calls expire');
 denied(fn()=>$service->signal(1,'private-a','call-0000000000004','offer','video',str_repeat('x',70000)));
 echo "[OK] native calls: ACL, private signaling, busy, answer, ICE restart, hangup, late answer, rejection and expiry\n";
} finally {foreach(glob($directory.'/*')?:[]as$file)unlink($file);if(is_dir($directory))rmdir($directory);}
