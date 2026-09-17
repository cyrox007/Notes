<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}

require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/request.php';
require_once $root . '/core/controller.php';
require_once $root . '/modules/tasks/controllers/TaskController.php';

final class TaskHarnessRequest extends \Core\Request
{
    public function __construct(
        private int $userId,
        private array $postData = [],
        private array $serverData = [],
    ) {
    }

    public function session($key = null, $default = null)
    {
        $data = ['user_id' => $this->userId];
        return $key === null ? $data : ($data[$key] ?? $default);
    }

    public function post($key = null, $default = null)
    {
        return $key === null ? $this->postData : ($this->postData[$key] ?? $default);
    }

    public function server($key = null, $default = null)
    {
        return $key === null ? $this->serverData : ($this->serverData[$key] ?? $default);
    }
}

/** @return array<string,mixed> */
function callTaskJson(object $controller, string $method, TaskHarnessRequest $request, array $args = []): array
{
    http_response_code(200);
    ob_start();
    $controller->{$method}($request, ...$args);
    $raw = (string) ob_get_clean();
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException($method . ' did not return JSON: ' . $raw);
    }
    $data['_status'] = http_response_code();
    return $data;
}

$db = \Core\DatabaseManager::getInstance();
$now = date('Y-m-d H:i:s');
$hash = password_hash('ci-password', PASSWORD_ARGON2ID);
foreach ([
    ['91000000-0000-4000-8000-000000000001', 'tasks-alice'],
    ['91000000-0000-4000-8000-000000000002', 'tasks-bob'],
] as [$uid, $username]) {
    $db->execute(
        'INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,created_at,updated_at)
         VALUES (:uid,:username,:email,:hash,"Task","User",888,1,:created_at,:updated_at)',
        [
            ':uid' => $uid,
            ':username' => $username,
            ':email' => $username . '@example.test',
            ':hash' => $hash,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
}

$alice = (int) $db->fetchValue('SELECT id FROM users WHERE username="tasks-alice"');
$bob = (int) $db->fetchValue('SELECT id FROM users WHERE username="tasks-bob"');
$taskUid = '11111111111111111111111111111111';
$db->execute(
    'INSERT INTO tasks (uid,user_id,title,status,priority,is_deleted,created_at,updated_at)
     VALUES (:uid,:user_id,"Alice task","pending","medium",0,:created_at,:updated_at)',
    [':uid' => $taskUid, ':user_id' => $alice, ':created_at' => $now, ':updated_at' => $now]
);

$controller = (new ReflectionClass(\App\Controllers\TaskController::class))->newInstanceWithoutConstructor();
$jsonServer = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

$result = callTaskJson($controller, 'update', new TaskHarnessRequest($alice, ['status' => 'completed'], $jsonServer), [$taskUid]);
if (($result['success'] ?? false) !== true || $result['_status'] !== 200) {
    throw new RuntimeException('Owner update failed');
}
$row = $db->fetchOne('SELECT status,completed_at FROM tasks WHERE uid=:uid', [':uid' => $taskUid]);
if (($row['status'] ?? '') !== 'completed' || empty($row['completed_at'])) {
    throw new RuntimeException('completed_at was not set');
}

callTaskJson($controller, 'update', new TaskHarnessRequest($alice, ['status' => 'pending'], $jsonServer), [$taskUid]);
$row = $db->fetchOne('SELECT status,completed_at FROM tasks WHERE uid=:uid', [':uid' => $taskUid]);
if (($row['status'] ?? '') !== 'pending' || $row['completed_at'] !== null) {
    throw new RuntimeException('completed_at was not cleared');
}

$result = callTaskJson($controller, 'update', new TaskHarnessRequest($alice, ['due_date' => '2026-09-14T15:45'], $jsonServer), [$taskUid]);
if (($result['success'] ?? false) !== true) {
    throw new RuntimeException('datetime-local update failed');
}
$dueDate = (string) $db->fetchValue('SELECT due_date FROM tasks WHERE uid=:uid', [':uid' => $taskUid]);
if ($dueDate !== '2026-09-14 15:45:00') {
    throw new RuntimeException('datetime-local was not normalized: ' . $dueDate);
}

$result = callTaskJson($controller, 'update', new TaskHarnessRequest($alice, ['status' => 'hacked'], $jsonServer), [$taskUid]);
if (($result['success'] ?? true) !== false || $result['_status'] !== 422) {
    throw new RuntimeException('Invalid status was accepted');
}
if ((string) $db->fetchValue('SELECT status FROM tasks WHERE uid=:uid', [':uid' => $taskUid]) !== 'pending') {
    throw new RuntimeException('Invalid status changed persisted state');
}

$result = callTaskJson($controller, 'update', new TaskHarnessRequest($bob, ['status' => 'completed'], $jsonServer), [$taskUid]);
if (($result['success'] ?? true) !== false || !in_array($result['_status'], [403, 404], true)) {
    throw new RuntimeException('Outsider updated task');
}

$result = callTaskJson($controller, 'addSubtask', new TaskHarnessRequest($alice, ['title' => 'Checklist item'], $jsonServer), [$taskUid]);
$subtaskId = (int) ($result['subtask']['id'] ?? 0);
if ($subtaskId <= 0) {
    throw new RuntimeException('Subtask id not returned');
}

$result = callTaskJson($controller, 'toggleSubtask', new TaskHarnessRequest($bob, [], $jsonServer), [$subtaskId]);
if (($result['success'] ?? true) !== false || !in_array($result['_status'], [403, 404], true)) {
    throw new RuntimeException('Outsider toggled subtask');
}
$result = callTaskJson($controller, 'toggleSubtask', new TaskHarnessRequest($alice, [], $jsonServer), [$subtaskId]);
if ((int) ($result['is_completed'] ?? 0) !== 1) {
    throw new RuntimeException('Owner toggle failed');
}

foreach ([
    [$alice, 'Alice category', '#112233'],
    [$bob, 'Bob category', '#445566'],
    [null, 'Global category', '#778899'],
] as [$userId, $name, $color]) {
    $db->execute(
        'INSERT INTO task_categories (user_id,name,color,icon,sort_order,is_deleted,created_at,updated_at)
         VALUES (:user_id,:name,:color,"fa-folder",0,0,:created_at,:updated_at)',
        [':user_id' => $userId, ':name' => $name, ':color' => $color, ':created_at' => $now, ':updated_at' => $now]
    );
}
$aliceCategory = (int) $db->fetchValue('SELECT id FROM task_categories WHERE name="Alice category"');
$bobCategory = (int) $db->fetchValue('SELECT id FROM task_categories WHERE name="Bob category"');
$globalCategory = (int) $db->fetchValue('SELECT id FROM task_categories WHERE name="Global category"');

foreach ([$aliceCategory, $aliceCategory, $globalCategory] as $categoryId) {
    $result = callTaskJson($controller, 'attachCategory', new TaskHarnessRequest($alice, [], $jsonServer), [$taskUid, $categoryId]);
    if (($result['success'] ?? false) !== true) {
        throw new RuntimeException('Accessible category attach failed');
    }
}
$count = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM task_category_relations r INNER JOIN tasks t ON t.id=r.task_id WHERE t.uid=:uid',
    [':uid' => $taskUid]
);
if ($count !== 2) {
    throw new RuntimeException('Category relation is not idempotent');
}

$result = callTaskJson($controller, 'attachCategory', new TaskHarnessRequest($alice, [], $jsonServer), [$taskUid, $bobCategory]);
if (($result['success'] ?? true) !== false || !in_array($result['_status'], [403, 404], true)) {
    throw new RuntimeException('Cross-user category attached');
}

$result = callTaskJson($controller, 'deleteSubtask', new TaskHarnessRequest($bob, [], $jsonServer), [$subtaskId]);
if (($result['success'] ?? true) !== false || !in_array($result['_status'], [403, 404], true)) {
    throw new RuntimeException('Outsider deleted subtask');
}
$result = callTaskJson($controller, 'deleteSubtask', new TaskHarnessRequest($alice, [], $jsonServer), [$subtaskId]);
if (($result['success'] ?? false) !== true || $db->fetchValue('SELECT id FROM subtasks WHERE id=:id', [':id' => $subtaskId]) !== null) {
    throw new RuntimeException('Owner subtask delete failed');
}

echo "Tasks ACL/state contract: OK\n";
