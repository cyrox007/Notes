<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/config.php';

use Core\DatabaseManager;
use Core\UserActionLog;

function auditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] user action log: {$message}\n");
        exit(1);
    }
}

$db = DatabaseManager::getInstance();
$uid = '00000000-0000-4000-8000-' . substr(bin2hex(random_bytes(6)), 0, 12);
$username = 'audit_' . substr(bin2hex(random_bytes(5)), 0, 10);
$email = $username . '@example.test';
$actorId = 0;

try {
    $db->execute(
        'INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status) '
        . 'VALUES (:uid,:username,:email,:password_hash,:firstname,:lastname,888,1,\'active\')',
        [
            ':uid' => $uid,
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => 'fixture-hash',
            ':firstname' => 'Audit',
            ':lastname' => 'Fixture',
        ]
    );
    $actorId = (int) $db->getPdo()->lastInsertId();
    auditAssert($actorId > 0, 'fixture actor was not created');

    $log = new UserActionLog($db);
    $log->record(
        $actorId,
        'http.note_update',
        'notes',
        'http',
        'success',
        302,
        [
            'method' => 'POST',
            'route' => '/notes/17',
            'password' => 'must-not-leak',
            'csrf_token' => 'must-not-leak-either',
        ]
    );
    $log->record($actorId, 'ws.messangersocket.message_send', 'messenger', 'websocket', 'success', null, ['action' => 'message_send']);
    $log->record($actorId, 'http.file_delete', 'files', 'http', 'failure', 409, ['method' => 'POST']);

    $rows = $db->fetchAll(
        'SELECT actor_id,actor_uid,actor_username,module_id,action,transport,outcome,status_code,details '
        . 'FROM user_action_log WHERE actor_uid = :uid ORDER BY id ASC',
        [':uid' => $uid]
    );
    auditAssert(count($rows) === 3, 'unexpected number of recorded actions');
    auditAssert((int) $rows[0]['actor_id'] === $actorId, 'actor id was not persisted');
    auditAssert((string) $rows[0]['actor_username'] === $username, 'actor snapshot was not persisted');
    $details = json_decode((string) $rows[0]['details'], true, 16, JSON_THROW_ON_ERROR);
    auditAssert(($details['password'] ?? null) === '[redacted]', 'password detail was not redacted');
    auditAssert(($details['csrf_token'] ?? null) === '[redacted]', 'CSRF detail was not redacted');
    auditAssert(!str_contains((string) $rows[0]['details'], 'must-not-leak'), 'sensitive value leaked into audit JSON');

    $filtered = $log->search([
        'module_id' => 'files',
        'outcome' => 'failure',
        'q' => $username,
    ], 20, 0);
    auditAssert((int) $filtered['total'] === 1, 'filters did not isolate the failed Files action');
    auditAssert(($filtered['items'][0]['action'] ?? '') === 'http.file_delete', 'filtered action drifted');

    $db->execute(
        'UPDATE user_action_log SET occurred_at = DATE_SUB(NOW(6), INTERVAL 365 DAY) '
        . 'WHERE actor_id = :actor_id AND action = :action',
        [':actor_id' => $actorId, ':action' => 'http.note_update']
    );
    auditAssert($log->countOlderThan(180) === 1, 'retention preview count is incorrect');
    auditAssert($log->purgeOlderThan(180, 10) === 1, 'retention purge did not delete the expired row');
    auditAssert($log->countOlderThan(180) === 0, 'expired audit row remained after purge');

    $db->execute('DELETE FROM users WHERE id = :id', [':id' => $actorId]);
    $actorId = 0;
    $snapshot = $db->fetchOne(
        'SELECT actor_id,actor_uid,actor_username FROM user_action_log WHERE actor_uid = :uid ORDER BY id DESC LIMIT 1',
        [':uid' => $uid]
    );
    auditAssert($snapshot !== null, 'audit history disappeared with the user');
    auditAssert($snapshot['actor_id'] === null, 'deleted user FK was not nulled');
    auditAssert((string) $snapshot['actor_username'] === $username, 'deleted user snapshot was not preserved');

    $router = (string) file_get_contents($root . '/core/Router.php');
    auditAssert(str_contains($router, 'UserActionLog::registerHttpMutation'), 'Router is not wired to the audit log');
    $socket = (string) file_get_contents($root . '/modules/messenger/socket/NativeMessengerServer.php');
    auditAssert(str_contains($socket, 'UserActionLog::emit'), 'WebSocket dispatcher is not wired to the audit log');
    $provider = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
    auditAssert(str_contains($provider, "'/audit'"), 'Admin audit route is missing');
    auditAssert(str_contains($provider, 'RequireAdminAuditView::class'), 'Admin audit route lacks its permission middleware');

    fwrite(STDOUT, "[OK] durable user action journal, redaction, filters, retention and actor snapshots\n");
} finally {
    if ($actorId > 0) {
        $db->execute('DELETE FROM users WHERE id = :id', [':id' => $actorId]);
    }
    $db->execute('DELETE FROM user_action_log WHERE actor_uid = :uid', [':uid' => $uid]);
}
