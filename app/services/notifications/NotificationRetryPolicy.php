<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class NotificationRetryPolicy
{
    public function __construct(
        public int $baseDelaySeconds = 60,
        public int $maxDelaySeconds = 3600
    ) {
        if ($baseDelaySeconds < 1 || $baseDelaySeconds > 3600) {
            throw new InvalidArgumentException('Базовая задержка повторной доставки должна быть в диапазоне 1..3600 секунд');
        }
        if ($maxDelaySeconds < $baseDelaySeconds || $maxDelaySeconds > 86400) {
            throw new InvalidArgumentException('Максимальная задержка повторной доставки имеет недопустимое значение');
        }
    }

    public function delaySeconds(int $attemptNo): int
    {
        if ($attemptNo < 1) {
            throw new InvalidArgumentException('Номер попытки доставки должен быть положительным');
        }

        $power = min($attemptNo - 1, 20);
        $delay = $this->baseDelaySeconds * (2 ** $power);

        return (int) min($delay, $this->maxDelaySeconds);
    }

    public function nextAttemptAt(int $attemptNo, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $now->setTimezone(new DateTimeZone('UTC'));

        return $now->modify('+' . $this->delaySeconds($attemptNo) . ' seconds');
    }
}
