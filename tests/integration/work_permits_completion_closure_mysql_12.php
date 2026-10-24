<?php

declare(strict_types=1);

// Проверяем всю цепочку от справочников и черновика до многодневных работ.
require __DIR__ . '/work_permits_crew_extensions_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitClosureService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitClosureService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$closure = new WorkPermitClosureService($db);
$id = $filled['id'];

wpAssert((int) $drafts->detail($id, $userA)['version'] === 12,
    'До завершения открыт третий рабочий день продлённого наряда');
$complete = [
    'version' => 12,
    'command_id' => sprintf('%032x', 12001),
    'completed_at' => '2026-11-01 16:10:00',
    'producer_employee_id' => $person['id'],
    'workplace_safe' => true,
    'crew_withdrawn' => true,
    'note' => 'Работы завершены и рабочее место приведено в безопасное состояние',
];

try {
    $closure->complete($id, $userB, $complete);
    throw new RuntimeException('Нельзя окончить работы при открытом периоде');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'При открытой смене завершение запрещено');
}
$stop = [
    'version' => 12,
    'command_id' => sprintf('%032x', 12002),
    'ended_at' => '2026-11-01 16:00:00',
    'note' => 'Окончание третьего рабочего дня',
    'work_stopped' => true,
];
wpAssert($daily->finish($id, $userB, 'day_end', $stop)['version'] === 13,
    'Закрыт последний рабочий период');
try {
    $closure->complete($id, $userB, [
        ...$complete, 'version' => 13, 'command_id' => sprintf('%032x', 12003),
        'crew_withdrawn' => false,
    ]);
    throw new RuntimeException('Нельзя завершить без вывода бригады');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Вывод бригады явно подтверждается');
}
try {
    $closure->complete($id, $userB, [
        ...$complete, 'version' => 13, 'command_id' => sprintf('%032x', 12004),
        'producer_employee_id' => $member['id'],
    ]);
    throw new RuntimeException('Нельзя подменить производителя работ');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Производитель совпадает с выданным нарядом');
}
try {
    $closure->complete($id, $userB, [
        ...$complete, 'version' => 13, 'command_id' => sprintf('%032x', 12005),
        'completed_at' => '2026-11-01 15:55:00',
    ]);
    throw new RuntimeException('Нельзя поставить окончание раньше закрытия смены');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Время окончания позже завершённого периода');
}
try {
    $closure->closePermit($id, $userB, [
        'version' => 13, 'command_id' => sprintf('%032x', 12006),
        'closed_at' => '2026-11-01 16:30:00',
        'closer_employee_id' => $person['id'],
        'workplace_accepted' => true,
        'documents_handed_over' => true,
        'note' => 'Закрыть без оформления окончания',
    ]);
    throw new RuntimeException('Закрытие без зарегистрированного окончания недопустимо');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Перед закрытием должен быть зарегистрирован конец работ');
}
$completed = $closure->complete($id, $userB, [
    ...$complete, 'version' => 13,
]);
wpAssert($completed['version'] === 14 && $completed['state'] === 'issued'
    && !$completed['replayed'], 'После окончания наряд ещё не закрыт');
wpAssert($closure->complete($id, $userB, [
    ...$complete, 'version' => 13,
])['replayed'], 'Повтор команды окончания не создаёт новое событие');
wpAssert($closure->details($id)['completion']['completed_at'] === '2026-11-01 16:10:00',
    'Факт окончания хранится отдельно');
try {
    $closure->complete($id, $userB, [
        ...$complete, 'version' => 13, 'note' => 'Подмена команды',
    ]);
    throw new RuntimeException('Нельзя повторно использовать команду с другим содержимым');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Подмена содержания команды возвращает 409');
}
try {
    $daily->start($id, $userB, 'daily_admission', [
        ...$thirdDay, 'version' => 14, 'command_id' => sprintf('%032x', 12007),
        'started_at' => '2026-11-02 08:00:00',
    ]);
    throw new RuntimeException('Нельзя допустить после полного окончания работ');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Новые допуски заблокированы после полного окончания');
}
try {
    $changes->extend($id, $userB, [
        ...$extension, 'version' => 14, 'command_id' => sprintf('%032x', 12008),
        'extended_at' => '2026-11-01 16:20:00', 'new_finish' => '2026-11-03 17:00:00',
    ]);
    throw new RuntimeException('Нельзя продлить после окончания работ');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Продление заблокировано после полного окончания');
}
try {
    $changes->changeCrew($id, $userB, [
        ...$crewUpdate, 'version' => 14, 'command_id' => sprintf('%032x', 12009),
        'changed_at' => '2026-11-01 16:25:00',
    ]);
    throw new RuntimeException('Нельзя менять бригаду после окончания работ');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Состав бригады заблокирован после окончания');
}
$close = [
    'version' => 14,
    'command_id' => sprintf('%032x', 12010),
    'closed_at' => '2026-11-01 16:30:00',
    'closer_employee_id' => $person['id'],
    'workplace_accepted' => true,
    'documents_handed_over' => true,
    'note' => 'Наряд оформлен и документация передана',
];
try {
    $closure->closePermit($id, $userB, [
        ...$close, 'workplace_accepted' => false,
    ]);
    throw new RuntimeException('Нельзя закрыть без проверки состояния места');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Приёмка рабочего места подтверждается отдельно');
}
try {
    $closure->closePermit($id, $userB, [
        ...$close, 'closer_employee_id' => $member['id'],
    ]);
    throw new RuntimeException('Нельзя закрыть наряд случайным участником');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Закрывающий должен быть выдавшим или руководителем');
}
try {
    $closure->closePermit($id, $userB, [
        ...$close, 'closed_at' => '2026-11-01 16:05:00',
    ]);
    throw new RuntimeException('Нельзя закрыть раньше фактического окончания');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Время закрытия не предшествует завершению');
}
$closed = $closure->closePermit($id, $userB, $close);
wpAssert($closed['version'] === 15 && $closed['state'] === 'closed'
    && !$closed['replayed'], 'Окончательное закрытие переводит наряд в архивное состояние');
wpAssert($closure->closePermit($id, $userB, $close)['replayed'],
    'Повтор закрытия не изменяет закрытый документ');
$detail = $drafts->detail($id, $userA);
wpAssert($detail['state'] === 'closed' && $detail['lifecycle']['closure']['closer_name'] !== '',
    'Автор видит историю и факт закрытия в своей карточке');
wpAssert($detail['lifecycle']['completion']['producer_employee_id'] === $person['id'],
    'История окончания доступна и после закрытия');
wpAssert(count($detail['lifecycle']['periods']) === 4
    && count($detail['lifecycle']['extensions']) === 1
    && count($detail['lifecycle']['crew_revisions']) === 1,
    'Закрытие не удалило допуски, продления и исторические составы');
$closedList = $drafts->managementList(20, 0, 'closed');
wpAssert(count(array_filter($closedList['items'], static fn (array $row): bool =>
    (int) $row['id'] === $id)) === 1,
    'Закрытый наряд доступен через фильтр закрытых');
wpAssert(count(array_filter($drafts->managementList(20, 0, 'issued')['items'],
    static fn (array $row): bool => (int) $row['id'] === $id)) === 0,
    'Закрытый наряд скрыт из списка выданных');
try {
    $closure->closePermit($id, $userB, [
        ...$close, 'version' => 15, 'command_id' => sprintf('%032x', 12011),
    ]);
    throw new RuntimeException('Нельзя закрывать документ дважды разными командами');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Второе независимое закрытие блокируется');
}
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_history WHERE document_id=:doc
     AND event_type IN ('complete','close_permit')", [':doc' => $id]
) === 2, 'Окончание и закрытие записаны в историю ровно по одному разу');

$orderClose = [
    'version' => 3,
    'command_id' => sprintf('%032x', 12012),
    'closed_at' => '2026-10-30 16:00:00',
    'closer_employee_id' => $person['id'],
    'workplace_accepted' => true,
    'documents_handed_over' => true,
    'note' => 'Работа по распоряжению окончена',
];
try {
    $closure->complete($order['id'], $userB, [
        ...$complete, 'version' => 3, 'command_id' => sprintf('%032x', 12013),
    ]);
    throw new RuntimeException('Распоряжение не должно использовать нарядный этап окончания');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Завершение предусмотрено только для наряда');
}
try {
    $closure->closeOrder($order['id'], $userB, [
        ...$orderClose, 'closer_employee_id' => $member['id'],
    ]);
    throw new RuntimeException('Распоряжение закрыл не производитель');
} catch (DomainException $e) {
    wpAssert($e->getCode() === 409, 'Распоряжение закрывает производитель');
}
wpAssert($closure->closeOrder($order['id'], $userB, $orderClose)['state'] === 'closed',
    'Распоряжение закрыто непосредственно из состояния issued');
wpAssert($closure->closeOrder($order['id'], $userB, $orderClose)['replayed'],
    'Повторное закрытие распоряжения безопасно');
wpAssert($closure->details($order['id'])['completion'] === null,
    'Распоряжение не создаёт выдуманный этап окончания наряда');

$routes = file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(str_contains($routes, 'work_permits_close_order')
    && str_contains($routes, 'work_permits_close_permit')
    && str_contains($routes, 'work_permits_complete')
    && substr_count($routes, 'CSRFMiddleware::class') >= 14,
    'Три перехода требуют CSRF');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 9,
    'Закрытие требует права управления');
DatabaseManager::resetInstance();
echo "Проверены окончание, закрытие, сохранность истории и блокировка повторов\n";
