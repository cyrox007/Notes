<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function usabilityAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Проверка удобства Notes 1.2: ' . $message);
    }
}

/** @return string */
function usabilitySource(string $path): string
{
    global $root;
    $source = file_get_contents($root . '/' . $path);
    usabilityAssert(is_string($source), 'не удалось прочитать ' . $path);
    return $source;
}

$controller = usabilitySource('core/Controller.php');
$sidebar = usabilitySource('app/views/^shared/sidebar/index.php');
$home = usabilitySource('app/views/main_page/index.php');
$summary = usabilitySource('app/services/WorkspaceHomeSummary.php');
$workPermits = usabilitySource('modules/work-permits/views/index.php');
$files = usabilitySource('modules/files/views/spaces.php');
$filesAdmin = usabilitySource('modules/files/views/admin-spaces.php');
$filesCss = usabilitySource('modules/files/assets/spaces-usability.css');
$profile = usabilitySource('modules/profile/views/publication.php');

usabilityAssert(str_contains($controller, "'work_permits' => \$active('workspace.work-permits')")
    && str_contains($controller, "'work_permits' => false")
    && str_contains($controller, "in_array('work_permits.use', \$permissions, true)"),
    'пункт нарядов должен зависеть от активного модуля и права use');
usabilityAssert(str_contains($sidebar, "route('work_permits')")
    && str_contains($sidebar, "if (!empty(\$access['work_permits']))")
    && str_contains($sidebar, 'aria-current="page"'),
    'доступ к нарядам в боковом меню должен использовать штатный маршрут и права');
usabilityAssert(str_contains($home, "route('work_permits')")
    && str_contains($home, "home_summary")
    && str_contains($home, 'Задач в работе'),
    'главная должна показывать разрешённые разделы и сводку');
usabilityAssert(str_contains($summary, 'ProfileContentProvider')
    && str_contains($summary, 'ModuleRuntimeLoader::isBooted()')
    && str_contains($summary, 'profileMetrics($userId)'),
    'главная должна собирать личные данные только через контракты активных модулей');
usabilityAssert(str_contains($profile, 'profile-metrics__attention')
    && str_contains($profile, 'open_tasks_count'),
    'профиль должен выделять задачи в работе');

$own = strpos($workPermits, '<h2 id="wp-registry-title"');
$management = strpos($workPermits, '<h2 id="wp-management-title"');
$create = strpos($workPermits, '<h2 id="wp-form-title"');
usabilityAssert($own !== false && $management !== false && $create !== false
    && $own < $management && $management < $create,
    'документы и журнал должны идти раньше формы создания');
$betweenManagementAndCreation = substr($workPermits, $management, $create - $management);
usabilityAssert(str_contains($betweenManagementAndCreation, '<?php endif; ?>'),
    'обычная форма создания должна находиться вне блока управления нарядами');

usabilityAssert(str_contains($workPermits, 'data-wp-form')
    && str_contains($workPermits, 'data-wp-management-list')
    && str_contains($workPermits, 'data-wp-list')
    && str_contains($workPermits, 'wp-journal__shortcuts'),
    'структура не должна терять действующие обработчики нарядов');

usabilityAssert(str_contains($files, 'file-spaces-page__tools')
    && str_contains($files, 'file-spaces-page__permissions')
    && str_contains($files, 'file-spaces-page__catalog')
    && str_contains($files, 'Сохранить правило')
    && !str_contains($files, 'подключаются следующим этапом'),
    'общие файлы должны компактно показывать каталог и действия без устаревшей заглушки');
usabilityAssert(str_contains($filesCss, '.file-spaces-page .file-manager__content{min-height:0}')
    && str_contains($filesCss, '.file-spaces-page__tools{display:grid'),
    'общие файлы не должны наследовать минимальную высоту каталога для форм');
usabilityAssert(str_contains($filesAdmin, 'files-admin-acl')
    && str_contains($filesAdmin, 'files-admin-check')
    && str_contains($filesAdmin, 'spaces-usability.css')
    && str_contains($files, 'spaces-usability.css'),
    'общее файловое пространство и админка должны иметь навигацию и общий стиль');

echo "Навигация нарядов, главная, профиль и компактные общие файлы: контракт подтверждён\n";
