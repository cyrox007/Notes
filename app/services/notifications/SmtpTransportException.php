<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use RuntimeException;

final class SmtpTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $resultCode,
        public readonly bool $retryable
    ) {
        parent::__construct($message);
    }
}
