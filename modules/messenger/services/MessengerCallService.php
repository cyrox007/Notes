<?php

declare(strict_types=1);

namespace App\Services;

require_once dirname(__DIR__, 3) . '/core/PrivateStorageResolver.php';

use Core\DatabaseManager;
use Core\PrivateStorageResolver;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/** Native same-installation signaling. Audio/video never passes through PHP. */
final class MessengerCallService
{
    private string $directory;
    private \Closure $members;

    public function __construct(?string $directory = null, ?\Closure $members = null)
    {
        $this->directory = $directory ?? (new PrivateStorageResolver())->prepare() . '/messenger-calls';
        $this->members = $members ?? static function (int $userId, string $dialogUid): array {
            $db = DatabaseManager::getInstance();
            $user = $db->fetchOne('SELECT uid FROM users WHERE id=:id AND is_active=1 LIMIT 1', [':id'=>$userId]);
            if (!$user) throw new DomainException('Требуется действующая учётная запись');
            $dialog = (new MessengerService($db))->getDialogInfo((string)$user['uid'], $dialogUid);
            if ($dialog['type'] !== 'private' || count($dialog['participants']) !== 2) {
                throw new DomainException('Звонки доступны в личных диалогах двух пользователей');
            }
            $ids=array_map(static fn(array $row): int => (int)$row['id'], $dialog['participants']);
            foreach($ids as $id) {
                if(!$db->fetchOne('SELECT id FROM users WHERE id=:id AND is_active=1 LIMIT 1',[':id'=>$id])) throw new DomainException('Собеседник недоступен');
            }
            return $ids;
        };
    }

    public function poll(int $userId): array
    {
        return $this->locked(function (array &$calls) use ($userId): array {
            $result=[];
            foreach ($calls as &$call) {
                if (!in_array($userId, [$call['caller'], $call['callee']], true)) continue;
                try { $this->authorize($userId, $call['dialog'], $call); }
                catch (DomainException) { $call['state']='ended'; continue; }
                if (in_array($call['state'], ['ringing','active'], true)) $call['seen'][(string)$userId]=time();
                $result[]=$this->view($call,$userId);
            }
            return $result;
        });
    }

    public function signal(int $userId, string $dialogUid, string $id, string $action, string $mode, string $sdp): array
    {
        if ($userId <= 0) throw new DomainException('Требуется авторизация');
        if (preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$id)!==1 || strlen($dialogUid)>128) throw new InvalidArgumentException('Некорректный идентификатор звонка');
        if (!in_array($action,['offer','answer','reject','hangup'],true)) throw new InvalidArgumentException('Неизвестное действие звонка');
        if (in_array($action,['offer','answer'],true) && (strlen($sdp)>65536 || !str_starts_with($sdp,'v=0'))) throw new InvalidArgumentException('Некорректное описание соединения');
        return $this->locked(function(array &$calls) use($userId,$dialogUid,$id,$action,$mode,$sdp):array {
            if (!isset($calls[$id])) {
                if ($action!=='offer' || !in_array($mode,['audio','video'],true)) throw new InvalidArgumentException('Звонок не найден');
                $members=$this->authorize($userId,$dialogUid);
                $peer=array_values(array_diff($members,[$userId]))[0] ?? 0;
                if ($peer<=0) throw new DomainException('Собеседник недоступен');
                foreach($calls as $existing) {
                    if (in_array($existing['state'],['ringing','active'],true)
                        && array_intersect([$userId,$peer],[$existing['caller'],$existing['callee']])) throw new DomainException('Вы или собеседник уже участвуете в звонке');
                }
                if(count($calls)>=128) throw new RuntimeException('Сервис звонков временно занят');
                $calls[$id]=['id'=>$id,'dialog'=>$dialogUid,'caller'=>$userId,'callee'=>$peer,'mode'=>$mode,'state'=>'ringing','offer'=>$sdp,'answer'=>'','created'=>time(),'updated'=>time(),'revision'=>1,'seen'=>[(string)$userId=>time(),(string)$peer=>time()]];
                return $this->view($calls[$id],$userId);
            }
            $call=&$calls[$id];
            if($call['dialog']!==$dialogUid) throw new DomainException('Звонок недоступен');
            $this->authorize($userId,$dialogUid,$call);
            if(!in_array($call['state'],['ringing','active'],true)) return $this->view($call,$userId);
            if($action==='answer') {
                if($userId!==$call['callee']) throw new DomainException('Ответить может только вызываемый участник');
                if($call['state']==='active' && $call['answer']!=='' && $call['answer']!==$sdp) throw new DomainException('Звонок уже принят в другом окне');
                $call['answer']=$sdp;$call['state']='active';
            } elseif($action==='offer') {
                if($userId!==$call['caller'] || $call['state']!=='active') throw new DomainException('Повторное предложение недоступно');
                $call['offer']=$sdp;$call['answer']='';
            } elseif($action==='reject') {
                if($userId!==$call['callee'] || $call['state']!=='ringing') throw new DomainException('Отклонение недоступно');
                $call['state']='rejected';
            } else { $call['state']='ended'; }
            $call['updated']=time();$call['revision']++;$call['seen'][(string)$userId]=time();
            return $this->view($call,$userId);
        });
    }

    private function authorize(int $userId,string $dialogUid,?array $call=null):array
    {
        $members=($this->members)($userId,$dialogUid);
        if(count($members)!==2 || !in_array($userId,$members,true)) throw new DomainException('Нет доступа к звонку');
        if($call && (array_diff([$call['caller'],$call['callee']],$members) || !in_array($userId,[$call['caller'],$call['callee']],true))) throw new DomainException('Состав диалога изменился');
        return $members;
    }

    private function view(array $call,int $userId):array
    {
        return ['id'=>$call['id'],'dialog_uid'=>$call['dialog'],'mode'=>$call['mode'],'state'=>$call['state'],'role'=>$userId===$call['caller']?'caller':'callee','revision'=>$call['revision'],'description'=>$userId===$call['caller']?$call['answer']:$call['offer']];
    }

    private function locked(\Closure $operation):mixed
    {
        if(!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new RuntimeException('Хранилище сигнализации недоступно');
        $path=$this->directory.'/state.json';
        if(is_link($this->directory)||is_link($path)) throw new RuntimeException('Некорректное хранилище сигнализации');
        $lock=$this->directory.'/state.lock';
        if(is_link($lock)) throw new RuntimeException('Некорректное хранилище сигнализации');
        $file=fopen($lock,'c+');
        if(!$file) throw new RuntimeException('Хранилище сигнализации недоступно');
        try {
            if(!flock($file,LOCK_EX)) throw new RuntimeException('Сигнализация занята');
            $raw=is_file($path)?file_get_contents($path,false,null,0,20*1024*1024+1):'';
            if(!is_string($raw))throw new RuntimeException('Не удалось прочитать журнал звонков');
            if(strlen($raw)>20*1024*1024) throw new RuntimeException('Превышен размер журнала звонков');
            $calls=$raw!==''?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];
            if(!is_array($calls)) throw new RuntimeException('Повреждён журнал звонков');
            foreach($calls as $id=>&$call) {
                $now=time();
                if(in_array($call['state'],['ringing','active'],true)
                   && (($call['state']==='ringing' && $now-$call['created']>45) || $now-min($call['seen'])>90 || $now-$call['created']>7200)) {
                    $call['state']='expired';$call['updated']=$now;$call['revision']++;
                }
                if(!in_array($call['state'],['ringing','active'],true) && $now-$call['updated']>120) unset($calls[$id]);
            }
            unset($call);
            $result=$operation($calls);
            $json=json_encode($calls,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $temporary=$this->directory.'/state-'.bin2hex(random_bytes(8)).'.tmp';
            try {
                if(file_put_contents($temporary,$json)!==strlen($json)) throw new RuntimeException('Не удалось сохранить сигнализацию');
                @chmod($temporary,0600);
                if(!rename($temporary,$path)) throw new RuntimeException('Не удалось заменить журнал звонков');
            } finally {if(is_file($temporary))unlink($temporary);}
            @chmod($path,0600);
            return $result;
        } finally {flock($file,LOCK_UN);fclose($file);}
    }
}
