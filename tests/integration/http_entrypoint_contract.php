<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/ApplicationEntryPoint.php';

use Core\ApplicationEntryPoint;

function httpEntryPointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] контракт HTTP-точки входа: {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionClass(ApplicationEntryPoint::class);
httpEntryPointAssert($reflection->isFinal(), 'ApplicationEntryPoint должен оставаться final');

foreach (['bootstrap', 'dispatch'] as $methodName) {
    httpEntryPointAssert($reflection->hasMethod($methodName), "нет метода {$methodName}");
    $method = $reflection->getMethod($methodName);
    httpEntryPointAssert($method->isPublic() && $method->isStatic(), "{$methodName} должен быть public static");
}

$index = file_get_contents($root . '/index.php');
httpEntryPointAssert(is_string($index), 'не удалось прочитать index.php');

$recoveryPosition = strpos($index, 'UpdateBootRecoveryGate::enforce');
$entryPointPosition = strpos($index, "core/ApplicationEntryPoint.php");
httpEntryPointAssert(is_int($recoveryPosition), 'index.php потерял ранний recovery-барьер');
httpEntryPointAssert(is_int($entryPointPosition), 'index.php не подключает ApplicationEntryPoint');
httpEntryPointAssert(
    $recoveryPosition < $entryPointPosition,
    'ApplicationEntryPoint нельзя загружать до завершения recovery пофайлового обновления'
);

foreach ([
    'SchemaReadiness::inspect',
    'CrawlerDefense::handleEarlyRequest',
    "core/routerConfig.php",
    'ModuleRuntimeLoader::getInstance()->registerRoutes',
] as $forbiddenFragment) {
    httpEntryPointAssert(
        !str_contains($index, $forbiddenFragment),
        'обычная логика запуска должна быть вынесена из index.php: ' . $forbiddenFragment
    );
}

httpEntryPointAssert(
    str_contains($index, 'ApplicationEntryPoint::bootstrap')
        && str_contains($index, 'ApplicationEntryPoint::dispatch'),
    'index.php должен только координировать bootstrap и dispatch обычного запуска'
);

$entryPoint = file_get_contents($root . '/core/ApplicationEntryPoint.php');
httpEntryPointAssert(is_string($entryPoint), 'не удалось прочитать ApplicationEntryPoint.php');

foreach ([
    'CrawlerDefense::handleEarlyRequest',
    'SchemaReadiness::inspect',
    "'/core.php'",
    "'/core/routerConfig.php'",
    'ModuleRuntimeLoader::getInstance()->registerRoutes',
    'Router::getInstance()',
] as $requiredFragment) {
    httpEntryPointAssert(
        str_contains($entryPoint, $requiredFragment),
        'ApplicationEntryPoint потерял обычную границу запуска: ' . $requiredFragment
    );
}

foreach (['UpdateWebHttpBridge', 'UpdateBootRecoveryGate', 'MaintenanceModeService'] as $forbiddenFragment) {
    httpEntryPointAssert(
        !str_contains($entryPoint, $forbiddenFragment),
        'updater/maintenance нельзя переносить за позднюю границу ApplicationEntryPoint: ' . $forbiddenFragment
    );
}

$requestMatcher = $reflection->getMethod('isMessengerLongPollRequest');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/workspace/messenger/realtime/poll?cursor=x';
httpEntryPointAssert(
    $requestMatcher->invoke(null) === true,
    'long poll Messenger должен распознаваться после переноса ответа схемы'
);

$_SERVER['REQUEST_METHOD'] = 'POST';
httpEntryPointAssert(
    $requestMatcher->invoke(null) === false,
    'POST не должен считаться Messenger long poll'
);

fwrite(STDOUT, "[OK] HTTP-точка входа отделена от обычного запуска и recovery-барьера\n");
