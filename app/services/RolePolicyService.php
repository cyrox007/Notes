<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Typed quantitative/module-feature policy layer kept deliberately separate
 * from RBAC. Permissions answer "may this actor use the capability?" while
 * these policies answer "how much / how often / which resource types?".
 */
final class RolePolicyService
{
    /**
     * A zero integer means "no role-specific cap / inherit the platform cap".
     * An empty string-list means "use the platform allow-list".
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    private const DEFINITIONS = [
        'files' => [
            'max_storage_bytes' => [
                'label' => 'Квота файлового хранилища',
                'type' => 'int',
                'default' => 0,
                'unit' => 'bytes',
                'help' => '0 — использовать системную/персональную квоту без дополнительного ограничения роли.',
            ],
            'max_file_bytes' => [
                'label' => 'Максимальный размер одного файла',
                'type' => 'int',
                'default' => 0,
                'unit' => 'bytes',
                'help' => '0 — использовать текущий платформенный лимит загрузки.',
            ],
            'allowed_extensions' => [
                'label' => 'Разрешённые расширения',
                'type' => 'string_list',
                'default' => [],
                'help' => 'Пусто — использовать платформенный allow-list. Значения через запятую, например: pdf, docx, jpg.',
            ],
            'can_create_folders' => [
                'label' => 'Создание папок',
                'type' => 'bool',
                'default' => true,
                'help' => 'Можно ли пользователям этой роли создавать новые папки.',
            ],
            'can_share' => [
                'label' => 'Публичные ссылки на файлы',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить создание отзывных публичных ссылок на файлы из личного хранилища.',
            ],
        ],
        'messenger' => [
            'messages_per_minute' => [
                'label' => 'Сообщений в минуту',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — без дополнительного ограничения роли.',
            ],
            'max_attachment_bytes' => [
                'label' => 'Максимальный размер вложения',
                'type' => 'int',
                'default' => 0,
                'unit' => 'bytes',
                'help' => '0 — использовать платформенный лимит.',
            ],
            'allowed_attachment_extensions' => [
                'label' => 'Разрешённые типы вложений',
                'type' => 'string_list',
                'default' => [],
                'help' => 'Пусто — использовать платформенный allow-list.',
            ],
            'can_create_groups' => [
                'label' => 'Создание групп',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить создание групповых диалогов.',
            ],
            'max_group_members' => [
                'label' => 'Участников в группе',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — использовать платформенный лимит.',
            ],
            'can_send_voice' => [
                'label' => 'Голосовые сообщения',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить запись и отправку голосовых сообщений.',
            ],
        ],
        'notes' => [
            'max_notes' => [
                'label' => 'Количество заметок',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — без дополнительного ограничения роли.',
            ],
            'max_attachment_bytes' => [
                'label' => 'Максимальный размер вложения',
                'type' => 'int',
                'default' => 0,
                'unit' => 'bytes',
                'help' => '0 — использовать платформенный лимит.',
            ],
            'allowed_attachment_extensions' => [
                'label' => 'Разрешённые типы вложений',
                'type' => 'string_list',
                'default' => [],
                'help' => 'Пусто — использовать платформенный allow-list.',
            ],
            'can_share' => [
                'label' => 'Публичные ссылки',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить создание ссылок общего доступа к заметкам.',
            ],
            'max_attachments_per_note' => [
                'label' => 'Вложений в одной заметке',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — использовать платформенный лимит.',
            ],
        ],
        'tasks' => [
            'max_personal_tasks' => [
                'label' => 'Количество личных задач',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — без дополнительного ограничения роли.',
            ],
            'can_create_shared_boards' => [
                'label' => 'Создание общих досок',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить создание досок для группы пользователей или всей системы.',
            ],
            'max_owned_boards' => [
                'label' => 'Количество собственных досок',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — без дополнительного ограничения роли.',
            ],
            'max_board_members' => [
                'label' => 'Участников на доске',
                'type' => 'int',
                'default' => 0,
                'help' => '0 — без дополнительного ограничения роли.',
            ],
            'can_assign_tasks' => [
                'label' => 'Назначение задач участникам',
                'type' => 'bool',
                'default' => true,
                'help' => 'Разрешить назначать задачи другим участникам общей доски.',
            ],
        ],
    ];

    private PermissionService $permissions;

    public function __construct(private ?DatabaseManager $db = null, ?PermissionService $permissions = null)
    {
        $this->db ??= DatabaseManager::getInstance();
        $this->permissions = $permissions ?? new PermissionService($this->db);
    }

    /** @return array<string,array<string,array<string,mixed>>> */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string,array<string,mixed>> */
    public function rolePolicies(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT module_id,policy_key,value_type,value_json FROM role_module_policies '
            . 'WHERE role_id = :role_id ORDER BY module_id,policy_key',
            [':role_id' => $roleId]
        );

        $policies = [];
        foreach ($rows as $row) {
            $module = (string) $row['module_id'];
            $key = (string) $row['policy_key'];
            if (!isset(self::DEFINITIONS[$module][$key])) {
                continue;
            }
            try {
                $policies[$module][$key] = $this->decodeValue((string) $row['value_type'], (string) $row['value_json']);
            } catch (Throwable) {
                // A malformed persisted value must not break the admin shell.
                // Enforcement falls back to the code-defined default below.
            }
        }
        return $policies;
    }

    /**
     * Resolve explicit policies from all assigned roles. Multiple roles compose
     * toward the broader capability: bool uses OR, positive integer caps use
     * the largest value, 0 means no role-specific cap, and string lists use a
     * union (an empty explicit list means the platform allow-list).
     *
     * @return array<string,array<string,mixed>>
     */
    public function effectivePoliciesForUser(int $userId): array
    {
        $effective = $this->defaults();
        if ($userId <= 0 || $this->permissions->hasRole($userId, 'superadmin')) {
            return $effective;
        }

        $rows = $this->db->fetchAll(
            'SELECT rmp.module_id,rmp.policy_key,rmp.value_type,rmp.value_json '
            . 'FROM user_roles ur JOIN role_module_policies rmp ON rmp.role_id = ur.role_id '
            . 'WHERE ur.user_id = :user_id ORDER BY rmp.module_id,rmp.policy_key,rmp.role_id',
            [':user_id' => $userId]
        );

        $explicit = [];
        foreach ($rows as $row) {
            $module = (string) $row['module_id'];
            $key = (string) $row['policy_key'];
            $definition = self::DEFINITIONS[$module][$key] ?? null;
            if ($definition === null || (string) $definition['type'] !== (string) $row['value_type']) {
                continue;
            }
            try {
                $explicit[$module][$key][] = $this->decodeValue((string) $row['value_type'], (string) $row['value_json']);
            } catch (Throwable) {
                continue;
            }
        }

        foreach ($explicit as $module => $modulePolicies) {
            foreach ($modulePolicies as $key => $values) {
                $type = (string) self::DEFINITIONS[$module][$key]['type'];
                $effective[$module][$key] = $this->mergeValues($type, $values);
            }
        }
        return $effective;
    }

    public function effectiveValue(int $userId, string $module, string $key): mixed
    {
        $this->definition($module, $key);
        return $this->effectivePoliciesForUser($userId)[$module][$key];
    }

    /**
     * Replace the complete explicit policy set for one role. Empty/inherit
     * values remove the override so the code-defined/platform default applies.
     *
     * @param array<string,mixed> $submitted nested [module][policy_key] values
     */
    public function replaceRolePolicies(int $actorId, int $roleId, array $submitted): void
    {
        $this->requireRoleManager($actorId);
        $role = $this->db->fetchOne('SELECT id,code FROM roles WHERE id = :id LIMIT 1', [':id' => $roleId]);
        if (!$role) {
            throw new DomainException('Роль не найдена', 404);
        }
        if ((string) $role['code'] === 'superadmin') {
            throw new DomainException('Суперадминистратор не ограничивается политиками модулей', 409);
        }

        $normalized = $this->normalizeSubmitted($submitted);
        $this->db->beginTransaction();
        try {
            $this->db->execute('DELETE FROM role_module_policies WHERE role_id = :role_id', [':role_id' => $roleId]);
            foreach ($normalized as $row) {
                $this->db->execute(
                    'INSERT INTO role_module_policies '
                    . '(role_id,module_id,policy_key,value_type,value_json,updated_by) '
                    . 'VALUES (:role_id,:module_id,:policy_key,:value_type,:value_json,:updated_by)',
                    [
                        ':role_id' => $roleId,
                        ':module_id' => $row['module_id'],
                        ':policy_key' => $row['policy_key'],
                        ':value_type' => $row['value_type'],
                        ':value_json' => $row['value_json'],
                        ':updated_by' => $actorId,
                    ]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function defaults(): array
    {
        $defaults = [];
        foreach (self::DEFINITIONS as $module => $definitions) {
            foreach ($definitions as $key => $definition) {
                $defaults[$module][$key] = $definition['default'];
            }
        }
        return $defaults;
    }

    /** @return array<string,mixed> */
    private function definition(string $module, string $key): array
    {
        $module = strtolower(trim($module));
        $key = strtolower(trim($key));
        $definition = self::DEFINITIONS[$module][$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Неизвестная политика модуля', 422);
        }
        return $definition;
    }

    /**
     * @param array<string,mixed> $submitted
     * @return list<array{module_id:string,policy_key:string,value_type:string,value_json:string}>
     */
    private function normalizeSubmitted(array $submitted): array
    {
        $rows = [];
        foreach ($submitted as $module => $moduleValues) {
            if (!is_string($module) || !is_array($moduleValues) || !isset(self::DEFINITIONS[$module])) {
                continue;
            }
            foreach ($moduleValues as $key => $raw) {
                if (!is_string($key) || !isset(self::DEFINITIONS[$module][$key])) {
                    continue;
                }
                if ($raw === null || $raw === '' || $raw === '__inherit__') {
                    continue;
                }

                $type = (string) self::DEFINITIONS[$module][$key]['type'];
                $value = match ($type) {
                    'bool' => $this->normalizeBool($raw),
                    'int' => $this->normalizeInt($raw),
                    'string_list' => $this->normalizeStringList($raw),
                    default => throw new InvalidArgumentException('Неподдерживаемый тип политики', 422),
                };

                try {
                    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException('Некорректное значение политики', 422, $e);
                }

                $rows[] = [
                    'module_id' => $module,
                    'policy_key' => $key,
                    'value_type' => $type,
                    'value_json' => $json,
                ];
            }
        }
        return $rows;
    }

    private function normalizeBool(mixed $raw): bool
    {
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }
        throw new InvalidArgumentException('Логическая политика должна быть Да/Нет', 422);
    }

    private function normalizeInt(mixed $raw): int
    {
        $value = is_int($raw) ? $raw : (ctype_digit((string) $raw) ? (int) $raw : -1);
        if ($value < 0 || $value > PHP_INT_MAX) {
            throw new InvalidArgumentException('Числовая политика должна быть неотрицательным целым числом', 422);
        }
        return $value;
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;]+/u', strtolower(trim((string) $raw))) ?: [];
        }

        $result = [];
        foreach ($parts as $part) {
            $value = strtolower(trim((string) $part));
            if ($value === '') {
                continue;
            }
            $value = ltrim($value, '.');
            if (preg_match('/^[a-z0-9][a-z0-9+._-]{0,31}$/', $value) !== 1) {
                throw new InvalidArgumentException('Недопустимое значение в списке типов файлов: ' . $value, 422);
            }
            $result[$value] = true;
            if (count($result) > 100) {
                throw new InvalidArgumentException('Слишком много значений в политике списка', 422);
            }
        }
        return array_keys($result);
    }

    private function decodeValue(string $type, string $json): mixed
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return match ($type) {
            'bool' => is_bool($value) ? $value : throw new InvalidArgumentException('Invalid bool policy'),
            'int' => is_int($value) && $value >= 0 ? $value : throw new InvalidArgumentException('Invalid int policy'),
            'string_list' => is_array($value) ? array_values(array_filter($value, 'is_string')) : throw new InvalidArgumentException('Invalid list policy'),
            default => throw new InvalidArgumentException('Invalid policy type'),
        };
    }

    /** @param list<mixed> $values */
    private function mergeValues(string $type, array $values): mixed
    {
        return match ($type) {
            'bool' => in_array(true, $values, true),
            'int' => in_array(0, $values, true) ? 0 : max(array_map('intval', $values)),
            'string_list' => $this->mergeStringLists($values),
            default => null,
        };
    }

    /** @param list<mixed> $values @return list<string> */
    private function mergeStringLists(array $values): array
    {
        foreach ($values as $value) {
            if (is_array($value) && $value === []) {
                return [];
            }
        }
        $merged = [];
        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [] as $item) {
                if (is_string($item)) {
                    $merged[$item] = true;
                }
            }
        }
        return array_keys($merged);
    }

    private function requireRoleManager(int $actorId): void
    {
        $this->permissions->requirePermission($actorId, 'admin.roles.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Управление ролями доступно только суперадминистратору', 403);
        }
    }
}
