<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('SITEPATH', $root);
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);

function defectsModuleAssert(bool $ok, string $message): void
{
    if ($ok) {
        return;
    }
    fwrite(STDERR, 'Контракт модуля Дефекты: ' . $message . PHP_EOL);
    exit(1);
}

$registry = \Core\ModuleRegistry::discover($root . '/modules', \Core\Version::VERSION);
defectsModuleAssert($registry->has('defects'), 'не зарегистрирован manifest');
defectsModuleAssert(!in_array('defects', $registry->defaultComposition(), true), 'новый модуль нельзя включать без оператора и лицензии');
$manifest = $registry->get('defects');
defectsModuleAssert($manifest->runtimeMode() === 'isolated', 'нужен изолированный runtime');
defectsModuleAssert($manifest->licenseFeature() === 'workspace.defects', 'отсутствует проверка лицензии');
$expectedTables = ['defect_areas', 'defect_object_categories', 'defect_objects',
    'defect_services', 'defects', 'defect_responsible_services'];
sort($expectedTables, SORT_STRING);
defectsModuleAssert($manifest->databaseTables() === $expectedTables, 'владение собственными таблицами изменено');
defectsModuleAssert(
    in_array('database/defects_schema.sql', $manifest->databaseSchemas(), true)
    && in_array('database/migrations/20261019_defects_foundation.sql', $manifest->databaseMigrations(), true),
    'новая установка и обновление должны содержать схему'
);
$ownership = \Core\DatabaseOwnership::fromPackageRoot($root);
defectsModuleAssert(
    in_array('database/migrations/20261019_defects_foundation.sql', $ownership->migrationFiles(), true),
    'платформа не видит миграцию Дефектов'
);

$provider = (static function (string $projectRoot): object {
    return require $projectRoot . '/modules/defects/runtime.php';
})($root);
defectsModuleAssert($provider instanceof \Core\ModuleRuntimeProvider, 'runtime должен возвращать интерфейс ядра');
defectsModuleAssert($provider->moduleId() === 'defects', 'неверный идентификатор поставщика');
defectsModuleAssert(array_keys($provider->capabilities()) === ['workspace.defects'], 'неверный экспорт возможностей');

$router = \Core\Router::getInstance();
$provider->registerRoutes($router);
$routes = $router->getRoutes();
$names = array_column($routes, 'name');
foreach ([
    'defects', 'defects_list', 'defects_create',
    'defects_references', 'defects_reference_create', 'defects_show', 'defects_close',
] as $name) {
    defectsModuleAssert(in_array($name, $names, true), 'не найден маршрут ' . $name);
}
$methods = [];
foreach ($routes as $route) {
    $methods[(string) ($route['name'] ?? '')] = $route['method'];
}
defectsModuleAssert($methods['defects_create'] === 'POST', 'создание должно использовать POST');
defectsModuleAssert($methods['defects_close'] === 'POST', 'закрытие должно использовать POST');

$api = (string) file_get_contents($root . '/modules/defects/controllers/DefectsApiController.php');
defectsModuleAssert(str_contains($api, '$request->rawJson()'), 'данные передаются через Request ядра');
defectsModuleAssert(str_contains($api, '$this->responseJson('), 'ответ строится через Controller ядра');
defectsModuleAssert(!str_contains($api, 'new PDO('), 'прямое соединение запрещено');

echo 'Автономный runtime Дефектов, схема, маршруты и HTTP-контракт проверены' . PHP_EOL;
