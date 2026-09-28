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

$emptyActivity = hash('sha256', '');

$boundaryChange = runMessengerLongPollBoundary(['cursor-A', 'cursor-B']);
assertMessengerLongPollBoundary(
    ($boundaryChange['cursor'] ?? '') === 'cursor-B'
        && ($boundaryChange['changed'] ?? false) === true
        && ($boundaryChange['revision'] ?? -1) === 0
        && ($boundaryChange['activity_cursor'] ?? '') === $emptyActivity,
    'изменение во время последней паузы должно вернуться как changed=true с transport hints'
);

$plainTimeout = runMessengerLongPollBoundary(['cursor-A', 'cursor-A']);
assertMessengerLongPollBoundary(
    ($plainTimeout['cursor'] ?? '') === 'cursor-A'
        && ($plainTimeout['changed'] ?? true) === false
        && ($plainTimeout['revision'] ?? -1) === 0
        && ($plainTimeout['activity_cursor'] ?? '') === $emptyActivity,
    'обычный timeout без изменений не должен создавать ложное событие'
);

$time = 0.0;
$fingerprintCalls = 0;
$revisionCalls = 0;
$revisionSequence = [7, 7, 8];
$revisionService = new MessengerLongPollService(
    null,
    static function (int $userId) use (&$fingerprintCalls): string {
        $fingerprintCalls++;
        return 'cursor-B';
    },
    static function () use (&$time): float {
        return $time;
    },
    static function (int $microseconds) use (&$time): void {
        $time += 0.75;
    },
    static function () use (&$revisionCalls, &$revisionSequence): int {
        $value = $revisionSequence[$revisionCalls] ?? end($revisionSequence);
        $revisionCalls++;
        return (int) $value;
    },
    static fn (int $userId): string => hash('sha256', '')
);

$revisionChange = $revisionService->waitForChange(
    42,
    'cursor-A',
    null,
    7,
    $emptyActivity
);

assertMessengerLongPollBoundary(
    ($revisionChange['changed'] ?? false) === true
        && ($revisionChange['cursor'] ?? '') === 'cursor-B'
        && ($revisionChange['revision'] ?? 0) === 8,
    'изменение realtime revision не запустило немедленную полную сверку'
);
assertMessengerLongPollBoundary(
    $fingerprintCalls === 1,
    'полный fingerprint выполняется на каждом коротком тике вместо revision-сигнала'
);
assertMessengerLongPollBoundary(
    $revisionCalls === 3,
    'тест revision-сигнала не прошёл ожидаемое число дешёвых тиков'
);

fwrite(STDOUT, "[OK] Messenger Long Poll использует revision-сигнал и не теряет изменение на границе timeout\n");
