<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/tasks/services/TaskReminderRepeatPolicy.php';

use Modules\Tasks\TaskReminderRepeatPolicy;

function repeatCheck(bool $ok, string $reason): void
{
    if (!$ok) {
        throw new RuntimeException('Политика напоминаний: ' . $reason);
    }
}

repeatCheck(TaskReminderRepeatPolicy::allowed() === [0, 1, 3, 7], 'некорректные варианты');
foreach ([0, 1, 3, 7] as $days) {
    repeatCheck(TaskReminderRepeatPolicy::validate($days) === $days, 'отклонён допустимый интервал');
}
try {
    TaskReminderRepeatPolicy::validate(2);
    throw new RuntimeException('Политика напоминаний: недопустимый интервал принят');
} catch (InvalidArgumentException) {
    // Неверная настройка должна быть отклонена.
}

$due = static fn(string $deadline, string $date, int $period): bool =>
    TaskReminderRepeatPolicy::due($deadline, $date, $period);

repeatCheck(!$due('2026-10-20 12:00:00', '2026-10-20', 1), 'уведомление в день срока');
repeatCheck($due('2026-10-20 12:00:00', '2026-10-21', 1), 'нет первого повтора');
repeatCheck(!$due('2026-10-20 12:00:00', '2026-10-21', 0), 'отключение игнорируется');
repeatCheck($due('2026-10-20 12:00:00', '2026-10-21', 3), 'нет первого повтора раз в три дня');
repeatCheck(!$due('2026-10-20 12:00:00', '2026-10-22', 3), 'лишний повтор на второй день');
repeatCheck(!$due('2026-10-20 12:00:00', '2026-10-23', 3), 'лишний повтор на третий день');
repeatCheck($due('2026-10-20 12:00:00', '2026-10-24', 3), 'нет повтора на четвёртый день');
repeatCheck($due('2026-10-20 12:00:00', '2026-10-28', 7), 'нет недельного повтора');
repeatCheck(!$due('2026-10-20 12:00:00', '2026-10-27', 7), 'ранний недельный повтор');
repeatCheck($due('2026-03-28 23:30:00', '2026-03-29', 1), 'потеря даты при весеннем переходе часов');
repeatCheck($due('2026-10-24 23:30:00', '2026-10-25', 1), 'потеря даты при осеннем переходе часов');
repeatCheck(!$due('2026-10-24 23:30:00', '2026-10-24', 1), 'повтор в день срока');
repeatCheck(!$due('не дата', '2026-10-25', 1), 'некорректный срок обработан как настоящий');

echo "Политика повторов: отключение, интервалы, даты и переход времени проверены\n";
