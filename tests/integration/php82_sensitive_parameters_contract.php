<?php

declare(strict_types=1);

use App\Helpers\CryptMethods;
use App\Helpers\MessengerCrypto;
use App\Services\LicenseVerifier;
use App\Services\TwoFactorService;
use Core\DatabaseManager;
use Core\InstallerDatabaseService;
use Core\UpdateDownloadCredentials;

function sensitiveParameterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sensitiveParameterTraceProbe(#[SensitiveParameter] string $secret): array
{
    return debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
}

function sensitiveParameterAssertMarked(
    string $class,
    string $method,
    string $parameter
): void {
    $reflection = new ReflectionMethod($class, $method);
    foreach ($reflection->getParameters() as $candidate) {
        if ($candidate->getName() !== $parameter) {
            continue;
        }

        sensitiveParameterAssert(
            $candidate->getAttributes(SensitiveParameter::class) !== [],
            sprintf('%s::%s($%s) должен быть помечен SensitiveParameter', $class, $method, $parameter)
        );
        return;
    }

    throw new RuntimeException(sprintf(
        'Не найден параметр %s::%s($%s)',
        $class,
        $method,
        $parameter
    ));
}

$root = dirname(__DIR__, 2);

sensitiveParameterAssert(
    PHP_VERSION_ID >= 80200,
    'Контракт SensitiveParameter должен выполняться на PHP 8.2+'
);

$marker = 'php82-sensitive-marker-' . bin2hex(random_bytes(8));
$trace = sensitiveParameterTraceProbe($marker);
$argument = $trace[0]['args'][0] ?? null;
sensitiveParameterAssert(
    $argument instanceof SensitiveParameterValue,
    'PHP должен заменять чувствительный аргумент объектом SensitiveParameterValue'
);
sensitiveParameterAssert(
    !str_contains(var_export($trace, true), $marker),
    'Исходный секрет не должен присутствовать в debug_backtrace()'
);

require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/app/services/TwoFactorService.php';
require_once $root . '/app/services/LicenseVerifier.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/HostingCompatibility.php';
require_once $root . '/core/InstallerDatabaseService.php';
require_once $root . '/core/UpdateDownloadCredentials.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';

sensitiveParameterAssertMarked(CryptMethods::class, 'hashPassword', 'password');
sensitiveParameterAssertMarked(CryptMethods::class, 'verifyPassword', 'password');
sensitiveParameterAssertMarked(CryptMethods::class, 'encryptWithSecret', 'secret');
sensitiveParameterAssertMarked(CryptMethods::class, 'decryptWithSecret', 'secret');

sensitiveParameterAssertMarked(TwoFactorService::class, 'provisioningUri', 'secret');
sensitiveParameterAssertMarked(TwoFactorService::class, 'matchingCounter', 'secret');
sensitiveParameterAssertMarked(TwoFactorService::class, 'matchingCounter', 'code');
sensitiveParameterAssertMarked(TwoFactorService::class, 'verifyAndConsume', 'code');

sensitiveParameterAssertMarked(LicenseVerifier::class, 'verify', 'token');
sensitiveParameterAssertMarked(UpdateDownloadCredentials::class, '__construct', 'data');
sensitiveParameterAssertMarked(UpdateDownloadCredentials::class, 'store', 'data');
sensitiveParameterAssertMarked(MessengerCrypto::class, 'encryptWithSecret', 'plaintext');
sensitiveParameterAssertMarked(MessengerCrypto::class, 'encryptWithSecret', 'secret');
sensitiveParameterAssertMarked(MessengerCrypto::class, 'decryptCurrentWithSecret', 'secret');

foreach (['queueInsert', 'queueUpdate', 'execute', 'fetchAll', 'fetchOne', 'fetchValue'] as $method) {
    $parameter = in_array($method, ['queueInsert', 'queueUpdate'], true) ? 'data' : 'params';
    sensitiveParameterAssertMarked(DatabaseManager::class, $method, $parameter);
}
sensitiveParameterAssertMarked(DatabaseManager::class, 'buildDsn', 'config');
sensitiveParameterAssertMarked(DatabaseManager::class, 'maskQuery', 'params');

foreach (['connect', 'connectOrCreate', 'importSchemas', 'createAdminUser'] as $method) {
    sensitiveParameterAssertMarked(InstallerDatabaseService::class, $method, 'password');
}
sensitiveParameterAssertMarked(InstallerDatabaseService::class, 'connectServer', 'password');
sensitiveParameterAssertMarked(InstallerDatabaseService::class, 'createDatabase', 'password');

$credentialsClass = new ReflectionClass(UpdateDownloadCredentials::class);
sensitiveParameterAssert(
    $credentialsClass->isReadOnly(),
    'UpdateDownloadCredentials должен оставаться readonly class в линии 1.1'
);

$sourceRequirements = [
    'app/services/LicenseService.php' => [
        '#[SensitiveParameter] string $token',
    ],
    'app/services/UserProvisioningService.php' => [
        '#[SensitiveParameter] array $input',
    ],
    'core/UpdateAccessBootstrap.php' => [
        '#[SensitiveParameter] string $licenseToken',
    ],
];

foreach ($sourceRequirements as $relative => $needles) {
    $source = file_get_contents($root . '/' . $relative);
    sensitiveParameterAssert(is_string($source), 'Не удалось прочитать ' . $relative);

    foreach ($needles as $needle) {
        sensitiveParameterAssert(
            str_contains($source, $needle),
            sprintf('%s должен защищать параметр через %s', $relative, $needle)
        );
    }
}

$installer = file_get_contents($root . '/install.php');
sensitiveParameterAssert(is_string($installer), 'Не удалось прочитать install.php');
sensitiveParameterAssert(
    str_contains($installer, 'PHP_VERSION_ID < 80200'),
    'Установщик должен отклонять PHP ниже 8.2'
);
sensitiveParameterAssert(
    str_contains($installer, "'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>=')"),
    'Проверка требований установщика должна соответствовать минимуму PHP 8.2'
);
sensitiveParameterAssert(
    !str_contains($installer, 'PHP 8.1+'),
    'В установщике не должно оставаться старого требования PHP 8.1+'
);
sensitiveParameterAssert(
    str_contains($installer, 'new \\Core\\InstallerDatabaseService()'),
    'HTTP-установщик должен использовать выделенный сервис БД'
);
sensitiveParameterAssert(
    !str_contains($installer, 'function connectDatabase(')
        && !str_contains($installer, 'function connectOrCreateDatabase(')
        && !str_contains($installer, 'function importSchemas(')
        && !str_contains($installer, 'function createAdminUser('),
    'БД-операции не должны возвращаться в HTTP-файл установщика'
);
sensitiveParameterAssert(
    str_contains($installer, 'function writeEnvironmentFile(string $file, #[SensitiveParameter] array $data): void'),
    'Массив секретов .env должен быть чувствительным параметром установщика'
);

fwrite(STDOUT, "[OK] чувствительные параметры PHP 8.2 скрыты из трассировок\n");
