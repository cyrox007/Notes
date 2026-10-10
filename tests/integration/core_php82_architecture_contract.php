<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function corePhp82ArchitectureAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[FAIL] архитектурный контракт Core PHP 8.2: {$message}\n");
    exit(1);
}

function corePhp82ArchitectureSource(string $root, string $relative): string
{
    $source = file_get_contents($root . '/' . $relative);
    corePhp82ArchitectureAssert(is_string($source), 'не удалось прочитать ' . $relative);
    return $source;
}

$requestSource = corePhp82ArchitectureSource($root, 'core/Request.php');
corePhp82ArchitectureAssert(
    str_contains($requestSource, 'public function session(string|int|null $key = null, mixed $default = null): mixed')
        && str_contains($requestSource, 'private function sanitize(mixed $data): mixed')
        && str_contains($requestSource, 'private function valueFrom('),
    'Request должен сохранять типизированный единый входной контракт'
);

$routerSource = corePhp82ArchitectureSource($root, 'core/Router.php');
foreach (['requestTarget', 'matchRoute', 'dispatchMatchedRoute', 'controllerFor', 'validControllerTuple', 'routePath'] as $method) {
    corePhp82ArchitectureAssert(
        str_contains($routerSource, 'function ' . $method . '('),
        'Router должен выделять этап ' . $method
    );
}
corePhp82ArchitectureAssert(
    !str_contains($routerSource, '$pathMatched'),
    'Router::dispatch не должен возвращаться к отдельному флагу совпадения пути'
);

$configSource = corePhp82ArchitectureSource($root, 'core/Config.php');
corePhp82ArchitectureAssert(
    str_contains($configSource, 'databaseConnectionFromEnvironment')
        && str_contains($configSource, 'siteUrlFromEnvironmentOrRequest')
        && str_contains($configSource, 'requestScheme'),
    'Config должен разделять чтение БД, SITEURL и схему запроса'
);

$databaseSource = corePhp82ArchitectureSource($root, 'core/DatabaseManager.php');
corePhp82ArchitectureAssert(
    str_contains($databaseSource, 'private function connectionOptions(')
        && str_contains($databaseSource, 'private function rollbackFailedCommit('),
    'DatabaseManager должен разделять параметры PDO и аварийный откат'
);
corePhp82ArchitectureAssert(
    substr_count($databaseSource, '#[\SensitiveParameter]') >= 8,
    'DatabaseManager должен защищать SQL-параметры и конфигурацию БД в трассировках'
);

$controllerSource = corePhp82ArchitectureSource($root, 'core/Controller.php');
foreach (['moduleViewRoots', 'basePath', 'commonViewData', 'mergeViewData', 'socketUrl', 'resolveWorkspaceAccess', 'emptyWorkspaceAccess'] as $method) {
    corePhp82ArchitectureAssert(
        str_contains($controllerSource, 'function ' . $method . '('),
        'Controller должен выделять ответственность ' . $method
    );
}
corePhp82ArchitectureAssert(
    str_contains($controllerSource, 'if ($output === \'\' || $output === false)')
        && str_contains($controllerSource, 'if (!is_array($data))'),
    'Controller должен использовать ранние выходы вместо пирамидальной вложенности'
);

fwrite(STDOUT, "[OK] архитектура Core для линии PHP 8.2 закреплена\n");
