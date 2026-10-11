<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateExternalCliSupport.php';
require_once $root . '/core/UpdateBootstrapSupport.php';

use Core\UpdateBootstrapSupport;
use Core\UpdateExternalCliSupport;

function externalUpdaterArchitectureAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, '[ОШИБКА] ' . $message . PHP_EOL);
    exit(1);
}

function externalUpdaterArchitectureSource(string $path): string
{
    $source = file_get_contents($path);
    externalUpdaterArchitectureAssert(is_string($source), 'Не удалось прочитать ' . $path);
    return $source;
}

$externalSource = externalUpdaterArchitectureSource($root . '/bin/update_external_apply.php');
$bootstrapSource = externalUpdaterArchitectureSource($root . '/bin/update_bootstrap.php');
$cliSupportSource = externalUpdaterArchitectureSource($root . '/core/UpdateExternalCliSupport.php');
$bootstrapSupportSource = externalUpdaterArchitectureSource($root . '/core/UpdateBootstrapSupport.php');

externalUpdaterArchitectureAssert(
    !str_contains($externalSource, 'CliRuntime')
        && !str_contains($bootstrapSource, 'CliRuntime')
        && !str_contains($cliSupportSource, 'CliRuntime')
        && !str_contains($bootstrapSupportSource, 'CliRuntime'),
    'Автономный updater не должен зависеть от live CliRuntime'
);

externalUpdaterArchitectureAssert(
    str_contains($externalSource, '/core/UpdateExternalCliSupport.php')
        && str_contains($bootstrapSource, '/core/UpdateExternalCliSupport.php')
        && str_contains($bootstrapSource, '/core/UpdateBootstrapSupport.php'),
    'Внешние точки входа не используют выделенные support-классы'
);

$cliReflection = new ReflectionClass(UpdateExternalCliSupport::class);
externalUpdaterArchitectureAssert($cliReflection->isFinal(), 'UpdateExternalCliSupport должен быть final');
foreach ([
    'assertCli',
    'jsonMode',
    'applicationRoot',
    'localFile',
    'configuredRoot',
    'transactionId',
    'writeJson',
    'fail',
] as $method) {
    externalUpdaterArchitectureAssert(
        $cliReflection->hasMethod($method),
        'В UpdateExternalCliSupport отсутствует метод ' . $method
    );
}

$bootstrapReflection = new ReflectionClass(UpdateBootstrapSupport::class);
externalUpdaterArchitectureAssert($bootstrapReflection->isFinal(), 'UpdateBootstrapSupport должен быть final');
foreach ([
    'database',
    'mutablePaths',
    'bindSourceVersion',
    'printResult',
    'releasePreLiveMaintenance',
] as $method) {
    externalUpdaterArchitectureAssert(
        $bootstrapReflection->hasMethod($method),
        'В UpdateBootstrapSupport отсутствует метод ' . $method
    );
}

foreach ([
    'function externalApplyFail',
    'function externalApplyNormalize',
    'function externalApplyInside',
    'function externalApplyEvent',
] as $legacyHelper) {
    externalUpdaterArchitectureAssert(
        !str_contains($externalSource, $legacyHelper),
        'В update_external_apply.php вернулся локальный helper: ' . $legacyHelper
    );
}

foreach ([
    'function bootstrapFail',
    'function bootstrapNormalize',
    'function bootstrapInside',
    'function bootstrapAppRoot',
    'function bootstrapLocalFile',
    'function bootstrapResolveRoot',
    'function bootstrapTransactionId',
    'function bootstrapDatabase',
    'function bootstrapMutablePaths',
    'function bootstrapPrint',
    'function bootstrapReleasePreLiveMaintenance',
] as $legacyHelper) {
    externalUpdaterArchitectureAssert(
        !str_contains($bootstrapSource, $legacyHelper),
        'В update_bootstrap.php вернулся локальный helper: ' . $legacyHelper
    );
}

externalUpdaterArchitectureAssert(
    !str_contains($bootstrapSource, '$runnerRoot . \'/core/Version.php\'')
        && !str_contains($bootstrapSource, 'require_once $runnerRoot . \'/core/Version.php\''),
    'Bootstrap не должен загружать Version из runner до привязки live-версии'
);
externalUpdaterArchitectureAssert(
    str_contains($bootstrapSupportSource, 'class_exists(Version::class, false)')
        && str_contains($bootstrapSupportSource, '$appRoot . \'/core/Version.php\'')
        && str_contains($bootstrapSupportSource, 'unsafe_version_binding'),
    'UpdateBootstrapSupport не защищает привязку Core\\Version к live-установке'
);

$orderedMarkers = [
    '$source = $bootstrap->bindSourceVersion(',
    '$verification = $verifier->verify(',
    '$staged = $stager->stage(',
    '$maintenance->enter(',
    '$journal->initialize(',
    '$backups = $backupManager->create(',
    '$stageVerification = $verifier->verify(',
    '$candidate = (new UpdateReleaseCandidate(',
    '$applyResult = (new UpdateApplyCommand(',
];
$previousPosition = -1;
foreach ($orderedMarkers as $marker) {
    $position = strpos($bootstrapSource, $marker);
    externalUpdaterArchitectureAssert(
        is_int($position),
        'В bootstrap-сценарии отсутствует этап: ' . $marker
    );
    externalUpdaterArchitectureAssert(
        $position > $previousPosition,
        'Нарушен порядок bootstrap-транзакции около этапа: ' . $marker
    );
    $previousPosition = $position;
}

externalUpdaterArchitectureAssert(
    str_contains($bootstrapSource, '$bootstrap->releasePreLiveMaintenance(')
        && str_contains($bootstrapSupportSource, "['live_mutation_started'] ?? true) !== false"),
    'Безопасное снятие maintenance до destructive-фазы не закреплено'
);

externalUpdaterArchitectureAssert(
    str_contains($externalSource, 'Внешний транзакционный обновлятор Workspace Organizer')
        && str_contains($bootstrapSource, 'Доверенный внешний bootstrap-обновлятор Workspace Organizer'),
    'Справка автономного updater должна оставаться русскоязычной'
);

echo "[OK] архитектура автономного updater: отдельный CLI-support, live Version binding и порядок bootstrap\n";
