<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/messenger/services/MessengerLongPollService.php';

use App\Services\MessengerLongPollService;

function failMessengerLongPollBoundary(string $message): never
{
    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

function assertMessengerLongPollBoundary(bool $condition, string $message): void
{
    if (!$condition) {
        failMessengerLongPollBoundary($message);
    }
}

/**
 * @param list<string> $fingerprints
 */
function runMessengerLongPollBoundary(array $fingerprints): array
{
    $time = 0.0;
    $index = 0;

    $service = new MessengerLongPollService(
        null,
        static function (int $userId) use (&$fingerprints, &$index): string {
            if ($userId !== 42) {
                throw new RuntimeException('Тест получил неожиданного пользователя');
            }
            $value = $fingerprints[$index] ?? end($fingerprints);
            $index++;
            return (string) $value;
        },
        static function () use (&$time): float {
            return $time;
        },
        static function (int $microseconds) use (&$time): void {
            // Переводим виртуальные часы сразу за минимальный timeout.
            // Реального sleep в регрессии нет.
            $time += max(5.0, $microseconds / 1_000_000);
        }
    );

    return $service->waitForChange(42, 'cursor-A');
}

$boundaryChange = runMessengerLongPollBoundary(['cursor-A', 'cursor-B']);
assertMessengerLongPollBoundary(
    $boundaryChange === ['cursor' => 'cursor-B', 'changed' => true],
    'изменение во время последней паузы должно вернуться как changed=true'
);

$plainTimeout = runMessengerLongPollBoundary(['cursor-A', 'cursor-A']);
assertMessengerLongPollBoundary(
    $plainTimeout === ['cursor' => 'cursor-A', 'changed' => false],
    'обычный timeout без изменений не должен создавать ложное событие'
);

fwrite(STDOUT, "[OK] Граница таймаута Messenger Long Poll не теряет изменение\n");
