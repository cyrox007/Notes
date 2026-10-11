<?php
declare(strict_types=1);
if (!getenv('TEST_DB_HOST')) {echo "[SKIP] set TEST_DB_HOST/PORT/NAME/USER/PASS for native MySQL search test\n";exit;}
try {$pdo=new PDO('mysql:host='.getenv('TEST_DB_HOST').';port='.(getenv('TEST_DB_PORT')?:3306).';dbname='.getenv('TEST_DB_NAME').';charset=utf8mb4',getenv('TEST_DB_USER'),getenv('TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
catch(Throwable $e){throw new RuntimeException('MySQL test connection failed (details suppressed)');}
$source=file_get_contents(dirname(__DIR__,2).'/modules/tasks/controllers/TaskController.php');
$start=strpos($source,"        if (\$query['q'] !== '') {");$end=strpos($source,'        switch ($filter)', $start);
if($start===false||$end===false)throw new RuntimeException('Search query block missing');
$tasks="(SELECT 1 id,1 user_id,0 is_deleted,'test title' title,'body' description UNION ALL SELECT 2,1,0,'other','test body' UNION ALL SELECT 3,2,0,'test private','test body' UNION ALL SELECT 4,1,1,'test deleted','body') t";
foreach(['test'=>2,'title'=>1,'body'=>2,'missing'=>0] as $term=>$expected){
 $where=['t.user_id=:user_id','t.is_deleted=0'];$params=[':user_id'=>1];$query=['q'=>$term];eval(substr($source,$start,$end-$start));
 $stmt=$pdo->prepare('SELECT COUNT(*) FROM '.$tasks.' WHERE '.implode(' AND ',$where));$stmt->execute($params);
 if((int)$stmt->fetchColumn()!==$expected)throw new RuntimeException('Search mismatch');
 $stmt=$pdo->prepare('SELECT t.id FROM '.$tasks.' WHERE '.implode(' AND ',$where).' ORDER BY t.id');$stmt->execute($params);
 if(count($stmt->fetchAll())!==$expected)throw new RuntimeException('List search mismatch');
}
echo "[OK] task count/list search with native MySQL prepares, title/description matches and private/deleted isolation\n";
