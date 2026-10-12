<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use Core\DatabaseManager;
use Core\ModuleCapabilityRegistry;
use Core\ModuleRuntimeLoader;
use Core\WorkspaceCalendarLinkTargetProvider;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class CalendarLinkService
{
    private const TARGETS = [
        'task' => ['capability' => 'workspace.tasks.calendar-links', 'label' => 'Задача'],
        'note' => ['capability' => 'workspace.notes.calendar-links', 'label' => 'Заметка'],
        'file' => ['capability' => 'workspace.files.calendar-links', 'label' => 'Файл'],
    ];

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?ModuleCapabilityRegistry $capabilities = null
    ) {
        $this->db ??= DatabaseManager::getInstance();
        if ($this->capabilities === null && ModuleRuntimeLoader::isBooted()) {
            $this->capabilities = ModuleRuntimeLoader::getInstance()->capabilities();
        }
    }

    /** @return array{type:string,label:string,available:bool,targets:list<array<string,mixed>>} */
    public function targetOptions(int $userId, string $type, string $query = '', int $limit = 20): array
    {
        $type = $this->targetType($type);
        $provider = $this->provider($type);
        if ($provider === null) {
            return [
                'type' => $type,
                'label' => self::TARGETS[$type]['label'],
                'available' => false,
                'targets' => [],
            ];
        }

        try {
            $targets = $provider->listCalendarLinkTargets($userId, $query, $limit);
        } catch (DomainException) {
            return [
                'type' => $type,
                'label' => self::TARGETS[$type]['label'],
                'available' => false,
                'targets' => [],
            ];
        }

        return [
            'type' => $type,
            'label' => self::TARGETS[$type]['label'],
            'available' => true,
            'targets' => $targets,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function linksForItem(int $userId, string $sourceType, string $sourceUid): array
    {
        $source = $this->source($userId, $sourceType, $sourceUid);
        $rows = $this->db->fetchAll(
            'SELECT target_type,target_uid FROM calendar_item_links '
            . 'WHERE owner_user_id = :user_id AND source_type = :source_type AND source_uid = :source_uid '
            . 'ORDER BY id ASC',
            [
                ':user_id' => $userId,
                ':source_type' => $source['type'],
                ':source_uid' => $source['uid'],
            ]
        );

        return array_values(array_map(fn (array $row): array => $this->resolveRow($userId, $row), $rows));
    }

    /** @param mixed $links @return list<array<string,mixed>> */
    public function replaceLinks(int $userId, string $sourceType, string $sourceUid, mixed $links): array
    {
        $source = $this->source($userId, $sourceType, $sourceUid);
        $normalized = $this->normalizeLinks($links);

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                'DELETE FROM calendar_item_links '
                . 'WHERE owner_user_id = :user_id AND source_type = :source_type AND source_uid = :source_uid',
                [
                    ':user_id' => $userId,
                    ':source_type' => $source['type'],
                    ':source_uid' => $source['uid'],
                ]
            );
            foreach ($normalized as $link) {
                $this->db->execute(
                    'INSERT INTO calendar_item_links '
                    . '(owner_user_id,source_type,source_uid,target_type,target_uid) '
                    . 'VALUES (:user_id,:source_type,:source_uid,:target_type,:target_uid)',
                    [
                        ':user_id' => $userId,
                        ':source_type' => $source['type'],
                        ':source_uid' => $source['uid'],
                        ':target_type' => $link['type'],
                        ':target_uid' => $link['uid'],
                    ]
                );
            }
            $this->db->endTransaction(true);
        } catch (Throwable $e) {
            $this->db->endTransaction(false);
            throw $e;
        }

        return $this->linksForItem($userId, $source['type'], $source['uid']);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function resolveRow(int $userId, array $row): array
    {
        $type = $this->targetType((string) ($row['target_type'] ?? ''));
        $uid = $this->targetUid((string) ($row['target_uid'] ?? ''));
        $base = [
            'type' => $type,
            'uid' => $uid,
            'label' => self::TARGETS[$type]['label'],
            'available' => false,
            'title' => 'Связанный объект недоступен',
            'subtitle' => '',
            'href' => null,
        ];

        $provider = $this->provider($type);
        if ($provider === null) {
            return $base;
        }
        try {
            $target = $provider->resolveCalendarLinkTarget($userId, $uid);
        } catch (Throwable) {
            return $base;
        }
        return [
            ...$base,
            'available' => true,
            'title' => (string) ($target['title'] ?? self::TARGETS[$type]['label']),
            'subtitle' => (string) ($target['subtitle'] ?? ''),
            'href' => (string) ($target['href'] ?? ''),
        ];
    }

    /** @return array{type:string,uid:string} */
    private function source(int $userId, string $type, string $uid): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Активная пользовательская сессия отсутствует');
        }
        $type = $this->sourceType($type);
        $uid = $this->sourceUid($uid);
        $table = $type === 'event' ? 'calendar_events' : 'calendar_time_blocks';
        $exists = $this->db->fetchValue(
            "SELECT id FROM {$table} WHERE uid = :uid AND owner_user_id = :user_id AND status = 'active' LIMIT 1",
            [':uid' => $uid, ':user_id' => $userId]
        );
        if ($exists === null) {
            throw new InvalidArgumentException('Объект Calendar не найден или недоступен');
        }
        return ['type' => $type, 'uid' => $uid];
    }

    /** @param mixed $links @return list<array{type:string,uid:string}> */
    private function normalizeLinks(mixed $links): array
    {
        if (!is_array($links) || !array_is_list($links) || count($links) > 20) {
            throw new InvalidArgumentException('Можно сохранить не более 20 связей Calendar');
        }
        $result = [];
        foreach ($links as $link) {
            if (!is_array($link) || array_is_list($link)) {
                throw new InvalidArgumentException('Каждая связь должна быть JSON-объектом');
            }
            foreach (array_keys($link) as $key) {
                if (!in_array($key, ['type', 'uid'], true)) {
                    throw new InvalidArgumentException('Связь Calendar содержит неизвестное поле');
                }
            }
            $type = $this->targetType((string) ($link['type'] ?? ''));
            $uid = $this->targetUid((string) ($link['uid'] ?? ''));
            $result[$type . ':' . $uid] = ['type' => $type, 'uid' => $uid];
        }
        return array_values($result);
    }

    private function provider(string $type): ?WorkspaceCalendarLinkTargetProvider
    {
        if ($this->capabilities === null) {
            return null;
        }
        $capability = self::TARGETS[$type]['capability'];
        if (!$this->capabilities->has($capability)) {
            return null;
        }
        $service = $this->capabilities->require($capability, WorkspaceCalendarLinkTargetProvider::class);
        return $service instanceof WorkspaceCalendarLinkTargetProvider ? $service : null;
    }

    private function sourceType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['event', 'time_block'], true)) {
            throw new InvalidArgumentException('Неизвестный тип объекта Calendar');
        }
        return $type;
    }

    private function targetType(string $type): string
    {
        $type = strtolower(trim($type));
        if (!isset(self::TARGETS[$type])) {
            throw new InvalidArgumentException('Неизвестный тип связанного объекта');
        }
        return $type;
    }

    private function sourceUid(string $uid): string
    {
        $uid = strtolower(trim($uid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $uid) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор объекта Calendar');
        }
        return $uid;
    }

    private function targetUid(string $uid): string
    {
        $uid = trim($uid);
        if ($uid === '' || strlen($uid) > 64 || preg_match('/[\x00-\x1F\x7F]/', $uid) === 1) {
            throw new InvalidArgumentException('Некорректный идентификатор связанного объекта');
        }
        return $uid;
    }
}
