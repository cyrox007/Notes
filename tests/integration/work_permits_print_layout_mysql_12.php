<?php

declare(strict_types=1);

// Проверяем весь исторический цикл, в том числе разрешения и инструктажи.
require __DIR__ . '/work_permits_safety_authorizations_mysql_12.php';

use Core\DatabaseManager;
use Modules\WorkPermits\Services\WorkPermitDraftEditService;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Modules\WorkPermits\Services\WorkPermitIssuanceService;

DatabaseManager::resetInstance();
$db = DatabaseManager::getInstance();
$drafts = new WorkPermitDraftService($db);
$editing = new WorkPermitDraftEditService($db);
$issuing = new WorkPermitIssuanceService($db);

// Старый наряд хранит именно неизвестное значение изоляции, а не выдуманное действие.
$legacy = $drafts->detail($doc, $userA);
wpAssert(($legacy['lifecycle']['measures'][0]['isolated_parts'] ?? null) === null,
    'Миграция не должна заполнять исторические сведения без оснований');
checkPdfWithPoppler($renderer->renderForm($legacy), [
    'ЛИЦЕВАЯ СТОРОНА', 'ОБОРОТНАЯ СТОРОНА',
    'Не указано в исходной записи', 'Целевые инструктажи',
    'Разрешено', 'Смирнов', 'КРУН-10', 'radio',
    'Передал', 'Получил', 'Провёл', 'Допускающий',
    'Производитель', 'При выдаче',
], 2);


// Проверяем самостоятельные пустые графы: учётные подтверждения не заполняют их.
$sample = tempnam(sys_get_temp_dir(), 'naryad-signatures-');
if (!is_string($sample)) throw new RuntimeException('Не удалось подготовить тест подписей');
try {
    file_put_contents($sample, $renderer->renderForm($legacy));
    $extracted = [];
    exec('pdftotext -layout -enc UTF-8 ' . escapeshellarg($sample)
        . ' - 2>&1', $extracted, $status);
    wpAssert($status === 0, 'Печатные графы должны читаться в PDF');
    $plain = implode("\n", $extracted);
    wpAssert(substr_count($plain, '__________') >= 12,
        'Участники разрешений, инструктажей и допусков имеют отдельные пустые графы');
} finally {
    @unlink($sample);
}

$new = $drafts->create($userA, [
    'kind' => 'permit', 'organization_id' => $org1['id'],
    'department_id' => $dept['id'], 'facility_id' => $facility['id'],
    'work_description' => 'Проверка выключателя и ограждения',
    'command_id' => sprintf('%032x', 41001),
]);
$editing->save($new['id'], $userA, [
    'version' => 1, 'command_id' => sprintf('%032x', 41002),
    'organization_id' => $org1['id'], 'department_id' => $dept['id'],
    'facility_id' => $facility['id'],
    'work_description' => 'Проверка выключателя и ограждения',
    'roles' => [
        'issuer' => $person['id'],
        'responsible_manager' => $person['id'],
        'producer' => $safetyProducer['id'],
        'admitter' => $person['id'],
    ],
    'member_ids' => [$person['id'], $safetyProducer['id']],
]);
$issue = [
    'version' => 2, 'command_id' => sprintf('%032x', 41003),
    'planned_start' => '2026-12-15 08:00:00',
    'planned_finish' => '2026-12-16 18:00:00',
    'special_instructions' => 'Подтвердить положение аппаратов перед началом.',
    'work_conditions' => 'Рабочее место подготовить до допуска бригады.',
    'measures' => [
        [
            'equipment' => 'КРУН-10 кВ, ячейка №4',
            'isolation' => 'Отключить выключатель, установить заземление.',
            'isolated_parts' => 'Оградить соседнюю ячейку и кабельный отсек.',
            'remaining_live' => 'Соседние ячейки остаются под напряжением.',
        ],
        [
            'equipment' => 'ОПУ, панель РЗА',
            'isolation' => 'Не требуется',
            'isolated_parts' => 'Не требуется',
            'remaining_live' => 'Не выполняются переключения в первичных цепях.',
        ],
    ],
];
wpAssert($issuing->issue($new['id'], $userB, $issue)['version'] === 3,
    'Сервер записывает выдачу с третьей графой мероприятий');
wpAssert($issuing->issue($new['id'], $userB, $issue)['replayed'] === true,
    'Повтор команды со всеми графами не создаёт второй наряд');
try {
    $issuing->issue($new['id'], $userB, [
        ...$issue,
        'measures' => [
            [...$issue['measures'][0], 'isolated_parts' => 'Совсем иное ограждение'],
            $issue['measures'][1],
        ],
    ]);
    throw new RuntimeException('Изменение изоляции той же команды должно быть запрещено');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409,
        'Различное содержимое третьей графы должно менять отпечаток команды');
}
$read = $drafts->detail($new['id'], $userA);
wpAssert(($read['lifecycle']['measures'][0]['isolated_parts'] ?? null)
    === 'Оградить соседнюю ячейку и кабельный отсек.',
    'Отдельная графа изоляции прочитана из базы без подмены оставшимся напряжением');
wpAssert(($read['lifecycle']['measures'][1]['isolated_parts'] ?? null) === 'Не требуется',
    'Случай без изоляции фиксируется явной записью');
wpAssert(($read['lifecycle']['measures'][0]['remaining_live'] ?? null)
    === 'Соседние ячейки остаются под напряжением.',
    'Ранее существовавшая графа осталась самостоятельной');

$printed = $renderer->renderForm($read);
checkPdfWithPoppler($printed, [
    'ЛИЦЕВАЯ СТОРОНА',
    'Мероприятия по подготовке рабочих мест',
    'Оградить', 'соседнюю', 'кабельный отсек',
    'изолировано',
    'ОБОРОТНАЯ СТОРОНА',
    'Подпись', 'Передал', 'Получил',
    'Подтвердить положение аппаратов',
], 2);
checkPdfWithPoppler($renderer->render($read), [
    'Подготовка рабочего места',
    'Соседние ячейки', 'под напряжением',
], 2);

// Оборотная сторона должна начинаться с отдельного листа, без подмены лицевой.
$file = tempnam(sys_get_temp_dir(), 'naryad-side-');
if (!is_string($file)) throw new RuntimeException('Не удалось подготовить временный PDF');
try {
    file_put_contents($file, $printed);
    $first = [];
    exec('pdftotext -f 1 -l 1 -enc UTF-8 ' . escapeshellarg($file)
        . ' - 2>&1', $first, $status);
    wpAssert($status === 0 && !str_contains(implode("\n", $first), 'ОБОРОТНАЯ СТОРОНА'),
        'Оборотная сторона не должна попадать на первый лист');
} finally {
    @unlink($file);
}

try {
    $renderer->renderForm(['kind' => 'order', 'state' => 'issued']);
    throw new RuntimeException('Распоряжение нельзя распечатывать как наряд');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Печать распоряжения под видом наряда запрещена');
}
try {
    $renderer->renderForm(['kind' => 'permit', 'state' => 'draft']);
    throw new RuntimeException('Черновик не должен выдаваться за оформленный наряд');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Печать черновика под видом наряда запрещена');
}
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
wpAssert(is_string($controller)
    && str_contains($controller, "get('layout', 'report')")
    && str_contains($controller, 'renderForm($document)')
    && str_contains($controller, 'render($document)')
    && str_contains($controller, 'detail($id, $actor, $canManage)'),
    'Печатный макет отдельно выбирается при сохранении доступа и старого PDF');
DatabaseManager::resetInstance();
echo "Печатный макет: три графы, снимки, старые команды, обе стороны и PDF проверены\n";
