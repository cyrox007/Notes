#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
DB_NAME="${DBNAME:-notes_test}"
MYSQL=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p$DB_PASS" "$DB_NAME")

"${MYSQL[@]}" -e 'SET FOREIGN_KEY_CHECKS=0; SHOW TABLES' >/dev/null
"${MYSQL[@]}" < database/messenger_schema.sql
"${MYSQL[@]}" < database/access_control_schema.sql
"${MYSQL[@]}" < database/migrations/20260915_role_module_policies.sql
"${MYSQL[@]}" < database/task_boards_schema.sql

DBHOST="$DB_HOST" DBPORT="$DB_PORT" DBUSER="$DB_USER" DBPASS="$DB_PASS" DBNAME="$DB_NAME" php <<'PHP'
<?php

declare(strict_types=1);

if (!defined('SITEPATH')) {
    define('SITEPATH', getcwd());
}

putenv('DBDRIVER=mysql');
putenv('DBHOST=' . getenv('DBHOST'));
putenv('DBPORT=' . getenv('DBPORT'));
putenv('DBUSER=' . getenv('DBUSER'));
putenv('DBPASS=' . getenv('DBPASS'));
putenv('DBNAME=' . getenv('DBNAME'));

require SITEPATH . '/core/config.php';
require SITEPATH . '/core/DatabaseManager.php';
require SITEPATH . '/app/services/PermissionService.php';
require SITEPATH . '/app/services/RolePolicyService.php';
require SITEPATH . '/app/services/TaskBoardService.php';

use App\Services\TaskBoardService;
use Core\DatabaseManager;
use DomainException;

$db = DatabaseManager::getInstance();
$db->execute('DELETE FROM task_board_assignees');
$db->execute('DELETE FROM task_board_items');
$db->execute('DELETE FROM task_board_members');
$db->execute('DELETE FROM task_boards');
$db->execute('DELETE FROM role_module_policies');
$db->execute('DELETE FROM users');

$now = date('Y-m-d H:i:s');
$password = password_hash('beta4-test', PASSWORD_DEFAULT);
$fixtures = [
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'owner', 'Owner', 'One', 'active', 1],
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'member', 'Member', 'Two', 'active', 1],
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'outsider', 'Outside', 'Three', 'active', 1],
    ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', 'blocked', 'Blocked', 'Four', 'blocked', 0],
];
foreach ($fixtures as [$uid, $username, $firstname, $lastname, $status, $active]) {
    $db->execute(
        'INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status,created_at,updated_at)
         VALUES (:uid,:username,:email,:password,:firstname,:lastname,888,:active,:status,:created_at,:updated_at)',
        [
            ':uid' => $uid,
            ':username' => $username,
            ':email' => $username . '@beta4.test',
            ':password' => $password,
            ':firstname' => $firstname,
            ':lastname' => $lastname,
            ':active' => $active,
            ':status' => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
}

$id = static fn(string $username): int => (int) $db->fetchValue(
    'SELECT id FROM users WHERE username = :username',
    [':username' => $username]
);
$owner = $id('owner');
$member = $id('member');
$outsider = $id('outsider');
$blocked = $id('blocked');

$service = new TaskBoardService($db);
$selectedUid = $service->createBoard($owner, 'Selected team', 'members', [$member]);
$selected = $service->boardForUser($member, $selectedUid);
if (($selected['access_role'] ?? null) !== 'member') {
    throw new RuntimeException('Selected member does not receive board access');
}

$outsideDenied = false;
try {
    $service->boardForUser($outsider, $selectedUid);
} catch (DomainException) {
    $outsideDenied = true;
}
if (!$outsideDenied) {
    throw new RuntimeException('Outsider can read selected-member board');
}

$taskUid = $service->createTask(
    $owner,
    $selectedUid,
    'Shared task',
    'Contract task',
    'pending',
    'medium',
    null,
    [$member]
);
$tasks = $service->listTasks($member, $selectedUid);
if (count($tasks) !== 1 || ($tasks[0]['uid'] ?? null) !== $taskUid) {
    throw new RuntimeException('Board member cannot read shared task');
}
if ((int) ($tasks[0]['assignees'][0]['id'] ?? 0) !== $member) {
    throw new RuntimeException('Shared task assignee was not persisted');
}
$updated = $service->updateTask($member, $taskUid, ['status' => 'completed']);
if (($updated['status'] ?? null) !== 'completed') {
    throw new RuntimeException('Board member cannot update shared task status');
}

$outsideMutationDenied = false;
try {
    $service->updateTask($outsider, $taskUid, ['status' => 'in_progress']);
} catch (DomainException) {
    $outsideMutationDenied = true;
}
if (!$outsideMutationDenied) {
    throw new RuntimeException('Outsider can mutate selected-member board task');
}

$allUid = $service->createBoard($owner, 'Everyone', 'all_active', []);
if (($service->boardForUser($outsider, $allUid)['access_role'] ?? null) !== 'member') {
    throw new RuntimeException('Active user cannot access all-active board');
}
$blockedDenied = false;
try {
    $service->boardForUser($blocked, $allUid);
} catch (DomainException) {
    $blockedDenied = true;
}
if (!$blockedDenied) {
    throw new RuntimeException('Blocked user can access all-active board');
}

$userRoleId = (int) $db->fetchValue("SELECT id FROM roles WHERE code = 'user' LIMIT 1");
$db->execute(
    "INSERT INTO role_module_policies (role_id,module_id,policy_key,value_type,value_json,updated_by)
     VALUES (:role_id,'tasks','can_create_shared_boards','bool','false',NULL)",
    [':role_id' => $userRoleId]
);
$policyDenied = false;
try {
    (new TaskBoardService($db))->createBoard($owner, 'Should fail', 'members', []);
} catch (DomainException) {
    $policyDenied = true;
}
if (!$policyDenied) {
    throw new RuntimeException('Role policy did not block shared-board creation');
}

echo "Beta 4 shared task board contract: OK\n";
PHP
