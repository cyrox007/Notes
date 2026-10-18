<?php

declare(strict_types=1);

// Сценарии исполняются в уже созданной изолированной MySQL базе Filespaces.
require_once $root . '/modules/files/services/FileSpaceAdminService.php';

use App\Services\Files\FileSpaceAdminService;

$adminFiles = new FileSpaceAdminService($pdo, $access);
$adminSnapshot = $adminFiles->snapshot($admin);
filespaceCheck((int) $adminSnapshot['retention_days'] === 30,
    'при установке политика корзины должна иметь безопасный срок по умолчанию');
$limitedDenied = false;
try {
    $adminFiles->snapshot($member);
} catch (\DomainException $e) {
    $limitedDenied = $e->getCode() === 403;
}
filespaceCheck($limitedDenied, 'обычному пользователю запрещено администрирование Files');

$createdGroup = $adminFiles->createGroup($admin, 'Бухгалтерия', 'fsp-admin-group-001');
$groupId = (int) ($createdGroup['group_id'] ?? 0);
filespaceCheck($groupId > 0 && (int) $createdGroup['version'] === 1,
    'администратор создал файловую группу');
$replayGroup = $adminFiles->createGroup($admin, 'Бухгалтерия', 'fsp-admin-group-001');
filespaceCheck($replayGroup['replayed'] && (int) $replayGroup['group_id'] === $groupId,
    'повтор создания группы не создаёт вторую');
$groupCount = (int) $pdo->query('SELECT COUNT(*) FROM file_access_groups')->fetchColumn();
filespaceCheck($groupCount === 1, 'в таблице нет дублирующих групп');

$added = $adminFiles->changeGroup(
    $admin, $groupId, 'add', $member, 1, 'fsp-admin-member-001'
);
filespaceCheck($added['success'] && $added['version'] === 2,
    'пользователь добавлен в группу с версией 2');
$replayAdd = $adminFiles->changeGroup(
    $admin, $groupId, 'add', $member, 1, 'fsp-admin-member-001'
);
filespaceCheck($replayAdd['replayed'] && $replayAdd['version'] === 2,
    'повтор добавления пользователя не повышает версию');

$stale = $adminFiles->changeGroup(
    $admin, $groupId, 'add', $reader, 1, 'fsp-admin-member-stale'
);
filespaceCheck(($stale['error'] ?? '') === 'version_conflict'
    && $stale['current_version'] === 2,
    'две вкладки администрации не перезаписывают состав группы');

$globalVersion = (int) $pdo->query("SELECT version FROM file_spaces WHERE uid='global'")->fetchColumn();
$groupRule = $folders->setRule(
    $admin, null, 'group', $groupId, 'history', 'allow',
    $globalVersion, 'fsp-admin-group-rule-001'
);
filespaceCheck($groupRule['success'], 'группа назначена как получатель права');
filespaceCheck($access->decision($member, 'global', null, 'history')['allowed'],
    'член группы наследует разрешение history');
filespaceCheck(!$access->decision($reader, 'global', null, 'history')['allowed'],
    'посторонний пользователь не получает разрешение группы');

$explanation = $access->explain($admin, $member);
$history = array_values(array_filter(
    $explanation, static fn (array $entry): bool => $entry['permission'] === 'history'
));
filespaceCheck(count($history) === 1 && $history[0]['allowed']
    && $history[0]['source'] === 'space',
    'диагностика показывает реально применённое правило и его источник');
$noDiagnostic = false;
try {
    $access->explain($reader, $member);
} catch (\DomainException $error) {
    $noDiagnostic = $error->getCode() === 403;
}
filespaceCheck($noDiagnostic, 'диагностика чужих прав закрыта для обычного пользователя');

$removed = $adminFiles->changeGroup(
    $admin, $groupId, 'remove', $member, 2, 'fsp-admin-member-remove'
);
filespaceCheck($removed['version'] === 3, 'удаление участника повышает версию группы');
filespaceCheck(!$access->decision($member, 'global', null, 'history')['allowed'],
    'права отозваны сразу после исключения из группы');
$removeAgain = $adminFiles->changeGroup(
    $admin, $groupId, 'remove', $member, 2, 'fsp-admin-member-remove'
);
filespaceCheck($removeAgain['replayed'], 'повтор исключения участника безопасен');

$retention = $adminFiles->setRetention($admin, 45, 1, 'fsp-admin-retention-001');
filespaceCheck($retention['version'] === 2, 'срок удержания корзины изменён на 45 дней');
$retentionAgain = $adminFiles->setRetention($admin, 45, 1, 'fsp-admin-retention-001');
filespaceCheck($retentionAgain['replayed'], 'повтор настройки срока удержания не дублируется');
$retentionStale = $adminFiles->setRetention($admin, 60, 1, 'fsp-admin-retention-stale');
filespaceCheck(($retentionStale['error'] ?? '') === 'version_conflict',
    'устаревшая настройка корзины возвращает конфликт');
filespaceCheck($adminFiles->snapshot($admin)['retention_days'] === 45,
    'конфликт не перезаписал актуальную настройку');

$deleted = $adminFiles->changeGroup(
    $admin, $groupId, 'delete', 0, 3, 'fsp-admin-group-delete'
);
filespaceCheck($deleted['deleted'], 'группа удалена администратором');
$ruleCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_acl WHERE subject_type='group' AND subject_id=" . $groupId
)->fetchColumn();
filespaceCheck($ruleCount === 0, 'при удалении группы удалены оставшиеся правила ACL');

$audits = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code LIKE 'admin.%'"
)->fetchColumn();
filespaceCheck($audits >= 5, 'изменения групп и политики попали в аудит');

echo "Контракт администрирования общих файлов 1.2 выполнен\n";
