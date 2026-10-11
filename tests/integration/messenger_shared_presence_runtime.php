<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/core/DatabaseManager.php';
require_once dirname(__DIR__,2).'/modules/messenger/services/MessengerActivityService.php';
final class PresenceDatabase extends \Core\DatabaseManager {
    public PDO $pdo;
    public function __construct() {
        $this->pdo=new PDO('sqlite::memory:');
        $this->pdo->sqliteCreateFunction('UNIX_TIMESTAMP',static fn($v)=>strtotime((string)$v.' UTC'));
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE users(id INTEGER,uid TEXT,is_active INTEGER);
        CREATE TABLE user_to_dialogs(dialog_id INTEGER,user_id INTEGER,is_deleted INTEGER);
        CREATE TABLE messenger_activity(dialog_id INTEGER,user_id INTEGER,activity TEXT,expires_at TEXT,updated_at TEXT,PRIMARY KEY(dialog_id,user_id,activity));
        INSERT INTO users VALUES(1,'alice',1),(2,'bob',1),(3,'outsider',1);
        INSERT INTO user_to_dialogs VALUES(10,1,0),(10,2,0),(20,3,0);");
    }
    private function translated(string $sql): string {
        $sql=str_replace('DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 75 SECOND)',"datetime('now','+75 seconds')",$sql);
        $sql=str_replace('CURRENT_TIMESTAMP(3)','CURRENT_TIMESTAMP',$sql);
        return str_replace('ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)',
            'ON CONFLICT(dialog_id,user_id,activity) DO UPDATE SET expires_at=excluded.expires_at,updated_at=excluded.updated_at',$sql);
    }
    public function execute(string $query,array $params=[]): PDOStatement|int {
        $s=$this->pdo->prepare($this->translated($query));$s->execute($params);return $s;
    }
    public function fetchAll(string $query,array $params=[]): array {
        return $this->execute($query,$params)->fetchAll(PDO::FETCH_ASSOC);
    }
}
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=new PresenceDatabase();
$a=new \App\Services\MessengerActivityService($db);
$b=new \App\Services\MessengerActivityService($db);
$a->touchPresence('alice');$b->touchPresence('bob');$b->touchPresence('outsider');
check($a->onlineUids('alice')===['alice','bob'],'Independent workers must see their shared peers only');
$b->touchPresence('bob');
check(count($a->onlineUids('alice'))===2,'Heartbeat must upsert, not duplicate');
$lastSeen=$a->lastSeenByUid('alice');
check(isset($lastSeen['bob'])&&!isset($lastSeen['outsider']),'Last seen must stay within shared dialogs');
$db->pdo->exec("UPDATE messenger_activity SET expires_at='2000-01-01' WHERE user_id=2");
$cleanup=new ReflectionMethod($a,'cleanupExpired');$cleanup->setAccessible(true);$cleanup->invoke($a);
check($a->lastSeenByUid('alice')['bob']===$lastSeen['bob'],'Expiry cleanup must retain last activity');
check($a->onlineUids('alice')===['alice'],'Expired peers must become offline');
$b->touchPresence('bob');$db->pdo->exec('UPDATE users SET is_active=0 WHERE id=2');
check($a->onlineUids('alice')===['alice'],'Inactive peers must be offline');
$db->pdo->exec('UPDATE users SET is_active=1 WHERE id=2; UPDATE user_to_dialogs SET is_deleted=1 WHERE user_id=2');
check($a->onlineUids('alice')===['alice'],'Removed members must not leak presence');
echo "[OK] shared presence, expiry, isolation and deactivated/removed users\n";
