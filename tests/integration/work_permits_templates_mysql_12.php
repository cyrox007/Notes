<?php

declare(strict_types=1);

require __DIR__ . '/work_permits_edit_crew_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitTemplateService.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitTemplateService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$templates = new WorkPermitTemplateService($db);

$job = $templates->create($userB, [
    'organization_id' => $org1['id'],
    'kind' => 'job',
    'name' => 'Проверка вторичных цепей',
    'work_description' => 'Проверить цепи РЗА по программе.',
]);
wpAssert($job['id'] > 0 && $job['version'] === 1, 'Создан шаблон задания');
$detail = $templates->detail($job['id'], $org1['id']);
wpAssert($detail['work_description'] === 'Проверить цепи РЗА по программе.',
    'Шаблон задания возвращает текст без изменения наряда');
wpAssert(count($templates->listing($org1['id'], 'job')['items']) === 1,
    'Список ограничен организацией и видом');
wpAssert($templates->listing($org2['id'], 'job')['items'] === [],
    'Чужая организация не видит шаблоны');
try {
    $templates->detail($job['id'], $org2['id']);
    throw new RuntimeException('Шаблон чужой организации доступен');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 404, 'Чужой шаблон скрыт');
}
wpAssert($drafts->detail($created['id'], $userA)['work_description'] !== $detail['work_description'],
    'Чтение шаблона не изменяет документ');
$crew = $templates->create($userB, [
    'organization_id' => $org1['id'],
    'kind' => 'crew',
    'name' => 'Релейная бригада',
    'roles' => ['issuer' => $person['id'], 'producer' => $person['id']],
    'member_ids' => [$person['id']],
]);
$crewDetail = $templates->detail($crew['id'], $org1['id']);
wpAssert((int) $crewDetail['roles']['issuer'] === $person['id']
    && (int) $crewDetail['roles']['producer'] === $person['id']
    && $crewDetail['member_ids'] === [$person['id']],
    'Сотрудник совмещает должности и членство в бригаде');
wpAssert(count($templates->listing($org1['id'], 'crew')['items']) === 1,
    'Список бригад отделён от списка заданий');
$renamed = $templates->change($userB, $job['id'], [
    'action' => 'rename', 'version' => 1, 'name' => 'РЗА и автоматика',
]);
wpAssert($renamed['version'] === 2, 'Переименование повышает версию');
try {
    $templates->change($userB, $job['id'], [
        'action' => 'rename', 'version' => 1, 'name' => 'Устаревшая версия',
    ]);
    throw new RuntimeException('Устаревшая версия перезаписала шаблон');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Конфликт версии приводит к 409');
}
$replace = $templates->change($userB, $job['id'], [
    'action' => 'replace', 'version' => 2, 'work_description' => 'Новое задание РЗА',
]);
wpAssert($replace['version'] === 3, 'Замена содержания повышает версию');
wpAssert($templates->detail($job['id'], $org1['id'])['work_description'] === 'Новое задание РЗА',
    'Содержание шаблона заменено атомарно');
$archive = $templates->change($userB, $job['id'], ['action' => 'archive', 'version' => 3]);
wpAssert($archive['version'] === 4, 'Архивация повышает версию');
wpAssert($templates->listing($org1['id'], 'job')['items'] === [],
    'Архивный шаблон не предлагается исполнителю');
try {
    $templates->detail($job['id'], $org1['id']);
    throw new RuntimeException('Архивный шаблон доступен без управляющего права');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 404, 'Архивный шаблон скрыт');
}
wpAssert($templates->detail($job['id'], $org1['id'], true)['name'] === 'РЗА и автоматика',
    'Администратор видит архив');
try {
    $templates->change($userB, $job['id'], [
        'action' => 'replace', 'version' => 4, 'work_description' => 'Запрещено',
    ]);
    throw new RuntimeException('Архивный шаблон перезаписан');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Перезапись архива запрещена');
}
$restored = $templates->change($userB, $job['id'], ['action' => 'restore', 'version' => 4]);
wpAssert($restored['version'] === 5, 'Восстановление повышает версию');
wpAssert(count($templates->listing($org1['id'], 'job')['items']) === 1,
    'Восстановленный шаблон снова доступен');

try {
    $templates->create($userB, [
        'organization_id' => $org2['id'],
        'kind' => 'crew',
        'name' => 'Чужая бригада',
        'roles' => ['issuer' => $person['id']],
        'member_ids' => [],
    ]);
    throw new RuntimeException('Работник чужой организации в шаблоне');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Чужой работник отклонён');
}
wpAssert((int) $db->fetchValue(
    'SELECT COUNT(*) FROM work_permit_template_history WHERE template_id=:id',
    [':id' => $job['id']]
) === 5, 'Создание, переименование, замена, архив и восстановление фиксируются в истории');
$provider = (string) file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
wpAssert(substr_count($provider, 'CSRFMiddleware::class') >= 6,
    'Создание и изменение шаблонов защищены от межсайтовых запросов');

DatabaseManager::resetInstance();
echo "Проверены задания, бригады, версии, архив и границы организации\n";
