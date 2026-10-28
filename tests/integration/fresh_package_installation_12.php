<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/core/ModuleManifest.php';
require_once $root.'/core/DatabaseOwnership.php';
require_once $root.'/core/InstallerDatabaseService.php';
require_once $root.'/core/InstallerEnvironmentService.php';
require_once $root.'/core/SchemaReadiness.php';
$name=(string)getenv('DBNAME');
if(!str_contains(strtolower($name),'test'))throw new RuntimeException('Only an isolated test database is permitted');
$host=(string)getenv('DBHOST');$port=(int)(getenv('DBPORT')?:3306);
$user=(string)getenv('DBUSER');$pass=(string)getenv('DBPASS');
$installer=new Core\InstallerDatabaseService();
$pdo=$installer->connect($host,$port,$name,$user,$pass);
if($installer->existingTables($pdo)!==[])throw new RuntimeException('Fresh installation requires an empty namespace');
$ownership=Core\DatabaseOwnership::fromPackageRoot($root);
foreach(['defects','work-permits'] as $id){
 if(!in_array($id,$ownership->moduleIds(),true)||!is_file($root.'/modules/'.$id.'/runtime.php'))throw new RuntimeException('Missing bundled module '.$id);
}
$installer->importSchemas($host,$port,$name,$user,$pass,array_map(fn($file)=>$root.'/'.$file,$ownership->schemaFiles()));
$missing=array_diff($ownership->tables(),$installer->existingTables($pdo));
if($missing!==[])throw new RuntimeException('Missing owned tables: '.implode(',',$missing));
$state=Core\SchemaReadiness::inspect($root);
if(!$state['ready'])throw new RuntimeException('Incomplete fresh schema: '.json_encode($state));
$temporary=tempnam(sys_get_temp_dir(),'wo-prefix-env-');
if(!is_string($temporary))throw new RuntimeException('Cannot prepare isolated environment check');
unlink($temporary);$temporary.='.env';
try{
 (new Core\InstallerEnvironmentService())->writeEnvironmentFile($temporary,[
  'db_host'=>$host,'db_port'=>$port,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,
  'db_prefix'=>getenv('DBPREFIX')?:'','private_storage'=>sys_get_temp_dir().'/wo-prefix-private',
  'unique_key'=>str_repeat('a',64),'message_key'=>str_repeat('b',64),'ws_ticket_secret'=>str_repeat('c',64),
  'site_url'=>'http://127.0.0.1','base_path'=>'/','ws_public_url'=>'','install_date'=>'2026-10-28',
 ]);
 if(!str_contains((string)file_get_contents($temporary),'DBPREFIX="'.(getenv('DBPREFIX')?:'').'"'))throw new RuntimeException('Installer lost database prefix');
}finally{if(is_file($temporary))unlink($temporary);}
echo json_encode(['fresh_ready'=>true,'modules'=>$ownership->moduleIds(),'prefix'=>getenv('DBPREFIX')?:'','owned_tables'=>count($ownership->tables())],JSON_UNESCAPED_UNICODE),PHP_EOL;
