<?php
declare(strict_types=1);
if(!getenv('TEST_DB_HOST')){echo "[SKIP] MySQL connection not configured\n";exit;}
$pdo=new PDO('mysql:host='.getenv('TEST_DB_HOST').';port='.(getenv('TEST_DB_PORT')?:3306).';dbname='.getenv('TEST_DB_NAME').';charset=utf8mb4',getenv('TEST_DB_USER'),getenv('TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix='workflow_test_'.bin2hex(random_bytes(5)).'_';
$tables=[$prefix.'tasks',$prefix.'task_board_items'];
try {
    foreach($tables as $table){
        $pdo->exec("CREATE TABLE `$table`(id INT PRIMARY KEY,status ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',updated_at DATETIME,completed_at DATETIME,title TEXT)");
        $pdo->exec("INSERT INTO `$table` VALUES(1,'cancelled','2026-10-10 12:00:00',NULL,'Keep cancelled data'),(2,'completed','2026-10-10 12:00:00','2026-10-10 12:00:00','Keep completed data'),(3,'in_progress','2026-10-10 12:00:00',NULL,'Keep progress data')");
    }
    $sql=file_get_contents(dirname(__DIR__,2).'/database/migrations/20261011_tasks_workflow_archive.sql');
    $sql=str_replace(['`task_board_items`',"'task_board_items'",'`tasks`',"'tasks'"],['`'.$tables[1].'`',"'".$tables[1]."'",'`'.$tables[0].'`',"'".$tables[0]."'"],$sql);
    for($i=0;$i<2;$i++)foreach(explode(';',$sql) as $statement)if(trim($statement)!==''){$s=$pdo->query($statement);$s->closeCursor();}
    foreach($tables as $table){
        $rows=$pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==3||$rows[0]['status']!=='pending'||$rows[0]['archived_at']!=='2026-10-10 12:00:00'||$rows[1]['status']!=='completed'||$rows[1]['archived_at']!==null||$rows[1]['completed_at']!=='2026-10-10 12:00:00'||$rows[2]['status']!=='in_progress'||$rows[2]['archived_at']!==null)throw new RuntimeException('Migration changed task data');
        foreach(['studying','review'] as $status)$pdo->exec("UPDATE `$table` SET status='$status' WHERE id=3");
    }
    echo "[OK] native MySQL migration twice, cancelled archived, existing statuses/completion retained, new ENUM values accepted\n";
} finally {foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");}
