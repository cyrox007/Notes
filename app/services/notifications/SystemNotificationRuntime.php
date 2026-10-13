<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/SystemEventOutbox.php';
require_once dirname(__DIR__, 3) . '/core/Config.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/DatabaseNotificationStore.php';

use Core\DatabaseManager;
use Core\SystemEventOutbox;
use DateTimeImmutable;
use Throwable;

final class SystemNotificationRuntime
{
    public function __construct(private readonly SystemEventOutbox $outbox, private readonly DatabaseManager $db)
    {
    }

    public static function catchUp(string $appRoot, int $limit = 3): int
    {
        try {
            return (new self(SystemEventOutbox::forApplication($appRoot), DatabaseManager::getInstance()))->run($limit);
        } catch (Throwable) {
            // Generic only: no SQL, credentials, diagnostic paths or private payload.
            error_log('System notifications deferred until the next pass');
            return 0;
        }
    }

    public function run(int $limit = 20): int
    {
        $service = new NotificationService(new DatabaseNotificationStore($this->db));
        return $this->outbox->drain(function (array $event) use ($service): bool {
            $after = 0;
            do {
                // Keyset batches preserve all recipients without an unbounded list.
                $rows = $this->db->fetchAll(
                    "SELECT u.id FROM users u WHERE u.id > :after AND u.is_active=1 AND u.account_status='active'
                     AND u.deletion_requested_at IS NULL AND u.anonymized_at IS NULL
                     AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
                       WHERE ur.user_id=u.id AND (r.code='superadmin' OR EXISTS (
                         SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
                         WHERE rp.role_id=r.id AND p.code='admin.settings.manage')))
                     ORDER BY u.id LIMIT 100",
                    [':after' => $after]
                );
                if ($rows === [] && $after === 0) return false;
                if ($rows === []) break;
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
                $after = max($ids);
                [$title, $body, $importance] = self::presentation($event['name']);
                $service->publish(new NotificationEvent(
                    name: $event['name'], version: 1,
                    idempotencyKey: $event['name'] . ':' . $event['identity'],
                    source: 'core', category: str_starts_with($event['name'], 'update.') ? 'updates' : 'system',
                    importance: $importance, title: $title, body: $body,
                    recipientUserIds: $ids, targetPath: '/admin',
                    occurredAt: new DateTimeImmutable('@' . $event['at'])
                ));
            } while (count($rows) === 100);
            return true;
        }, $limit);
    }

    /** @return array{string,string,string} */
    private static function presentation(string $name): array
    {
        return match ($name) {
            'update.available' => ['Доступно обновление', 'Проверено совместимое подписанное обновление. Откройте раздел обновлений.', 'normal'],
            'update.installed' => ['Обновление установлено', 'Обновление завершено, новая версия прошла проверку состояния.', 'normal'],
            'update.rollback' => ['Выполнен откат обновления', 'Предыдущая рабочая версия восстановлена и проверена. Изучите журнал обновлений.', 'high'],
            'system.storage_low' => ['Мало места в хранилище', 'Свободное место в приватном хранилище ниже установленного порога. Проверьте состояние сервера.', 'high'],
            default => ['Проверка состояния выявила ошибку', 'Запустите healthcheck и изучите локальную диагностику сервера. Приватные сведения в уведомление не включены.', 'high'],
        };
    }
}
