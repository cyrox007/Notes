<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/core/DatabaseManager.php';
require_once $root.'/core/Uuid.php';
require_once $root.'/modules/messenger/handlers/MessengerCrypto.php';
require_once $root.'/modules/messenger/services/MessengerMediaService.php';
final class RecordedFixturePdo extends PDO {
 public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace('FOR UPDATE','',$query),$options);}
}
$pdo=new RecordedFixturePdo('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,uid TEXT,username TEXT,firstname TEXT,lastname TEXT,avatar TEXT,is_active INTEGER)',
 'CREATE TABLE dialogs(id INTEGER PRIMARY KEY,uid TEXT,updated_at TEXT)',
 'CREATE TABLE user_to_dialogs(dialog_id INTEGER,user_id INTEGER,is_deleted INTEGER)',
 'CREATE TABLE messenger_attachments(id INTEGER PRIMARY KEY,uid TEXT,dialog_id INTEGER,uploader_user_id INTEGER,message_id INTEGER,original_name TEXT,stored_path TEXT,mime_type TEXT,extension TEXT,media_kind TEXT,size INTEGER,is_deleted INTEGER)',
 'CREATE TABLE messages(id INTEGER PRIMARY KEY AUTOINCREMENT,uid TEXT,dialog_id INTEGER,from_user_id INTEGER,reply_to_message_id INTEGER,message TEXT,message_type TEXT,media_url TEXT,meta_data TEXT,message_status TEXT,is_deleted INTEGER,created_at TEXT,updated_at TEXT)',
 'CREATE TABLE message_user_deletions(message_id INTEGER,user_id INTEGER)'
] as $sql)$pdo->exec($sql);
$reflection=new ReflectionClass(Core\DatabaseManager::class);$db=$reflection->newInstanceWithoutConstructor();
foreach(['pdo'=>$pdo,'enableLogging'=>false,'maxLogLength'=>100]as$name=>$value)$reflection->getProperty($name)->setValue($db,$value);
$directory=sys_get_temp_dir().'/recorded-retry-'.bin2hex(random_bytes(6));mkdir($directory.'/messenger',0700,true);
$oldStorage=getenv('PRIVATE_STORAGE_PATH');$oldSecret=getenv('MSG_SECRET_KEY');putenv('PRIVATE_STORAGE_PATH='.$directory);putenv('MSG_SECRET_KEY='.str_repeat('test-key-',8));
$pdo->exec("INSERT INTO users VALUES (1,'user-one','one','','','',1),(2,'user-two','two','','','',1),(3,'user-three','three','','','',1)");
$pdo->exec("INSERT INTO dialogs VALUES (1,'dialog-a','now'); INSERT INTO user_to_dialogs VALUES (1,1,0),(1,2,0)");
function recordedAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
try{
 foreach(['voice','video']as$index=>$kind){
  $id=$index+1;$path=$directory.'/messenger/'.$kind.'.webm';file_put_contents($path,'fixture');
  $statement=$pdo->prepare('INSERT INTO messenger_attachments VALUES (:id,:uid,1,1,NULL,:name,:path,:mime,"webm",:kind,7,0)');$statement->execute([':id'=>$id,':uid'=>$kind,':name'=>$kind.'.webm',':path'=>$path,':mime'=>$kind==='voice'?'audio/webm':'video/webm',':kind'=>$kind]);
  $service=new App\Services\MessengerMediaService($db);
  $first=$service->sendRecorded(1,$kind);$retry=$service->sendRecorded(1,$kind);
  recordedAssert(!$first['already_sent']&&$retry['already_sent'],'Retry must acknowledge the existing message');
  recordedAssert((int)$pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()===$id,'Retry must not duplicate messages');
  try{$service->sendRecorded(2,$kind);throw new RuntimeException('Peer resent another user recording');}catch(DomainException){}
  try{$service->sendRecorded(3,$kind);throw new RuntimeException('Non-member accessed recording');}catch(DomainException){}
 }
 echo "[OK] recorded voice/video: confirmed send, same-attachment retry without duplicate, uploader and membership ACL\n";
}finally{putenv($oldStorage===false?'PRIVATE_STORAGE_PATH':'PRIVATE_STORAGE_PATH='.$oldStorage);putenv($oldSecret===false?'MSG_SECRET_KEY':'MSG_SECRET_KEY='.$oldSecret);foreach(glob($directory.'/messenger/*')as$file)unlink($file);rmdir($directory.'/messenger');rmdir($directory);}
