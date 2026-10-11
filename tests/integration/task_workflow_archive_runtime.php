<?php
declare(strict_types=1);
namespace Core {
    class Controller {}
    class Request {
        public function __construct(public int $user, public array $data) {}
        public function session($key,$default=null) {return $this->user;}
        public function post($key=null,$default=null) {return $key===null?$this->data:($this->data[$key]??$default);}
        public function server($key,$default=null) {return $key==='HTTP_ACCEPT'?'application/json':$default;}
    }
    class DatabaseManager {
        public static self $instance;
        public \PDO $pdo;
        public static function getInstance(): self {return self::$instance;}
        public function fetchAll(string $q,array $p=[]): array {$s=$this->pdo->prepare($q);$s->execute($p);return $s->fetchAll(\PDO::FETCH_ASSOC);}
        public function fetchOne(string $q,array $p=[]): ?array {return $this->fetchAll($q,$p)[0]??null;}
        public function fetchValue(string $q,array $p=[]) { $row=$this->fetchOne($q,$p);return $row?reset($row):null; }
        public function execute(string $q,array $p=[]): void {$s=$this->pdo->prepare($q);$s->execute($p);}
        public function beginTransaction(): void {$this->pdo->beginTransaction();}
        public function endTransaction(bool $ok): void {$ok?$this->pdo->commit():$this->pdo->rollBack();}
    }
}
namespace App\Services {class RolePolicyService {public function __construct($db){}}}
namespace {
    $root=dirname(__DIR__,2);
    require $root.'/modules/tasks/controllers/TaskController.php';
    require $root.'/modules/tasks/services/TaskBoardService.php';
    $db=new \Core\DatabaseManager();\Core\DatabaseManager::$instance=$db;
    $db->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->pdo->exec("CREATE TABLE users(id INTEGER,uid TEXT,username TEXT,firstname TEXT,lastname TEXT,avatar TEXT,role INTEGER,is_active INTEGER,account_status TEXT);
      INSERT INTO users VALUES(1,'owner','owner','','',NULL,888,1,'active'),(2,'outsider','outsider','','',NULL,888,1,'active'),(3,'reader','reader','','',NULL,888,1,'active');
      CREATE TABLE tasks(id INTEGER,uid TEXT,user_id INTEGER,title TEXT,description TEXT,status TEXT,priority TEXT,due_date TEXT,completed_at TEXT,archived_at TEXT,is_deleted INTEGER,updated_at TEXT);
      INSERT INTO tasks VALUES(1,'personal',1,'Title','Text','pending','medium',NULL,NULL,NULL,0,'2026-10-10');
      CREATE TABLE task_boards(id INTEGER,uid TEXT,owner_user_id INTEGER,name TEXT,audience TEXT,is_archived INTEGER,created_at TEXT,updated_at TEXT);
      INSERT INTO task_boards VALUES(1,'board',1,'Board','members',0,'2026-10-10','2026-10-10');
      CREATE TABLE task_board_members(board_id INTEGER,user_id INTEGER,role TEXT);
      INSERT INTO task_board_members VALUES(1,3,'viewer');
      CREATE TABLE task_board_items(id INTEGER,uid TEXT,board_id INTEGER,title TEXT,description TEXT,status TEXT,priority TEXT,due_date TEXT,completed_at TEXT,archived_at TEXT,is_deleted INTEGER,updated_at TEXT);
      INSERT INTO task_board_items SELECT id,'shared',1,title,description,status,priority,due_date,completed_at,archived_at,is_deleted,updated_at FROM tasks;");
    (new ReflectionProperty(\App\Services\TaskBoardService::class,'schemaReady'))->setValue(null,true);
    $controller=new \App\Controllers\TaskController();$service=new \App\Services\TaskBoardService($db);
    $personal=static function(int $user,array $changes)use($controller):int {
        http_response_code(200);ob_start();$controller->update(new \Core\Request($user,$changes),'personal');ob_end_clean();return http_response_code();
    };
    foreach(['tasks','task_board_items'] as $table) {
        $change=static function(int $user,array $data)use($table,$personal,$service):int {
            if($table==='tasks')return $personal($user,$data);
            try{$service->updateTask($user,'shared',$data);return 200;}catch(DomainException $e){return (int)$e->getCode();}catch(InvalidArgumentException $e){return 422;}
        };
        foreach(['pending','studying','in_progress','review','completed'] as $status) {
            if($change(1,['status'=>$status])!==200)throw new RuntimeException('Status rejected: '.$status);
            $before=$db->fetchOne("SELECT * FROM $table WHERE id=1");
            if($before['status']!==$status)throw new RuntimeException('Status not saved');
            if($change(2,['archived'=>'1'])!==404)throw new RuntimeException('Outsider archived task');
            if($table==='task_board_items'&&$change(3,['archived'=>'1'])!==403)throw new RuntimeException('Reader archived task');
            if($change(1,['archived'=>'1'])!==200)throw new RuntimeException('Archive failed');
            $archived=$db->fetchOne("SELECT * FROM $table WHERE id=1");
            if(!$archived['archived_at']||$archived['status']!==$status||$archived['completed_at']!==$before['completed_at'])throw new RuntimeException('Archive lost state');
            if($change(1,['status'=>'pending'])!==422)throw new RuntimeException('Archived status mutated');
            if($change(2,['archived'=>'0'])!==404)throw new RuntimeException('Outsider restored task');
            if($change(1,['archived'=>'0'])!==200)throw new RuntimeException('Restore failed');
            $restored=$db->fetchOne("SELECT * FROM $table WHERE id=1");
            if($restored['archived_at']!==null||$restored['status']!==$status||$restored['completed_at']!==$before['completed_at'])throw new RuntimeException('Restore lost state');
        }
        foreach([['status'=>'cancelled'],['archived'=>'invalid']] as $invalid)if($change(1,$invalid)!==422)throw new RuntimeException('Invalid action accepted');
        $db->pdo->exec("UPDATE $table SET is_deleted=1 WHERE id=1");
        if($change(1,['archived'=>'0'])!==404)throw new RuntimeException('Deleted task restored');
    }
    echo "[OK] five statuses; archive/restore preserves status and completion; outsider, read-only, deleted and invalid actions denied\n";
}
