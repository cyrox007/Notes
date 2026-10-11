<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
$options=getopt('', ['root:','yes']);
if(!isset($options['yes'],$options['root'])){fwrite(STDERR,"Usage: php repair-messenger.php --yes --root=/absolute/path/to/installed-1.0.16\nExtract the patch outside the site. Finish active updates first.\n");exit(2);}
$root=realpath((string)$options['root']);$source=__DIR__;
if(!$root||!is_dir($root)||is_link((string)$options['root']))throw new RuntimeException('Unsafe application root');
$normalize=static fn(string $p):string=>strtolower(str_replace('\\','/',$p));
if(str_starts_with($normalize($source).'/',rtrim($normalize($root),'/').'/'))throw new RuntimeException('Extract patch outside application');
$version=file_get_contents($root.'/core/Version.php');
if(!str_contains($version,"VERSION = '1.0.16'")||!str_contains($version,'VERSION_CODE = 10016;'))throw new RuntimeException('This patch requires installed 1.0.16 (10016)');
$manifest=json_decode(file_get_contents($source.'/payload.json'),true,16,JSON_THROW_ON_ERROR);
$payload=[];
foreach($manifest as $name=>$hash){
 if(!preg_match('~^(database/(migrations/(manifest\.json|20261011_tasks_workflow_archive\.sql)|tasks_schema\.sql|task_boards_schema\.sql)|modules/tasks/(TasksCapability\.php|module\.json|controllers/TaskBoardController\.php|services/TaskBoardService\.php|models/TaskModel\.php|assets/(kanban|boards)\.css|views/boards\.php)|modules/tasks/views/(index\.php|elements/task_item/index\.php)|assets/js/common\.js|modules/tasks/assets/(style|hardening)\.css|modules/notes/NotesRuntimeProvider\.php|modules/notes/controllers/(NoteShareController|NoteAttachmentController)\.php|modules/tasks/TasksRuntimeProvider\.php|modules/tasks/controllers/TaskController\.php|modules/profile/assets/profile\.js|modules/profile/assets/hub\.css|modules/files/FilesRuntimeProvider\.php|modules/files/controllers/FileController\.php|modules/profile/views/public\.php|modules/messenger/controllers/MessengerRealtimeController\.php|modules/messenger/socket/MessangerSocket\.php|modules/messenger/services/MessengerActivityService\.php|modules/messenger/services/MessengerLongPollService\.php|modules/messenger/(views|services|handlers)/[A-Za-z0-9.-]+|assets/css/(local-transcription|messenger-connection-ux)\.css|assets/js/(local-transcription|messenger-connection-ux|usability-actions)\.js|modules/tasks/assets/(tasks-page|tasks-kanban)\.js|modules/notes/assets/(notes-editor-013\.js|editor-013\.css))$~D',$name))throw new RuntimeException('Unsafe payload entry');
 $bytes=file_get_contents($source.'/'.$name);
 if(!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Payload checksum mismatch: '.$name);
 if(($name !== 'database/migrations/20261011_tasks_workflow_archive.sql' && !is_file($root.'/'.$name))||is_link($root.'/'.$name))throw new RuntimeException('Installed file missing or unsafe: '.$name);
 $payload[$name]=$bytes;
}
// Preserve migrations declared by the installed package, including later modules.
$migrationName='20261011_tasks_workflow_archive.sql';
$installedManifest=json_decode(file_get_contents($root.'/database/migrations/manifest.json'),true,32,JSON_THROW_ON_ERROR);
if(!is_array($installedManifest['migrations']??null))throw new RuntimeException('Invalid installed migration manifest');
if(!in_array($migrationName,$installedManifest['migrations'],true))$installedManifest['migrations'][]=$migrationName;
$payload['database/migrations/manifest.json']=json_encode($installedManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
$installedModule=json_decode(file_get_contents($root.'/modules/tasks/module.json'),true,32,JSON_THROW_ON_ERROR);
if(!is_array($installedModule['database']['migrations']??null))throw new RuntimeException('Invalid installed Tasks manifest');
if(!in_array('database/migrations/'.$migrationName,$installedModule['database']['migrations'],true))$installedModule['database']['migrations'][]='database/migrations/'.$migrationName;
$payload['modules/tasks/module.json']=json_encode($installedModule,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
if(is_file($root.'/database/migrations/'.$migrationName)&&!hash_equals(hash('sha256',$payload['database/migrations/'.$migrationName]),hash_file('sha256',$root.'/database/migrations/'.$migrationName)))throw new RuntimeException('Installed workflow migration has different bytes');
$htaccess=$root.'/.htaccess';
if(is_file($htaccess)&&!is_link($htaccess)){
 $bytes=file_get_contents($htaccess);$changed=str_replace('camera=()','camera=(self)',$bytes);
 if($changed!==$bytes)$payload['.htaccess']=$changed;
}
$backup=dirname($root).'/.'.basename($root).'-media-repair-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
if(!mkdir($backup,0700))throw new RuntimeException('Cannot create backup');
foreach($payload as $name=>$bytes){$path=$backup.'/'.$name;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);if(is_file($root.'/'.$name)&&!copy($root.'/'.$name,$path))throw new RuntimeException('Cannot back up '.$name);}
// All source/target validation and file backups have completed before DB mutation.
require_once $root.'/core/Environment.php';
\Core\Environment::load($root.'/.env');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('DBHOST')?:'localhost',getenv('DBUSER'),getenv('DBPASS')?:'',getenv('DBNAME'),(int)(getenv('DBPORT')?:3306));
$db->set_charset('utf8mb4');
try {
 // Save both affected tables before the first nontransactional ALTER TABLE.
 $snapshot=[];
 foreach(['tasks','task_board_items'] as $table){
  $result=$db->query('SELECT * FROM `'.$table.'`');
  $snapshot[$table]=$result->fetch_all(MYSQLI_ASSOC);$result->free();
 }
 $snapshotBytes=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
 if(file_put_contents($backup.'/task-data-before-migration.json',$snapshotBytes)!==strlen($snapshotBytes))throw new RuntimeException('Cannot back up task data');
 chmod($backup.'/task-data-before-migration.json',0600);
 $db->multi_query($payload['database/migrations/'.$migrationName]);
 do {if($result=$db->store_result())$result->free();} while($db->more_results()&&$db->next_result());
} catch(Throwable $error) {throw new RuntimeException('Task schema migration failed; runtime files unchanged. Back up/check the database and retry.',0,$error);}
$db->close();
$written=[];
try{
 foreach($payload as $name=>$bytes){$target=$root.'/'.$name;$temp=$target.'.media-repair-'.bin2hex(random_bytes(4)).'.tmp';if(file_put_contents($temp,$bytes)!==strlen($bytes))throw new RuntimeException('Cannot write '.$name);if(!rename($temp,$target)){@unlink($temp);throw new RuntimeException('Cannot replace '.$name);}$written[]=$name;if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$target)))throw new RuntimeException('Installed checksum mismatch');}
}catch(Throwable $error){foreach(array_reverse($written) as $name){if(!is_file($backup.'/'.$name)){@unlink($root.'/'.$name);continue;}if(!copy($backup.'/'.$name,$root.'/'.$name))fwrite(STDERR,'Restore manually: '.$name.PHP_EOL);}throw $error;}
echo "Media repair installed. Backup: {$backup}\nApplication version, .env, keys and messages unchanged. Reload both browsers with Ctrl+F5.\n";
