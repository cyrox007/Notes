<?php
declare(strict_types=1);
namespace Core {
    class Controller {}
    class Request {public function session(string $key,mixed $default=null): mixed {return $GLOBALS['scenario']==='anonymous'?0:1;}}
}
namespace App\Models {
    class Query {
        private array $filters=[];
        public function __construct(private array $rows) {}
        public function where(string $name,string $op,mixed $value): self {$this->filters[$name]=$value;return $this;}
        public function first(): ?object {
            foreach($this->rows as $row){foreach($this->filters as $key=>$value)if(($row[$key]??null)!=$value)continue 2;return (object)$row;}return null;
        }
    }
    class UserModel {public static function select(): Query {return new Query([
        ['id'=>1,'is_active'=>1],['id'=>2,'is_active'=>$GLOBALS['scenario']==='inactive'?0:1]
    ]);}}
    class FileModel {public static function select(): Query {$s=$GLOBALS['scenario'];return new Query([
        ['uid'=>'published','user_id'=>2,'is_profile_public'=>in_array($s,['private','revoked'])?0:1,'is_deleted'=>$s==='deleted'?1:0,
        'type'=>$s==='folder'?'folder':'document','path'=>$GLOBALS['storage'].'/file.txt','name'=>'report','extension'=>'txt','mime_type'=>'text/plain']
    ]);}}
}
namespace {
    if(isset($argv[1])) {
        $GLOBALS['scenario']=$argv[1];$GLOBALS['storage']=$argv[2];
        define('SITEPATH',$argv[2].'/site');putenv('PRIVATE_STORAGE_PATH='.$argv[2]);
        require dirname(__DIR__,2).'/modules/files/controllers/FileController.php';
        (new \App\Controllers\FileController())->publishedFile(new \Core\Request(),'published');
        echo 'STATUS:'.http_response_code();exit;
    }
    $dir=sys_get_temp_dir().'/notes-public-file-test-'.bin2hex(random_bytes(6));mkdir($dir);file_put_contents($dir.'/file.txt','PUBLIC CONTENT');
    try {
        foreach(['published'=> 'PUBLIC CONTENT','private'=>'STATUS:404','revoked'=>'STATUS:404','deleted'=>'STATUS:404','folder'=>'STATUS:404','inactive'=>'STATUS:404','anonymous'=>'STATUS:403'] as $case=>$expected) {
            $p=proc_open([PHP_BINARY,__FILE__,$case,$dir],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
            if($code!==0||!str_contains($output,$expected))throw new \RuntimeException($case.': '.$output.$error);
            if($case!=='published'&&str_contains($output,'PUBLIC CONTENT'))throw new \RuntimeException('Private bytes leaked');
        }
        echo "[OK] published file access and private/revoked/deleted/folder/inactive/anonymous denial\n";
    } finally {unlink($dir.'/file.txt');rmdir($dir);}
}
