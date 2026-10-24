<?php

declare(strict_types=1);

// Полная последовательность: шаблоны, выдача, первый и ежедневные допуски.
require __DIR__ . '/work_permits_daily_periods_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitChangeService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitChangeService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$changes = new WorkPermitChangeService($db);
$documentId = $filled['id'];

wpAssert((int) $drafts->detail($documentId, $userA)['version'] === 9,
    'Исходный многодневный наряд завершил второй рабочий день');
$member = $refs->create('employees', [
    'name' => 'Смирнов Алексей Олегович',
    'organization_id' => $org1['id'],
    'department_id' => $dept['id'],
    'electrical_group' => 'IV',
], $userB);
$crewUpdate = [
    'version' => 9,
    'command_id' => sprintf('%032x', 11001),
    'changed_at' => '2026-10-31 16:10:00',
    'reason' => 'Замена члена бригады после окончания смены',
    'member_ids' => [$person['id'], $member['id']],
];

try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'member_ids' => [$otherEmployee['id']],
    ]);
    throw new RuntimeException('Нельзя назначить чужого сотрудника');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Чужой работник запрещён');
}
try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'member_ids' => [$secondEmployee['id']],
    ]);
    throw new RuntimeException('Нельзя назначить архивного сотрудника');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Архивный работник запрещён');
}
try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'member_ids' => [$person['id'], $person['id']],
    ]);
    throw new RuntimeException('Нельзя повторять сотрудника в составе');
} catch (InvalidArgumentException) {
    // Состав участников без дублирования.
}
$changed = $changes->changeCrew($documentId, $userB, $crewUpdate);
wpAssert($changed['version'] === 10 && !$changed['replayed'],
    'Зарегистрирована новая версия состава бригады');
wpAssert($changes->changeCrew($documentId, $userB, $crewUpdate)['replayed'],
    'Повтор изменения состава не переписывает историю');
$detail = $drafts->detail($documentId, $userA);
$crew = $detail['crew'];
wpAssert(count(array_filter($crew, static fn (array $r): bool => $r['role'] === 'member')) === 2,
    'В новом составе два члена бригады');
wpAssert(count(array_filter($crew, static fn (array $r): bool => $r['role'] === 'issuer')) === 1
    && count(array_filter($crew, static fn (array $r): bool => $r['role'] === 'producer')) === 1,
    'Выдавший и производитель работ сохранены без замены');
$history = $detail['lifecycle']['crew_revisions'];
wpAssert(count($history) === 1 && count($history[0]['members']) >= 4,
    'История хранит полный снимок ролей после изменения');
$oldName = 'Смирнов Алексей Олегович';
$refs->change('employees', $member['id'], 1, $userB, 'Смирнов А. О.');
wpAssert(count(array_filter($changes->details($documentId)['crew_revisions'][0]['members'],
    static fn (array $r): bool => $r['employee_name'] === $oldName)) === 1,
    'Историческое ФИО осталось прежним после переименования');
wpAssert($changes->changeCrew($documentId, $userB, $crewUpdate)['replayed'],
    'Переименование справочника не ломает повтор старой команды');
try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'reason' => 'Подмена повторной команды',
    ]);
    throw new RuntimeException('Подмена команды не должна проходить');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Повтор с изменённым содержанием запрещён');
}
try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'command_id' => sprintf('%032x', 11002),
    ]);
    throw new RuntimeException('Старой версией нельзя изменить бригаду');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Устаревшая версия состава запрещена');
}

$extension = [
    'version' => 10,
    'command_id' => sprintf('%032x', 11003),
    'extended_at' => '2026-10-31 16:20:00',
    'new_finish' => '2026-11-02 17:00:00',
    'reason' => 'Дополнительный объём проверок релейной защиты',
    'issuer_employee_id' => $person['id'],
];
try {
    $changes->extend($documentId, $userB, [
        ...$extension, 'issuer_employee_id' => $member['id'],
    ]);
    throw new RuntimeException('Посторонний сотрудник не может продлить наряд');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Выдавший должен совпадать с выдавшим исходный наряд');
}
try {
    $changes->extend($documentId, $userB, [
        ...$extension, 'new_finish' => '2026-10-31 16:30:00',
    ]);
    throw new RuntimeException('Нельзя сократить срок вместо продления');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Продление только увеличивает срок');
}
try {
    $changes->extend($documentId, $userB, [
        ...$extension, 'extended_at' => '2026-11-01 08:00:00',
    ]);
    throw new RuntimeException('Нельзя продлить после истечения прежнего срока');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Просроченное продление невозможно');
}
$extended = $changes->extend($documentId, $userB, $extension);
wpAssert($extended['version'] === 11 && !$extended['replayed'],
    'Продление увеличило версию документа');
wpAssert($changes->extend($documentId, $userB, $extension)['replayed'],
    'Повтор продления возвращает прежний результат');
$detail = $drafts->detail($documentId, $userA);
wpAssert($detail['lifecycle']['issuance']['planned_finish'] === '2026-11-02 17:00:00',
    'Текущий срок наряда действительно продлён');
$extensions = $detail['lifecycle']['extensions'];
wpAssert(count($extensions) === 1
    && $extensions[0]['previous_finish'] === '2026-10-31 17:00:00'
    && $extensions[0]['new_finish'] === '2026-11-02 17:00:00',
    'История хранит старый и новый срок');
try {
    $changes->extend($documentId, $userB, [
        ...$extension, 'version' => 11, 'command_id' => sprintf('%032x', 11004),
        'new_finish' => '2026-11-03 17:00:00',
        'extended_at' => '2026-11-03 09:00:00',
    ]);
    throw new RuntimeException('Нельзя регистрировать изменение задним числом после срока');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Продление после текущей даты окончания запрещено');
}

$thirdDay = [
    ...$nextDay,
    'version' => 11,
    'command_id' => sprintf('%032x', 11005),
    'started_at' => '2026-11-01 09:00:00',
];
$next = $daily->start($documentId, $userB, 'daily_admission', $thirdDay);
wpAssert($next['version'] === 12,
    'Продлённый наряд допускает новый рабочий день за исходным сроком');

try {
    $changes->changeCrew($documentId, $userB, [
        ...$crewUpdate, 'version' => 12,
        'command_id' => sprintf('%032x', 11006),
        'changed_at' => '2026-11-01 10:00:00',
    ]);
    throw new RuntimeException('Изменять бригаду при активных работах нельзя');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Смена бригады заблокирована при открытом периоде');
}
try {
    $changes->extend($documentId, $userB, [
        ...$extension, 'version' => 12,
        'command_id' => sprintf('%032x', 11007),
        'extended_at' => '2026-11-01 10:00:00',
        'new_finish' => '2026-11-03 17:00:00',
    ]);
    throw new RuntimeException('Продление при незавершённом периоде запрещено');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Продление заблокировано при активных работах');
}
wpAssert(count($changes->details($documentId)['extensions']) === 1,
    'Отклонённые действия не добавили историю');
wpAssert((int) $db->fetchValue(
    "SELECT COUNT(*) FROM work_permit_history
     WHERE document_id=:doc AND event_type IN ('crew_change','extension')",
    [':doc' => $documentId]
) === 2, 'Две успешные операции создали ровно два события истории');

try {
    $changes->extend($order['id'], $userB, [
        ...$extension, 'version' => 3,
        'command_id' => sprintf('%032x', 11008),
        'extended_at' => '2026-10-30 16:30:00',
    ]);
    throw new RuntimeException('Распоряжения нельзя продлевать');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Операции продления недоступны для распоряжений');
}

$routes = file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(str_contains($routes, 'work_permits_extend')
    && str_contains($routes, 'work_permits_change_crew')
    && substr_count($routes, 'CSRFMiddleware::class') >= 11,
    'Обе новые операции требуют CSRF');
wpAssert(substr_count($controller, "'work_permits.manage'") >= 8,
    'Управление требует отдельного разрешения');
DatabaseManager::resetInstance();
echo "Проверены изменения бригады, продление и история прежних сроков\n";
