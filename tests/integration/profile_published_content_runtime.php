<?php
declare(strict_types=1);
namespace Core {
    class Controller {}
    class Request {public function session(string $key,mixed $default=null): mixed {return $GLOBALS['viewer'];}}
    class DatabaseManager {
        public \PDO $pdo;
        public static self $instance;
        public static function getInstance(): self {return self::$instance;}
        public function fetchAll(string $q,array $p=[]): array {$s=$this->pdo->prepare($q);$s->execute($p);return $s->fetchAll(\PDO::FETCH_ASSOC);}
        public function fetchOne(string $q,array $p=[]): ?array {return $this->fetchAll($q,$p)[0]??null;}
    }
}
namespace App\Helpers {class CryptMethods {public static function decrypt(string $value,string $uid): string {if($uid!=='note')throw new \RuntimeException('Wrong key');return substr($value,7);}}}
namespace {
    $r=dirname(__DIR__,2);
    require $r.'/modules/notes/controllers/NoteShareController.php';
    require $r.'/modules/tasks/controllers/TaskController.php';
    $db=new \Core\DatabaseManager();\Core\DatabaseManager::$instance=$db;
    $db->pdo=new PDO('sqlite::memory:');$db->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->pdo->exec("CREATE TABLE users(id INTEGER,is_active INTEGER,username TEXT);
      INSERT INTO users VALUES(1,1,'viewer'),(2,1,'owner');
      CREATE TABLE notes(id INTEGER,uid TEXT,user_id INTEGER,notename TEXT,content TEXT,is_encrypted INTEGER,is_deleted INTEGER,is_profile_public INTEGER);
      INSERT INTO notes VALUES(1,'note',2,'Title','cipher:<script>unsafe</script>',1,0,1);
      CREATE TABLE tasks(id INTEGER,uid TEXT,user_id INTEGER,title TEXT,description TEXT,status TEXT,priority TEXT,due_date TEXT,is_deleted INTEGER,is_profile_public INTEGER);
      INSERT INTO tasks VALUES(1,'task',2,'Task','Description','in_progress','medium',NULL,0,1);
      CREATE TABLE subtasks(task_id INTEGER,title TEXT,is_completed INTEGER,sort_order INTEGER,id INTEGER);
      INSERT INTO subtasks VALUES(1,'Step',1,0,1);
      CREATE TABLE note_attachments(note_id INTEGER,file_uid TEXT,file_name TEXT,is_deleted INTEGER,id INTEGER);
      INSERT INTO note_attachments VALUES(1,'attachment','a.txt',0,1),(99,'private','private.txt',0,2);");
    $GLOBALS['viewer']=1;$request=new \Core\Request();
    foreach(['notes'=>new \App\Controllers\NoteShareController(),'tasks'=>new \App\Controllers\TaskController()] as $table=>$controller){
      $uid=$table==='notes'?'note':'task';
      $call=static function()use($controller,$request,$uid){http_response_code(200);ob_start();$controller->published($request,$uid);$json=ob_get_clean();return [http_response_code(),json_decode($json,true,512,JSON_THROW_ON_ERROR)];};
      [$status,$data]=$call();if($status!==200||!isset($data['content']))throw new \RuntimeException('Published content missing');
      if($table==='notes'&&($data['content']!=='<script>unsafe</script>'||count($data['attachments'])!==1))throw new \RuntimeException('Decryption or attachment isolation failed');
      if($table==='tasks'&&count($data['subtasks'])!==1)throw new \RuntimeException('Subtasks missing');
      foreach(['is_profile_public'=>0,'is_deleted'=>1] as $column=>$value){$db->pdo->exec("UPDATE $table SET $column=$value");[$status,$data]=$call();if($status!==404||isset($data['content']))throw new \RuntimeException('Hidden/deleted content exposed');$db->pdo->exec("UPDATE $table SET $column=".(1-$value));}
      foreach([0,999] as $viewer){$GLOBALS['viewer']=$viewer;[$status,$data]=$call();if($status!==404)throw new \RuntimeException('Unauthenticated viewer accepted');}$GLOBALS['viewer']=1;
      foreach([1,2] as $id){$db->pdo->exec("UPDATE users SET is_active=0 WHERE id=$id");[$status]=$call();if($status!==404)throw new \RuntimeException('Inactive user accepted');$db->pdo->exec("UPDATE users SET is_active=1 WHERE id=$id");}
    }
    echo "[OK] published notes/tasks, decryption, subtasks, isolated attachments, revocation, deletion and inactive/anonymous denial\n";
}
