<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once dirname(__DIR__, 3) . '/core/Environment.php';
require_once __DIR__ . '/NotificationSecretResolver.php';

use Core\Environment;
use InvalidArgumentException;
use RuntimeException;

final class EnvironmentNotificationSecretResolver implements NotificationSecretResolver
{
    public function resolve(?string $reference): ?string
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (preg_match('/^env:([A-Z][A-Z0-9_]{1,127})$/', $reference, $matches) !== 1) {
            throw new InvalidArgumentException(
                'Ссылка на секрет канала должна иметь формат env:ИМЯ_ПЕРЕМЕННОЙ'
            );
        }

        $name = $matches[1];
        $value = Environment::get($name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Переменная окружения для секрета канала не задана: ' . $name);
        }

        return $value;
    }
}
