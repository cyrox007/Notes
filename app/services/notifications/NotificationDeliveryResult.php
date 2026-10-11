<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;

final readonly class NotificationDeliveryResult
{
    public function __construct(
        public bool $success,
        public string $code,
        public ?string $errorSummary = null,
        public bool $retryable = true
    ) {
        if (preg_match('/^[a-z0-9_.-]{2,80}$/', $code) !== 1) {
            throw new InvalidArgumentException('Код результата доставки имеет недопустимый формат');
        }

        if ($errorSummary !== null) {
            $length = function_exists('mb_strlen') ? mb_strlen($errorSummary) : strlen($errorSummary);
            if ($length > 500) {
                throw new InvalidArgumentException('Краткое описание ошибки доставки слишком длинное');
            }
        }
    }

    public static function sent(string $code = 'sent'): self
    {
        return new self(true, $code, null, false);
    }

    public static function failed(string $code, ?string $errorSummary = null, bool $retryable = true): self
    {
        return new self(false, $code, $errorSummary, $retryable);
    }
}
