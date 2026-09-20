<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/SecurityEventLog.php';

use InvalidArgumentException;
use Throwable;

final class UserActionLog
{
    private const TRANSPORTS = ['http', 'websocket', 'system', 'cli'];
    private const OUTCOMES = ['success', 'failure'];
    private const SENSITIVE_KEY = '/(?:^|_)(password|passphrase|secret|token|authorization|cookie|csrf|session)(?:$|_)/i';

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @param array<string,mixed> $details */
    public static function emit(
        int $actorId,
        string $action,
        string $moduleId,
        string $transport = 'http',
        string $outcome = 'success',
        ?int $statusCode = null,
        array $details = []
    ): bool {
        if ($actorId <= 0) {
            return false;
        }
        try {
            (new self())->record($actorId, $action, $moduleId, $transport, $outcome, $statusCode, $details);
            return true;
        } catch (Throwable $e) {
            SecurityEventLog::emit(
                'audit.write_failed',
                'critical',
                'user_action_log',
                'user',
                $actorId,
                [
                    'action' => mb_substr($action, 0, 128),
                    'module_id' => mb_substr($moduleId, 0, 64),
                    'error_type' => get_debug_type($e),
                ]
            );
            error_log('User action audit write failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $details */
    public function record(
        int $actorId,
        string $action,
        string $moduleId,
        string $transport = 'http',
        string $outcome = 'success',
        ?int $statusCode = null,
        array $details = []
    ): void {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('Audit actor id must be positive');
        }
        $action = strtolower(trim($action));
        $moduleId = strtolower(trim($moduleId));
        $transport = strtolower(trim($transport));
        $outcome = strtolower(trim($outcome));

        if (preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $action) !== 1) {
            throw new InvalidArgumentException('Invalid audit action code');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $moduleId) !== 1) {
            throw new InvalidArgumentException('Invalid audit module id');
        }
        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw new InvalidArgumentException('Invalid audit transport');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Invalid audit outcome');
        }
        if ($statusCode !== null && ($statusCode < 100 || $statusCode > 599)) {
            throw new InvalidArgumentException('Invalid audit status code');
        }

        $actor = $this->db->fetchOne(
            'SELECT uid,username FROM users WHERE id = :id LIMIT 1',
            [':id' => $actorId]
        );
        if (!$actor) {
            throw new InvalidArgumentException('Audit actor does not exist');
        }

        $safeDetails = $this->sanitizeDetails($details);
        $encodedDetails = $safeDetails === []
            ? null
            : json_encode($safeDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->db->execute(
            'INSERT INTO user_action_log '
            . '(actor_id,actor_uid,actor_username,module_id,action,transport,outcome,status_code,details,occurred_at) '
            . 'VALUES (:actor_id,:actor_uid,:actor_username,:module_id,:action,:transport,:outcome,:status_code,:details,NOW(6))',
            [
                ':actor_id' => $actorId,
                ':actor_uid' => (string) ($actor['uid'] ?? ''),
                ':actor_username' => (string) ($actor['username'] ?? ''),
                ':module_id' => $moduleId,
                ':action' => $action,
                ':transport' => $transport,
                ':outcome' => $outcome,
                ':status_code' => $statusCode,
                ':details' => $encodedDetails,
            ]
        );
    }

    public static function registerHttpMutation(
        int $actorId,
        string $method,
        string $routeName,
        string $routePath
    ): void {
        if ($actorId <= 0) {
            return;
        }
        $method = strtoupper(trim($method));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $moduleId = self::moduleFromRoutePath($routePath);
        $routeName = strtolower(trim($routeName));
        $action = $routeName !== '' && preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $routeName) === 1
            ? 'http.' . $routeName
            : 'http.' . strtolower($method) . '.' . $moduleId;

        register_shutdown_function(static function () use ($actorId, $action, $moduleId, $method, $routePath): void {
            $status = http_response_code();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = 200;
            }
            self::emit(
                $actorId,
                $action,
                $moduleId,
                'http',
                $status >= 400 ? 'failure' : 'success',
                $status,
                ['method' => $method, 'route' => $routePath]
            );
        });
    }

    /** @param array<string,mixed> $filters @return array{items:list<array<string,mixed>>,total:int} */
    public function search(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $where = [];
        $params = [];

        $actorId = (int) ($filters['actor_id'] ?? 0);
        if ($actorId > 0) {
            $where[] = 'ual.actor_id = :actor_id';
            $params[':actor_id'] = $actorId;
        }

        $moduleId = strtolower(trim((string) ($filters['module_id'] ?? '')));
        if ($moduleId !== '') {
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $moduleId) !== 1) {
                throw new InvalidArgumentException('Invalid audit module filter');
            }
            $where[] = 'ual.module_id = :module_id';
            $params[':module_id'] = $moduleId;
        }

        $outcome = strtolower(trim((string) ($filters['outcome'] ?? '')));
        if ($outcome !== '') {
            if (!in_array($outcome, self::OUTCOMES, true)) {
                throw new InvalidArgumentException('Invalid audit outcome filter');
            }
            $where[] = 'ual.outcome = :outcome';
            $params[':outcome'] = $outcome;
        }

        $action = strtolower(trim((string) ($filters['action'] ?? '')));
        if ($action !== '') {
            $action = mb_substr($action, 0, 120);
            $where[] = 'ual.action LIKE :action';
            $params[':action'] = '%' . $action . '%';
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Invalid audit date filter');
            }
            $where[] = 'ual.occurred_at ' . $operator . ' :' . $key . '_at';
            $params[':' . $key . '_at'] = $value . ($key === 'from' ? ' 00:00:00' : ' 23:59:59.999999');
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $q = mb_substr($q, 0, 120);
            $where[] = '(ual.actor_username LIKE :q_user OR ual.actor_uid LIKE :q_uid OR ual.action LIKE :q_action OR ual.module_id LIKE :q_module)';
            $needle = '%' . $q . '%';
            $params[':q_user'] = $needle;
            $params[':q_uid'] = $needle;
            $params[':q_action'] = $needle;
            $params[':q_module'] = $needle;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_action_log ual' . $whereSql, $params);
        $items = $this->db->fetchAll(
            'SELECT ual.id,ual.occurred_at,ual.actor_id,ual.actor_uid,ual.actor_username,'
            . 'ual.module_id,ual.action,ual.transport,ual.outcome,ual.status_code,ual.details '
            . 'FROM user_action_log ual' . $whereSql
            . ' ORDER BY ual.occurred_at DESC, ual.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        foreach ($items as &$item) {
            $decoded = null;
            if (is_string($item['details'] ?? null) && $item['details'] !== '') {
                $decoded = json_decode((string) $item['details'], true);
            } elseif (is_array($item['details'] ?? null)) {
                $decoded = $item['details'];
            }
            $item['details'] = is_array($decoded) ? $decoded : [];
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    public function countOlderThan(int $days): int
    {
        $days = self::normalizeRetentionDays($days);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM user_action_log WHERE occurred_at < :cutoff',
            [':cutoff' => $cutoff]
        );
    }

    public function purgeOlderThan(int $days, int $limit = 1000): int
    {
        $days = self::normalizeRetentionDays($days);
        $limit = max(1, min(5000, $limit));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $rows = $this->db->fetchAll(
            'SELECT id FROM user_action_log WHERE occurred_at < :cutoff ORDER BY id ASC LIMIT ' . $limit,
            [':cutoff' => $cutoff]
        );
        if ($rows === []) {
            return 0;
        }

        $placeholders = [];
        $params = [];
        foreach ($rows as $index => $row) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $row['id'];
        }
        return (int) $this->db->execute(
            'DELETE FROM user_action_log WHERE id IN (' . implode(',', $placeholders) . ')',
            $params
        );
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function sanitizeDetails(array $details): array
    {
        $safe = [];
        $count = 0;
        foreach ($details as $key => $value) {
            if ($count++ >= 24) {
                break;
            }
            $name = mb_substr((string) $key, 0, 64);
            if ($name === '') {
                continue;
            }
            if (preg_match(self::SENSITIVE_KEY, $name) === 1) {
                $safe[$name] = '[redacted]';
                continue;
            }
            $safe[$name] = $this->sanitizeValue($value, 0);
        }
        return $safe;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 512);
        }
        if (is_array($value) && $depth < 2) {
            $safe = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if ($count++ >= 16) {
                    break;
                }
                $name = mb_substr((string) $key, 0, 64);
                if (preg_match(self::SENSITIVE_KEY, $name) === 1) {
                    $safe[$name] = '[redacted]';
                    continue;
                }
                $safe[$name] = $this->sanitizeValue($child, $depth + 1);
            }
            return $safe;
        }
        return '[' . get_debug_type($value) . ']';
    }

    private static function moduleFromRoutePath(string $routePath): string
    {
        $relative = trim(str_replace('\\', '/', $routePath), '/');
        $base = trim(str_replace('\\', '/', (string) (getenv('BASE_PATH') ?: '/')), '/');
        if ($base !== '' && ($relative === $base || str_starts_with($relative, $base . '/'))) {
            $relative = ltrim(substr($relative, strlen($base)), '/');
        }
        $segment = strtolower((string) (explode('/', $relative, 2)[0] ?? ''));
        return in_array($segment, ['admin', 'notes', 'tasks', 'files', 'messenger', 'profile'], true)
            ? $segment
            : 'core';
    }

    private static function normalizeRetentionDays(int $days): int
    {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Audit retention must be between 1 and 3650 days');
        }
        return $days;
    }
}
