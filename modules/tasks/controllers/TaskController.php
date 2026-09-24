<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ListQuery;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class TaskController extends Controller
{
    private const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const FILTERS = ['all', 'today', 'week', 'overdue', 'pending', 'in_progress', 'completed', 'cancelled'];

    /** @var array<string,string> */
    private const SORT_COLUMNS = [
        'created_at' => 't.created_at',
        'updated_at' => 't.updated_at',
        'title' => 't.title',
        'due_date' => 't.due_date',
        'priority' => 't.priority',
        'status' => 't.status',
    ];

    /** @var array<string,string> */
    private const PRIORITY_COLORS = [
        'low' => '#95a5a6',
        'medium' => '#3498db',
        'high' => '#f39c12',
        'urgent' => '#e74c3c',
    ];

    /** @var array<string,string> */
    private const STATUS_LABELS = [
        'pending' => 'Ожидает',
        'in_progress' => 'В процессе',
        'completed' => 'Завершена',
        'cancelled' => 'Отменена',
    ];

    public function index(Request $request): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();

        $filter = strtolower((string) $request->get('filter', 'all'));
        if (!in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $query = ListQuery::fromRequest($request, self::SORT_COLUMNS, 'created_at');
        $params = [':user_id' => (int) $user->id];
        $where = ['t.user_id = :user_id', 't.is_deleted = 0'];
        $now = date('Y-m-d H:i:s');

        if ($query['q'] !== '') {
            $where[] = '(t.title LIKE :q OR t.description LIKE :q)';
            $params[':q'] = '%' . $query['q'] . '%';
        }

        switch ($filter) {
            case 'today':
                $where[] = 't.due_date >= :today_start AND t.due_date < :tomorrow_start';
                $params[':today_start'] = date('Y-m-d 00:00:00');
                $params[':tomorrow_start'] = date('Y-m-d 00:00:00', strtotime('+1 day'));
                break;
            case 'week':
                $where[] = 't.due_date >= :week_start AND t.due_date < :week_end';
                $params[':week_start'] = date('Y-m-d 00:00:00');
                $params[':week_end'] = date('Y-m-d 00:00:00', strtotime('+7 days'));
                break;
            case 'overdue':
                $where[] = 't.due_date IS NOT NULL AND t.due_date < :now';
                $where[] = 't.status NOT IN ("completed", "cancelled")';
                $params[':now'] = $now;
                break;
            default:
                if (in_array($filter, self::STATUSES, true)) {
                    $where[] = 't.status = :status';
                    $params[':status'] = $filter;
                }
                break;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $db->fetchValue('SELECT COUNT(*) FROM tasks t WHERE ' . $whereSql, $params);
        $tasks = $db->fetchAll(
            'SELECT t.* FROM tasks t WHERE ' . $whereSql
            . ' ORDER BY ' . $query['sort_column'] . ' ' . $query['direction_sql'] . ', t.id DESC'
            . ' LIMIT ' . (int) $query['limit'] . ' OFFSET ' . (int) $query['offset'],
            $params
        );
        $this->hydrateTaskRelations($tasks, (int) $user->id);

        $categories = $db->fetchAll(
            'SELECT id,user_id,name,color,icon,sort_order
             FROM task_categories
             WHERE is_deleted = 0 AND (user_id = :user_id OR user_id IS NULL)
             ORDER BY sort_order ASC, name ASC, id ASC',
            [':user_id' => (int) $user->id]
        );
        foreach ($categories as &$category) {
            $category = $this->normalizeCategory($category);
        }
        unset($category);

        $statsRow = $db->fetchOne(
            'SELECT
                COUNT(*) AS total,
                SUM(status = "pending") AS pending,
                SUM(status = "in_progress") AS in_progress,
                SUM(status = "completed") AS completed,
                SUM(status NOT IN ("completed", "cancelled") AND due_date IS NOT NULL AND due_date < :now) AS overdue
             FROM tasks
             WHERE user_id = :user_id AND is_deleted = 0',
            [':user_id' => (int) $user->id, ':now' => $now]
        ) ?? [];

        $pagination = ListQuery::pagination($query, $total);
        $pagination['filter'] = $filter;
        $this->render_template('@tasks/index', [
            'tasks' => $tasks,
            'categories' => $categories,
            'user' => $user,
            'currentFilter' => $filter,
            'currentSort' => $query['sort'],
            'currentDirection' => $query['direction'],
            'stats' => [
                'total' => (int) ($statsRow['total'] ?? 0),
                'pending' => (int) ($statsRow['pending'] ?? 0),
                'in_progress' => (int) ($statsRow['in_progress'] ?? 0),
                'completed' => (int) ($statsRow['completed'] ?? 0),
                'overdue' => (int) ($statsRow['overdue'] ?? 0),
            ],
            'pagination' => $pagination,
        ]);
    }

    public function create(Request $request): void
    {
        try {
            $user = $this->currentUser($request);
            $title = $this->taskTitle((string) $request->post('title', ''));
            $description = $this->description((string) $request->post('description', ''));
            $status = $this->status((string) $request->post('status', 'pending'));
            $priority = $this->priority((string) $request->post('priority', 'medium'));
            $dueDate = $this->dateTimeOrNull((string) $request->post('due_date', ''));
            $uid = bin2hex(random_bytes(16));
            $now = date('Y-m-d H:i:s');

            DatabaseManager::getInstance()->execute(
                'INSERT INTO tasks (
                    uid,user_id,title,description,status,priority,due_date,completed_at,
                    is_deleted,deleted_at,created_at,updated_at
                 ) VALUES (
                    :uid,:user_id,:title,:description,:status,:priority,:due_date,:completed_at,
                    0,NULL,:created_at,:updated_at
                 )',
                [
                    ':uid' => $uid,
                    ':user_id' => (int) $user->id,
                    ':title' => $title,
                    ':description' => $description,
                    ':status' => $status,
                    ':priority' => $priority,
                    ':due_date' => $dueDate,
                    ':completed_at' => $status === 'completed' ? $now : null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );

            Router::getInstance()->redirect('tasks', 'name');
        } catch (InvalidArgumentException $e) {
            $this->validationFailure($request, $e->getMessage());
        }
    }

    public function update(Request $request, string $uid): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $task = $db->fetchOne(
            'SELECT * FROM tasks
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => (int) $user->id]
        );

        if (!$task) {
            $this->respondNotFound($request, 'Задача не найдена');
            return;
        }

        try {
            $post = $request->post();
            if (!is_array($post)) {
                throw new InvalidArgumentException('Некорректные данные задачи');
            }

            $changes = [];
            if (array_key_exists('title', $post)) {
                $changes['title'] = $this->taskTitle((string) $post['title']);
            }
            if (array_key_exists('description', $post)) {
                $changes['description'] = $this->description((string) $post['description']);
            }
            if (array_key_exists('priority', $post)) {
                $changes['priority'] = $this->priority((string) $post['priority']);
            }
            if (array_key_exists('due_date', $post)) {
                $changes['due_date'] = $this->dateTimeOrNull((string) $post['due_date']);
            }
            if (array_key_exists('status', $post)) {
                $newStatus = $this->status((string) $post['status']);
                $changes['status'] = $newStatus;
                if ($newStatus === 'completed' && (string) $task['status'] !== 'completed') {
                    $changes['completed_at'] = date('Y-m-d H:i:s');
                } elseif ($newStatus !== 'completed') {
                    $changes['completed_at'] = null;
                }
            }
        } catch (InvalidArgumentException $e) {
            $this->validationFailure($request, $e->getMessage());
            return;
        }

        if ($changes === []) {
            if ($this->expectsJson($request)) {
                $this->json(['success' => true, 'task_uid' => $uid]);
                return;
            }
            Router::getInstance()->redirect('tasks', 'name');
            return;
        }

        $changes['updated_at'] = date('Y-m-d H:i:s');
        $params = [':id' => (int) $task['id'], ':user_id' => (int) $user->id];
        $set = [];
        foreach ($changes as $column => $value) {
            $placeholder = ':' . $column;
            $set[] = $column . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $db->execute(
            'UPDATE tasks SET ' . implode(', ', $set)
            . ' WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            $params
        );

        if ($this->expectsJson($request)) {
            $this->json([
                'success' => true,
                'task_uid' => $uid,
                'status' => $changes['status'] ?? (string) $task['status'],
            ]);
            return;
        }

        Router::getInstance()->redirect('tasks', 'name');
    }

    public function delete(Request $request, string $uid): void
    {
        $user = $this->currentUser($request);
        DatabaseManager::getInstance()->execute(
            'UPDATE tasks
             SET is_deleted = 1, deleted_at = :deleted_at, updated_at = :updated_at
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0',
            [
                ':deleted_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':uid' => $uid,
                ':user_id' => (int) $user->id,
            ]
        );
        Router::getInstance()->redirect('tasks', 'name');
    }

    public function addSubtask(Request $request, string $taskUid): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $task = $this->ownedTask($db, $taskUid, (int) $user->id);
        if (!$task) {
            $this->jsonError('Доступ запрещен', 403);
            return;
        }

        $title = trim((string) $request->post('title', ''));
        if ($title === '' || mb_strlen($title) > 255) {
            $this->jsonError('Название подзадачи должно содержать от 1 до 255 символов', 422);
            return;
        }

        $sortOrder = (int) $db->fetchValue(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM subtasks WHERE task_id = :task_id',
            [':task_id' => (int) $task['id']]
        );
        $now = date('Y-m-d H:i:s');
        $db->execute(
            'INSERT INTO subtasks (task_id,title,is_completed,completed_at,sort_order,created_at,updated_at)
             VALUES (:task_id,:title,0,NULL,:sort_order,:created_at,:updated_at)',
            [
                ':task_id' => (int) $task['id'],
                ':title' => $title,
                ':sort_order' => $sortOrder,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
        $id = (int) $db->getPdo()->lastInsertId();

        $this->json([
            'success' => true,
            'subtask' => [
                'id' => $id,
                'title' => $title,
                'is_completed' => 0,
                'sort_order' => $sortOrder,
            ],
        ]);
    }

    public function toggleSubtask(Request $request, int $subtaskId): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $subtask = $db->fetchOne(
            'SELECT s.id,s.is_completed
             FROM subtasks s
             INNER JOIN tasks t ON t.id = s.task_id
             WHERE s.id = :id AND t.user_id = :user_id AND t.is_deleted = 0
             LIMIT 1',
            [':id' => $subtaskId, ':user_id' => (int) $user->id]
        );
        if (!$subtask) {
            $this->jsonError('Подзадача не найдена или недоступна', 404);
            return;
        }

        $newStatus = (int) $subtask['is_completed'] === 1 ? 0 : 1;
        $db->execute(
            'UPDATE subtasks
             SET is_completed = :is_completed,
                 completed_at = :completed_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':is_completed' => $newStatus,
                ':completed_at' => $newStatus === 1 ? date('Y-m-d H:i:s') : null,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $subtaskId,
            ]
        );
        $this->json(['success' => true, 'is_completed' => $newStatus]);
    }

    public function deleteSubtask(Request $request, int $subtaskId): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $allowed = $db->fetchValue(
            'SELECT s.id
             FROM subtasks s
             INNER JOIN tasks t ON t.id = s.task_id
             WHERE s.id = :id AND t.user_id = :user_id AND t.is_deleted = 0
             LIMIT 1',
            [':id' => $subtaskId, ':user_id' => (int) $user->id]
        );
        if ($allowed === null) {
            $this->jsonError('Подзадача не найдена или недоступна', 404);
            return;
        }

        $db->execute('DELETE FROM subtasks WHERE id = :id', [':id' => $subtaskId]);
        $this->json(['success' => true]);
    }

    public function createCategory(Request $request): void
    {
        try {
            $user = $this->currentUser($request);
            $name = trim((string) $request->post('name', ''));
            if ($name === '' || mb_strlen($name) > 120) {
                throw new InvalidArgumentException('Название категории должно содержать от 1 до 120 символов');
            }

            $color = strtolower(trim((string) $request->post('color', '#3498db')));
            if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
                throw new InvalidArgumentException('Некорректный цвет категории');
            }

            $icon = strtolower(trim((string) $request->post('icon', 'fa-folder')));
            if (preg_match('/^fa-[a-z0-9-]{1,48}$/', $icon) !== 1) {
                throw new InvalidArgumentException('Некорректная иконка категории');
            }

            DatabaseManager::getInstance()->execute(
                'INSERT INTO task_categories (user_id,name,color,icon,sort_order,is_deleted,created_at,updated_at)
                 VALUES (:user_id,:name,:color,:icon,0,0,:created_at,:updated_at)',
                [
                    ':user_id' => (int) $user->id,
                    ':name' => $name,
                    ':color' => $color,
                    ':icon' => $icon,
                    ':created_at' => date('Y-m-d H:i:s'),
                    ':updated_at' => date('Y-m-d H:i:s'),
                ]
            );
            Router::getInstance()->redirect('tasks', 'name');
        } catch (InvalidArgumentException $e) {
            $this->validationFailure($request, $e->getMessage());
        }
    }

    public function attachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $task = $this->ownedTask($db, $taskUid, (int) $user->id);
        if (!$task) {
            $this->jsonError('Доступ запрещен', 403);
            return;
        }

        $category = $db->fetchOne(
            'SELECT id FROM task_categories
             WHERE id = :id AND is_deleted = 0
               AND (user_id = :user_id OR user_id IS NULL)
             LIMIT 1',
            [':id' => $categoryId, ':user_id' => (int) $user->id]
        );
        if (!$category) {
            $this->jsonError('Категория не найдена', 404);
            return;
        }

        $db->execute(
            'INSERT IGNORE INTO task_category_relations (task_id,category_id,created_at)
             VALUES (:task_id,:category_id,:created_at)',
            [
                ':task_id' => (int) $task['id'],
                ':category_id' => $categoryId,
                ':created_at' => date('Y-m-d H:i:s'),
            ]
        );
        $this->json(['success' => true]);
    }

    public function detachCategory(Request $request, string $taskUid, int $categoryId): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $task = $this->ownedTask($db, $taskUid, (int) $user->id);
        if (!$task) {
            $this->jsonError('Доступ запрещен', 403);
            return;
        }

        $db->execute(
            'DELETE FROM task_category_relations WHERE task_id = :task_id AND category_id = :category_id',
            [':task_id' => (int) $task['id'], ':category_id' => $categoryId]
        );
        $this->json(['success' => true]);
    }

    /** @param array<int,array<string,mixed>> $tasks */
    private function hydrateTaskRelations(array &$tasks, int $userId): void
    {
        if ($tasks === []) {
            return;
        }

        $db = DatabaseManager::getInstance();
        $taskIds = array_map(static fn (array $task): int => (int) $task['id'], $tasks);
        $placeholders = [];
        $params = [];
        foreach ($taskIds as $index => $id) {
            $key = ':task_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $in = implode(',', $placeholders);

        $subtasks = $db->fetchAll(
            'SELECT id,task_id,title,is_completed,completed_at,sort_order,created_at,updated_at
             FROM subtasks WHERE task_id IN (' . $in . ')
             ORDER BY task_id ASC, sort_order ASC, id ASC',
            $params
        );
        $categoryRows = $db->fetchAll(
            'SELECT r.task_id,c.id,c.user_id,c.name,c.color,c.icon,c.sort_order
             FROM task_category_relations r
             INNER JOIN task_categories c ON c.id = r.category_id AND c.is_deleted = 0
             WHERE r.task_id IN (' . $in . ')
               AND (c.user_id = :user_id OR c.user_id IS NULL)
             ORDER BY r.task_id ASC,c.sort_order ASC,c.name ASC,c.id ASC',
            $params + [':user_id' => $userId]
        );

        $subtaskMap = [];
        foreach ($subtasks as $subtask) {
            $subtaskMap[(int) $subtask['task_id']][] = $subtask;
        }
        $categoryMap = [];
        foreach ($categoryRows as $category) {
            $taskId = (int) $category['task_id'];
            unset($category['task_id']);
            $categoryMap[$taskId][] = $this->normalizeCategory($category);
        }

        $now = time();
        foreach ($tasks as &$task) {
            $taskId = (int) $task['id'];
            $task['categories'] = $categoryMap[$taskId] ?? [];
            $task['subtasks'] = $subtaskMap[$taskId] ?? [];
            $task['priority_color'] = self::PRIORITY_COLORS[(string) $task['priority']] ?? '#3498db';
            $task['status_label'] = self::STATUS_LABELS[(string) $task['status']] ?? 'Неизвестный статус';
            $task['is_overdue'] = $task['due_date'] !== null
                && !in_array((string) $task['status'], ['completed', 'cancelled'], true)
                && strtotime((string) $task['due_date']) < $now;

            $totalSubtasks = count($task['subtasks']);
            $completed = 0;
            foreach ($task['subtasks'] as $subtask) {
                if ((int) $subtask['is_completed'] === 1) {
                    $completed++;
                }
            }
            $task['completion_percentage'] = $totalSubtasks > 0
                ? (int) round(($completed / $totalSubtasks) * 100)
                : 0;
        }
        unset($task);
    }

    /** @return object{id:int,uid:string,username:string,firstname:string,lastname:string,avatar:?string,role:int,is_active:int} */
    private function currentUser(Request $request): object
    {
        $id = (int) $request->session('user_id', 0);
        if ($id <= 0) {
            throw new RuntimeException('Требуется авторизация');
        }

        $row = DatabaseManager::getInstance()->fetchOne(
            'SELECT id,uid,username,firstname,lastname,avatar,role,is_active
             FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
            [':id' => $id]
        );
        if (!$row) {
            throw new RuntimeException('Пользователь не найден или заблокирован');
        }

        return (object) $row;
    }

    /** @return array<string,mixed>|null */
    private function ownedTask(DatabaseManager $db, string $uid, int $userId): ?array
    {
        return $db->fetchOne(
            'SELECT id,uid,status FROM tasks
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => $userId]
        );
    }

    /** @param array<string,mixed> $category @return array<string,mixed> */
    private function normalizeCategory(array $category): array
    {
        $color = strtolower((string) ($category['color'] ?? ''));
        $category['color'] = preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : '#3498db';

        $icon = strtolower((string) ($category['icon'] ?? ''));
        $category['icon'] = preg_match('/^fa-[a-z0-9-]{1,48}$/', $icon) === 1 ? $icon : 'fa-folder';
        return $category;
    }

    private function taskTitle(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Название задачи должно содержать от 1 до 255 символов');
        }
        return $title;
    }

    private function description(string $description): ?string
    {
        $description = trim($description);
        if (mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Описание задачи слишком длинное');
        }
        return $description === '' ? null : $description;
    }

    private function status(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Некорректный статус задачи');
        }
        return $status;
    }

    private function priority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        if (!in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Некорректный приоритет задачи');
        }
        return $priority;
    }

    private function dateTimeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d\\TH:i', 'Y-m-d\\TH:i:s', 'Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Некорректная дата выполнения');
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->server('HTTP_ACCEPT', ''));
        $requestedWith = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', ''));
        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    private function respondNotFound(Request $request, string $message): void
    {
        if ($this->expectsJson($request)) {
            $this->jsonError($message, 404);
            return;
        }
        Router::getInstance()->redirect('tasks', 'name');
    }

    private function validationFailure(Request $request, string $message): void
    {
        if ($this->expectsJson($request)) {
            $this->jsonError($message, 422);
            return;
        }
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function jsonError(string $message, int $status): void
    {
        $this->json(['success' => false, 'error' => $message], $status);
    }
}
