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
 if(!preg_match('~^(modules/messenger/(views|services|handlers)/[A-Za-z0-9.-]+|assets/css/(local-transcription|messenger-connection-ux)\.css|assets/js/(local-transcription|messenger-connection-ux)\.js)$~D',$name))throw new RuntimeException('Unsafe payload entry');
 $bytes=file_get_contents($source.'/'.$name);
 if(!hash_equals($hash,hash('sha256',$bytes)))throw new RuntimeException('Payload checksum mismatch: '.$name);
 if(!is_file($root.'/'.$name)||is_link($root.'/'.$name))throw new RuntimeException('Installed file missing or unsafe: '.$name);
 $payload[$name]=$bytes;
}
$htaccess=$root.'/.htaccess';
if(is_file($htaccess)&&!is_link($htaccess)){
 $bytes=file_get_contents($htaccess);$changed=str_replace('camera=()','camera=(self)',$bytes);
 if($changed!==$bytes)$payload['.htaccess']=$changed;
}
$backup=dirname($root).'/.'.basename($root).'-media-repair-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
if(!mkdir($backup,0700))throw new RuntimeException('Cannot create backup');
foreach($payload as $name=>$bytes){$path=$backup.'/'.$name;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);if(!copy($root.'/'.$name,$path))throw new RuntimeException('Cannot back up '.$name);}
$written=[];
try{
 foreach($payload as $name=>$bytes){$target=$root.'/'.$name;$temp=$target.'.media-repair-'.bin2hex(random_bytes(4)).'.tmp';if(file_put_contents($temp,$bytes)!==strlen($bytes))throw new RuntimeException('Cannot write '.$name);if(!rename($temp,$target)){@unlink($temp);throw new RuntimeException('Cannot replace '.$name);}$written[]=$name;if(!hash_equals(hash('sha256',$bytes),hash_file('sha256',$target)))throw new RuntimeException('Installed checksum mismatch');}
}catch(Throwable $error){foreach(array_reverse($written) as $name){if(!copy($backup.'/'.$name,$root.'/'.$name))fwrite(STDERR,'Restore manually: '.$name.PHP_EOL);}throw $error;}
echo "Media repair installed. Backup: {$backup}\nApplication version, .env, keys and messages unchanged. Reload both browsers with Ctrl+F5.\n";
