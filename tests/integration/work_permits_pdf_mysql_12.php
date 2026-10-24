<?php

declare(strict_types=1);

// Наследуем полный жизненный цикл и контроль прав предшествующих этапов.
require __DIR__ . '/work_permits_completion_closure_mysql_12.php';
require_once $root . '/modules/work-permits/services/WorkPermitPdfFont.php';
require_once $root . '/modules/work-permits/services/WorkPermitPdfDocument.php';
require_once $root . '/modules/work-permits/services/WorkPermitPdfRenderer.php';

use Modules\WorkPermits\Services\WorkPermitPdfRenderer;

$renderer = new WorkPermitPdfRenderer();
$issued = $drafts->detail($filled['id'], $userA);
wpAssert($issued !== null && $issued['state'] === 'closed',
    'Контрольный наряд должен быть полностью закрыт');
$pdf = $renderer->render($issued);
wpAssert(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '/ToUnicode')
    && str_contains($pdf, '/FontFile2')
    && strlen($pdf) > 12_000,
    'PDF должен быть самостоятельным файлом со встроенным шрифтом и таблицей Unicode');
preg_match('/\/Type \/Pages \/Kids \[[^\]]+\] \/Count (\d+)/', $pdf, $pages);
wpAssert(isset($pages[1]) && (int) $pages[1] >= 2,
    'Журнал продлений и смен должен переноситься на несколько страниц');

function checkPdfWithPoppler(string $bytes, array $expected, int $minimumPages): void
{
    $file = tempnam(sys_get_temp_dir(), 'notes-naryad-');
    if ($file === false) throw new RuntimeException('Не удалось создать временный PDF в тесте');
    $pdfFile = $file . '.pdf';
    rename($file, $pdfFile);
    try {
        file_put_contents($pdfFile, $bytes);
        $escaped = escapeshellarg($pdfFile);
        exec('pdfinfo ' . $escaped . ' 2>&1', $info, $status);
        wpAssert($status === 0, 'Внешний анализатор должен распознавать PDF');
        $infoText = implode("\n", $info);
        wpAssert(preg_match('/Pages:\s*(\d+)/', $infoText, $result)
            && (int) $result[1] >= $minimumPages, 'Количество страниц некорректно');
        exec('pdftotext -layout -enc UTF-8 ' . $escaped . ' - 2>&1', $text, $status);
        wpAssert($status === 0, 'Текст PDF извлекается стандартным просмотрщиком');
        $plain = implode("\n", $text);
        foreach ($expected as $phrase) {
            wpAssert(str_contains($plain, $phrase),
                'В PDF пропал обязательный реквизит: ' . $phrase);
        }
        $preview = substr($pdfFile, 0, -4) . '-first';
        exec('pdftoppm -f 1 -l 1 -scale-to 1000 -singlefile -png '
            . $escaped . ' ' . escapeshellarg($preview) . ' 2>&1', $imageInfo, $imageStatus);
        wpAssert($imageStatus === 0 && is_file($preview . '.png')
            && filesize($preview . '.png') > 1000, 'Первая страница должна рисоваться без ошибки');
        unlink($preview . '.png');
    } finally {
        if (is_file($pdfFile)) unlink($pdfFile);
    }
}

checkPdfWithPoppler($pdf, [
    'НАРЯД-ДОПУСК', 'Организация', 'Подготовка рабочего места',
    'Продления наряда', 'Изменения состава бригады', 'целевой инструктаж',
    'Окончательное закрытие', 'Полное окончание работ',
], 2);

$boldyrevo = $issued;
$boldyrevo['facility'] = 'ПС 35 кВ «Болдыревка», КРУН-10 кВ, ячейка № 4';
$boldyrevo['work_description'] = 'Проверка цепей управления выключателя, РЗА и АУВ.';
$boldyrevo['lifecycle']['measures'] = [
    ['equipment' => 'КРУН-10 кВ, выключатель',
        'isolation' => 'Отключить выключатель, выполнить проверку отсутствия напряжения.',
        'remaining_live' => 'Соседние токоведущие части остаются под напряжением.'],
    ['equipment' => 'Трансформатор',
        'isolation' => 'Отключить и установить переносное заземление.',
        'remaining_live' => 'Смежное оборудование находится в работе.'],
    ['equipment' => 'Шинный разъединитель',
        'isolation' => 'Отключить, проверить, вывесить плакаты и оградить.',
        'remaining_live' => 'Остальные ячейки остаются под напряжением.'],
];
checkPdfWithPoppler($renderer->render($boldyrevo), [
    'Болдыревка', 'РЗА и АУВ', 'Трансформатор', 'Шинный разъединитель',
], 2);

$zhilzona = $issued;
$zhilzona['facility'] = 'ПС 110 кВ «Жилзона», ОПУ, панель автоматики АЧР';
$zhilzona['work_description'] = 'Восстановление автоматики частотной разгрузки, '
    . 'проверка цепей и регистрация испытаний.';
$zhilzona['lifecycle']['measures'] = [
    ['equipment' => 'ОПУ, панель АЧР',
        'isolation' => 'Первичное оборудование не выводится из работы; отключения не требуются.',
        'remaining_live' => 'Шины и первичное оборудование остаются под напряжением.'],
];
$zhilzona['lifecycle']['periods'] = array_merge(
    $zhilzona['lifecycle']['periods'],
    array_fill(0, 44, [
        'start_kind' => 'daily_admission',
        'started_at' => '2026-10-30 09:00:00',
        'admitter_name' => 'Иванов Иван Иванович',
        'instructor_name' => 'Петров Пётр Петрович',
        'recipient_name' => 'Иванов Иван Иванович',
        'ended_at' => '2026-10-30 18:00:00',
        'end_kind' => 'day_end',
        'end_note' => 'Проверка завершена, объект передан персоналу.',
    ])
);
checkPdfWithPoppler($renderer->render($zhilzona), [
    'Жилзона', 'АЧР', 'отключения не требуются', 'Ежедневные допуски',
    'Работы', 'Страница',
], 4);

try {
    $renderer->render(['id' => 42, 'kind' => 'order', 'state' => 'closed']);
    throw new RuntimeException('Распоряжение не должно иметь печатную форму наряда');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'PDF распоряжения запрещён');
}
try {
    $renderer->render(['id' => 43, 'kind' => 'permit', 'state' => 'draft']);
    throw new RuntimeException('Черновик не должен печататься как выданный наряд');
} catch (DomainException $error) {
    wpAssert($error->getCode() === 409, 'Черновик запрещено представлять выданным');
}
$controller = file_get_contents($root . '/modules/work-permits/controllers/WorkPermitController.php');
$routes = file_get_contents($root . '/modules/work-permits/WorkPermitsRuntimeProvider.php');
wpAssert(str_contains($routes, "'work_permits_pdf'"),
    'Маршрут PDF должен быть зарегистрирован для авторизованного пользователя');
wpAssert(str_contains($controller, "'work_permits.use'")
    && str_contains($controller, 'detail($id, $actor, $canManage)')
    && str_contains($controller, 'Cache-Control: private, no-store'),
    'PDF ограничен правами, авторством и запретом кэширования');

echo "PDF/A4: Кириллица, таблицы, переносы, контрольные случаи и права проверены\n";
