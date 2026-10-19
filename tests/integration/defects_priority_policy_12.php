<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/defects/services/DefectPriorityPolicy.php';

use Modules\Defects\Services\DefectPriorityPolicy;

function assertDefectPolicy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'Нарушение политики дефектов: ' . $message . PHP_EOL);
        exit(1);
    }
}

$date = new DateTimeImmutable('2026-10-19 12:30:00', new DateTimeZone('Europe/Moscow'));
foreach ([
    DefectPriorityPolicy::URGENT => ['1', '2026-10-20'],
    DefectPriorityPolicy::FIRST => ['7', '2026-10-26'],
    DefectPriorityPolicy::IMPORTANT => ['14', '2026-11-02'],
    DefectPriorityPolicy::NORMAL => ['30', '2026-11-18'],
] as $priority => [$days, $expectedDate]) {
    assertDefectPolicy(DefectPriorityPolicy::days($priority) === (int) $days, 'неверное число суток');
    $deadline = DefectPriorityPolicy::deadline($date, $priority);
    assertDefectPolicy($deadline->format('Y-m-d') === $expectedDate, 'неверная плановая дата');
    assertDefectPolicy($deadline->getTimezone()->getName() === 'Europe/Moscow', 'изменён часовой пояс');
}

$rejected = false;
try {
    DefectPriorityPolicy::days('unknown');
} catch (InvalidArgumentException) {
    $rejected = true;
}
assertDefectPolicy($rejected, 'неизвестный приоритет должен отклоняться');
echo 'Политика приоритетов дефектов проверена' . PHP_EOL;
