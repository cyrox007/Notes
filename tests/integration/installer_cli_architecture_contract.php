<?php

declare(strict_types=1);

function installerCliAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);

$installer = file_get_contents($root . '/install.php');
installerCliAssert(is_string($installer), 'Не удалось прочитать install.php');
installerCliAssert(
    str_contains($installer, "require_once __DIR__ . '/core/InstallerDatabaseService.php';"),
    'Установщик должен явно подключать сервис БД'
);
installerCliAssert(
    str_contains($installer, "require_once __DIR__ . '/core/InstallerEnvironmentService.php';"),
    'Установщик должен явно подключать сервис окружения'
);
installerCliAssert(
    str_contains($installer, 'new \\Core\\InstallerDatabaseService()'),
    'Установщик должен создавать InstallerDatabaseService'
);
installerCliAssert(
    str_contains($installer, 'new \\Core\\InstallerEnvironmentService()'),
    'Установщик должен создавать InstallerEnvironmentService'
);

$legacyInstallerFunctions = [
    'connectDatabase',
    'connectOrCreateDatabase',
    'assertDatabaseSchemaPrivileges',
    'importSchemas',
    'createAdminUser',
    'randomSecret',
    'installerFunctionAvailable',
    'installerLongPollTimeoutSeconds',
    'installerIniBytes',
    'installerUploadTempWritable',
    'installerOptionalCapabilities',
    'privateStorageCandidate',
    'preparePrivateStorage',
    'assertPrivateStorageFilesystemContract',
    'prepareRuntimeDirectories',
    'envQuoted',
    'writeEnvironmentFile',
    'installerRequirements',
];
foreach ($legacyInstallerFunctions as $legacyFunction) {
    installerCliAssert(
        !str_contains($installer, 'function ' . $legacyFunction . '('),
        'Инфраструктурная логика не должна возвращаться в install.php: ' . $legacyFunction
    );
}

require_once $root . '/core/HostingCompatibility.php';
require_once $root . '/core/PrivateStorageResolver.php';
require_once $root . '/core/InstallerDatabaseService.php';
require_once $root . '/core/InstallerEnvironmentService.php';

$installerDatabase = new ReflectionClass(\Core\InstallerDatabaseService::class);
installerCliAssert($installerDatabase->isFinal(), 'InstallerDatabaseService должен оставаться final');
foreach (['connect', 'connectOrCreate', 'assertServerCompatibility', 'assertSchemaPrivileges', 'existingTables', 'importSchemas', 'createAdminUser'] as $method) {
    installerCliAssert(
        $installerDatabase->hasMethod($method),
        'InstallerDatabaseService должен содержать метод ' . $method
    );
}

$installerEnvironment = new ReflectionClass(\Core\InstallerEnvironmentService::class);
installerCliAssert($installerEnvironment->isFinal(), 'InstallerEnvironmentService должен оставаться final');
foreach ([
    'randomSecret',
    'functionAvailable',
    'longPollTimeoutSeconds',
    'iniBytes',
    'uploadTempWritable',
    'optionalCapabilities',
    'privateStorageCandidate',
    'preparePrivateStorage',
    'prepareRuntimeDirectories',
    'requirements',
    'writeEnvironmentFile',
] as $method) {
    installerCliAssert(
        $installerEnvironment->hasMethod($method),
        'InstallerEnvironmentService должен содержать метод ' . $method
    );
}

require_once $root . '/core/CliRuntime.php';
$cliRuntime = new ReflectionClass(\Core\CliRuntime::class);
installerCliAssert($cliRuntime->isFinal(), 'CliRuntime должен оставаться final');
foreach (['projectRoot', 'assertCli', 'assertCliHidden', 'loadEnvironment', 'registerAutoloader', 'writeJson', 'fail'] as $method) {
    installerCliAssert($cliRuntime->hasMethod($method), 'CliRuntime должен содержать метод ' . $method);
}

$cliFiles = [
    'bin/maintenance.php',
    'bin/audit_log.php',
    'bin/observability.php',
    'bin/retention.php',
    'bin/cleanup_messenger_orphans.php',
    'bin/healthcheck.php',
    'bin/control.php',
    'bin/migrate.php',
    'bin/migrate_crypto.php',
    'bin/reconcile_file_storage.php',
    'bin/repair_phpmyadmin_dump.php',
    'bin/rotate_data_keys.php',
    'bin/ws_doctor.php',
    'bin/release_acceptance.php',
    'bin/update_doctor.php',
    'bin/update_activate.php',
    'bin/update.php',
    'bin/update_remote.php',
    'bin/update_apply.php',
];
foreach ($cliFiles as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    installerCliAssert(is_string($source), 'Не удалось прочитать ' . $relative);
    installerCliAssert(
        str_contains($source, 'CliRuntime'),
        $relative . ' должен использовать общий CliRuntime'
    );
    installerCliAssert(
        !str_contains($source, 'This command is CLI-only.'),
        $relative . ' не должен возвращать старое англоязычное сообщение CLI'
    );
}

$migrate = file_get_contents($root . '/bin/migrate.php');
installerCliAssert(is_string($migrate), 'Не удалось прочитать bin/migrate.php');
foreach ([
    '--baseline-version-code',
    'MigrationBaseline::activeUpdaterSourceVersionCode(',
    'SET FOREIGN_KEY_CHECKS=0',
    'SET FOREIGN_KEY_CHECKS=1',
    "'PENDING' : 'APPLIED'",
] as $compatibilityMarker) {
    installerCliAssert(
        str_contains($migrate, $compatibilityMarker),
        'Миграции должны сохранять совместимость старого обновлятора: ' . $compatibilityMarker
    );
}
installerCliAssert(
    !str_contains($migrate, 'Missing environment variable:')
        && !str_contains($migrate, 'Migration failed:'),
    'Операторские сообщения миграций должны оставаться русскоязычными'
);

$rotation = file_get_contents($root . '/bin/rotate_data_keys.php');
installerCliAssert(is_string($rotation), 'Не удалось прочитать bin/rotate_data_keys.php');
installerCliAssert(
    str_contains($rotation, 'CliRuntime::fail(') && str_contains($rotation, 'CliRuntime::writeJson('),
    'Ротация ключей должна использовать единый JSON/error-контур CLI'
);

$releaseAcceptance = file_get_contents($root . '/bin/release_acceptance.php');
installerCliAssert(is_string($releaseAcceptance), 'Не удалось прочитать bin/release_acceptance.php');
installerCliAssert(
    str_contains($releaseAcceptance, 'CliRuntime::assertCli();')
        && !str_contains($releaseAcceptance, 'CliRuntime::loadEnvironment('),
    'Приёмка релиза не должна зависеть от .env приложения'
);

foreach (['bin/update_doctor.php', 'bin/update_activate.php'] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    installerCliAssert(is_string($source), 'Не удалось прочитать ' . $relative);
    installerCliAssert(
        str_contains($source, 'CliRuntime::assertCliHidden();'),
        $relative . ' должен скрывать web-вызов кодом 404'
    );
}

$updateActivate = file_get_contents($root . '/bin/update_activate.php');
installerCliAssert(is_string($updateActivate), 'Не удалось прочитать bin/update_activate.php');
installerCliAssert(
    str_contains($updateActivate, '@fopen($destination, \'xb\')')
        && !str_contains($updateActivate, '@fopen($destination, \'wb\')'),
    'Активация updater не должна перезаписывать существующий файл credentials'
);

foreach (['bin/update.php', 'bin/update_remote.php'] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    installerCliAssert(is_string($source), 'Не удалось прочитать ' . $relative);
    installerCliAssert(
        !str_contains($source, 'MaintenanceModeService')
            && !str_contains($source, 'UpdateLiveApplier')
            && !str_contains($source, 'UpdateApplyCommand'),
        $relative . ' должен оставаться предтранзакционным слоем без maintenance/live apply'
    );
    installerCliAssert(
        str_contains($source, "'status' => 'fail'")
            && str_contains($source, '\'code\' => $code')
            && str_contains($source, '\'message\' => $message'),
        $relative . ' должен сохранять совместимый JSON-контракт ошибки updater'
    );
}

$updatePreflight = file_get_contents($root . '/bin/update.php');
installerCliAssert(is_string($updatePreflight), 'Не удалось прочитать bin/update.php');
installerCliAssert(
    str_contains($updatePreflight, 'UpdateManifestVerifier')
        && str_contains($updatePreflight, 'UpdatePackageStager')
        && str_contains($updatePreflight, 'UpdateArchiveInspector'),
    'Локальная предвалидация updater должна сохранять подпись, staging и аудит ZIP'
);

$updateRemote = file_get_contents($root . '/bin/update_remote.php');
installerCliAssert(is_string($updateRemote), 'Не удалось прочитать bin/update_remote.php');
installerCliAssert(
    str_contains($updateRemote, 'UpdateCredentialRefreshingTransport')
        && str_contains($updateRemote, 'ensureUpdateAccess()')
        && str_contains($updateRemote, 'refreshUpdateAccess()'),
    'Удалённая проверка updater должна сохранять автоматическое восстановление credentials'
);

$updateApply = file_get_contents($root . '/bin/update_apply.php');
installerCliAssert(is_string($updateApply), 'Не удалось прочитать bin/update_apply.php');
installerCliAssert(
    str_contains($updateApply, 'new UpdateApplyCommand($root, $json)')
        && str_contains($updateApply, 'UpdateApplyException')
        && str_contains($updateApply, 'SecurityEventLog::emit('),
    'Транзакционный apply должен сохранять единый UpdateApplyCommand и журнал безопасности'
);
installerCliAssert(
    str_contains($updateApply, "'code' => $e->errorCode")
        && str_contains($updateApply, "'code' => 'apply_failed'"),
    'Транзакционный apply должен сохранять машинные коды ошибок'
);

foreach (['bin/update_external_apply.php', 'bin/update_bootstrap.php'] as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    installerCliAssert(is_string($source), 'Не удалось прочитать ' . $relative);
    installerCliAssert(
        !str_contains($source, 'CliRuntime'),
        $relative . ' должен оставаться самодостаточным внешним runner без зависимости от live CliRuntime'
    );
}

$healthcheck = file_get_contents($root . '/bin/healthcheck.php');
installerCliAssert(is_string($healthcheck), 'Не удалось прочитать bin/healthcheck.php');
installerCliAssert(
    str_contains($healthcheck, "version_compare(PHP_VERSION, '8.2.0', '>=')"),
    'healthcheck должен проверять технический минимум PHP 8.2'
);
installerCliAssert(
    !str_contains($healthcheck, 'technical floor 8.1') && !str_contains($healthcheck, "'8.1.0'"),
    'healthcheck не должен содержать старый минимум PHP 8.1'
);

fwrite(STDOUT, "[OK] архитектура установщика и CLI закреплена\n");
