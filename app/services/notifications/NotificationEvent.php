<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class NotificationEvent
{
    public const IMPORTANCE_LOW = 'low';
    public const IMPORTANCE_NORMAL = 'normal';
    public const IMPORTANCE_HIGH = 'high';
    public const IMPORTANCE_CRITICAL = 'critical';

    /** @var list<int> */
    public array $recipientUserIds;
    public DateTimeImmutable $occurredAt;

    /**
     * @param list<int> $recipientUserIds
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $name,
        public int $version,
        public string $idempotencyKey,
        public string $source,
        public string $category,
        public string $importance,
        public string $title,
        public string $body,
        array $recipientUserIds,
        public array $payload = [],
        public ?string $targetPath = null,
        ?DateTimeImmutable $occurredAt = null
    ) {
        $this->assertValidName($name);
        $this->assertLength($idempotencyKey, 1, 190, 'Ключ идемпотентности');
        $this->assertLength($source, 1, 80, 'Источник события');
        $this->assertLength($category, 1, 80, 'Категория уведомления');
        $this->assertLength($title, 1, 190, 'Заголовок уведомления');
        $this->assertLength($body, 1, 4000, 'Текст уведомления');

        if ($version < 1 || $version > 65535) {
            throw new InvalidArgumentException('Версия события должна быть в диапазоне 1..65535');
        }

        if (!in_array($importance, self::importanceLevels(), true)) {
            throw new InvalidArgumentException('Неизвестный уровень важности уведомления');
        }

        if ($targetPath !== null && !$this->isSafeTargetPath($targetPath)) {
            throw new InvalidArgumentException('Ссылка уведомления должна быть внутренним путём приложения');
        }

        $this->recipientUserIds = $this->normalizeRecipients($recipientUserIds);
        $this->occurredAt = ($occurredAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public static function importanceLevels(): array
    {
        return [
            self::IMPORTANCE_LOW,
            self::IMPORTANCE_NORMAL,
            self::IMPORTANCE_HIGH,
            self::IMPORTANCE_CRITICAL,
        ];
    }

    public function occurredAtSql(): string
    {
        return $this->occurredAt->format('Y-m-d H:i:s');
    }

    private function assertValidName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,119}$/', $name) !== 1) {
            throw new InvalidArgumentException('Имя события имеет недопустимый формат');
        }
    }

    private function assertLength(string $value, int $minimum, int $maximum, string $field): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new InvalidArgumentException("{$field}: недопустимая длина");
        }
    }

    private function isSafeTargetPath(string $targetPath): bool
    {
        if ($targetPath === '' || !str_starts_with($targetPath, '/')) {
            return false;
        }

        if (str_starts_with($targetPath, '//')) {
            return false;
        }

        return !str_contains($targetPath, "\0");
    }

    /**
     * @param list<int> $recipientUserIds
     * @return list<int>
     */
    private function normalizeRecipients(array $recipientUserIds): array
    {
        $normalized = [];
        foreach ($recipientUserIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0) {
                throw new InvalidArgumentException('Получатель уведомления должен иметь положительный идентификатор');
            }
            $normalized[$userId] = $userId;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('У события должен быть хотя бы один получатель');
        }
        if (count($normalized) > 1000) {
            throw new InvalidArgumentException('За одну публикацию допускается не более 1000 получателей');
        }

        return array_values($normalized);
    }
}
