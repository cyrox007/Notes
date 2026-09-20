<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}

require_once $root . '/core/Environment.php';
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/app/services/DataKeyRotationService.php';

use App\Helpers\CryptMethods;
use App\Helpers\MessengerCrypto;
use App\Services\DataKeyRotationService;
use App\Services\MaintenanceModeService;
use Core\DatabaseManager;

function keyRotationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] data-key rotation: {$message}\n");
        exit(1);
    }
}

function canDecryptNote(string $payload, string $uid, string $secret): bool
{
    try {
        CryptMethods::decryptWithSecret($payload, $uid, $secret);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function canDecryptMessage(string $payload, string $uid, string $secret): bool
{
    try {
        MessengerCrypto::decryptCurrentWithSecret($payload, $uid, $secret);
        return true;
    } catch (Throwable) {
        return false;
    }
}

$oldUnique = trim((string) (getenv('TEST_OLD_UNIQUE_KEY') ?: ''));
$newUnique = trim((string) (getenv('TEST_NEW_UNIQUE_KEY') ?: ''));
$oldMsg = trim((string) (getenv('TEST_OLD_MSG_KEY') ?: ''));
$newMsg = trim((string) (getenv('TEST_NEW_MSG_KEY') ?: ''));
$stateRoot = trim((string) (getenv('DATA_KEY_ROTATION_STATE_PATH') ?: ''));
keyRotationAssert(strlen($oldUnique) >= 32 && strlen($newUnique) >= 32, 'note test secrets missing');
keyRotationAssert(strlen($oldMsg) >= 32 && strlen($newMsg) >= 32, 'Messenger test secrets missing');
keyRotationAssert($stateRoot !== '', 'state root missing');
keyRotationAssert(trim((string) getenv('UNIQUE_KEY')) === $oldUnique, 'active UNIQUE_KEY must be the old key');
keyRotationAssert(trim((string) getenv('MSG_SECRET_KEY')) === $oldMsg, 'active MSG_SECRET_KEY must be the old key');

$db = DatabaseManager::getInstance();
$db->execute(
    "INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
     VALUES ('11111111-1111-4111-8111-111111111111','keyrot','keyrot@example.test','not-used','Key','Rotation',888,1)"
);
$userId = (int) $db->fetchValue("SELECT id FROM users WHERE username='keyrot'");
$db->execute(
    "INSERT INTO dialogs (uid,type,name,created_by)
     VALUES ('22222222-2222-4222-8222-222222222222','private','Key rotation',$userId)"
);
$dialogId = (int) $db->fetchValue("SELECT id FROM dialogs WHERE uid='22222222-2222-4222-8222-222222222222'");

$noteRows = [
    ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'first note'],
    ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'second note'],
];
foreach ($noteRows as [$uid, $plaintext]) {
    $ciphertext = CryptMethods::encrypt($plaintext, $uid);
    $db->execute(
        'INSERT INTO notes (uid,user_id,notename,content,content_type,is_encrypted) '
        . "VALUES (:uid,:user_id,:name,:content,'text',1)",
        [':uid' => $uid, ':user_id' => $userId, ':name' => $plaintext, ':content' => $ciphertext]
    );
}

$firstNoteId = (int) $db->fetchValue("SELECT id FROM notes WHERE uid='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'");
$db->execute(
    "INSERT INTO note_history (note_id,user_id,action,old_content,new_content)
     VALUES (:note_id,:user_id,'update','legacy plaintext snapshot',NULL)",
    [':note_id' => $firstNoteId, ':user_id' => $userId]
);

$messageRows = [
    ['33333333-3333-4333-8333-333333333333', 'first message'],
    ['44444444-4444-4444-8444-444444444444', 'second message'],
];
foreach ($messageRows as [$uid, $plaintext]) {
    $ciphertext = MessengerCrypto::encrypt($plaintext, $uid);
    $db->execute(
        'INSERT INTO messages (uid,dialog_id,from_user_id,message,message_type) '
        . "VALUES (:uid,:dialog_id,:user_id,:message,'text')",
        [':uid' => $uid, ':dialog_id' => $dialogId, ':user_id' => $userId, ':message' => $ciphertext]
    );
}

$maintenance = new MaintenanceModeService(null, $root);
$transaction = 'keyrotate-contract';
$maintenance->enter($transaction, 'Data-key rotation contract');
$service = new DataKeyRotationService($db, $maintenance, $stateRoot, $root);
$secrets = [
    'old_unique' => $oldUnique,
    'new_unique' => $newUnique,
    'old_msg' => $oldMsg,
    'new_msg' => $newMsg,
];

try {
    $partial = $service->run($transaction, 'all', $secrets, 1, 1, false);
    keyRotationAssert($partial['complete'] === false, 'one-batch run must be resumable/incomplete');
    keyRotationAssert($partial['verified'] === false, 'partial rotation must not report final verification');

    $statePath = (string) $partial['state_path'];
    $stateBytes = (string) file_get_contents($statePath);
    foreach ([$oldUnique, $newUnique, $oldMsg, $newMsg] as $secret) {
        keyRotationAssert(!str_contains($stateBytes, $secret), 'state file leaked a raw encryption secret');
    }

    // Simulate: DB commit succeeded, process died before checkpoint persisted.
    $state = json_decode($stateBytes, true, 32, JSON_THROW_ON_ERROR);
    $state['checkpoints']['notes'] = 0;
    $state['complete']['notes'] = false;
    file_put_contents(
        $statePath,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
        LOCK_EX
    );
    chmod($statePath, 0600);

    $forward = $service->run($transaction, 'all', $secrets, 1, 0, false);
    keyRotationAssert($forward['complete'] === true && $forward['verified'] === true, 'forward rotation did not complete');
    keyRotationAssert(
        (int) ($forward['counts']['notes_already_target'] ?? 0) >= 1,
        'resume did not detect already-target ciphertext after simulated checkpoint loss'
    );

    foreach ($db->fetchAll("SELECT uid,content FROM notes WHERE content IS NOT NULL AND content<>'' ORDER BY id") as $row) {
        keyRotationAssert(canDecryptNote((string) $row['content'], (string) $row['uid'], $newUnique), 'note is not readable with new key');
        keyRotationAssert(!canDecryptNote((string) $row['content'], (string) $row['uid'], $oldUnique), 'rotated note still authenticates with old key');
    }
    foreach ($db->fetchAll("SELECT uid,message FROM messages WHERE message<>'' ORDER BY id") as $row) {
        keyRotationAssert(canDecryptMessage((string) $row['message'], (string) $row['uid'], $newMsg), 'message is not readable with new key');
        keyRotationAssert(!canDecryptMessage((string) $row['message'], (string) $row['uid'], $oldMsg), 'rotated message still authenticates with old key');
    }

    $plaintextHistory = (string) $db->fetchValue(
        "SELECT old_content FROM note_history WHERE old_content='legacy plaintext snapshot' LIMIT 1"
    );
    keyRotationAssert($plaintextHistory === 'legacy plaintext snapshot', 'plaintext historical snapshot must remain byte-identical');

    $rollback = $service->run($transaction, 'all', $secrets, 1, 0, true);
    keyRotationAssert($rollback['complete'] === true && $rollback['verified'] === true, 'rollback did not complete');

    foreach ($db->fetchAll("SELECT uid,content FROM notes WHERE content IS NOT NULL AND content<>'' ORDER BY id") as $row) {
        keyRotationAssert(canDecryptNote((string) $row['content'], (string) $row['uid'], $oldUnique), 'rolled-back note is not readable with original key');
        keyRotationAssert(!canDecryptNote((string) $row['content'], (string) $row['uid'], $newUnique), 'rolled-back note still authenticates with replacement key');
    }
    foreach ($db->fetchAll("SELECT uid,message FROM messages WHERE message<>'' ORDER BY id") as $row) {
        keyRotationAssert(canDecryptMessage((string) $row['message'], (string) $row['uid'], $oldMsg), 'rolled-back message is not readable with original key');
        keyRotationAssert(!canDecryptMessage((string) $row['message'], (string) $row['uid'], $newMsg), 'rolled-back message still authenticates with replacement key');
    }

    foreach ([(string) $forward['state_path'], (string) $rollback['state_path']] as $path) {
        $bytes = (string) file_get_contents($path);
        foreach ([$oldUnique, $newUnique, $oldMsg, $newMsg] as $secret) {
            keyRotationAssert(!str_contains($bytes, $secret), 'rotation state leaked a raw secret');
        }
    }
} finally {
    $maintenance->leave($transaction, true);
}

fwrite(STDOUT, "[OK] resumable forward and rollback data-key rotation contract\n");
