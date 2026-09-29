<?php

declare(strict_types=1);

use Core\HostingCompatibility;

$root = dirname(__DIR__, 2);
require_once $root . '/core/HostingCompatibility.php';

function hostingCompatibilityAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

hostingCompatibilityAssert(
    HostingCompatibility::iniBytes('128M') === 128 * 1024 * 1024,
    'Парсер memory_limit не понимает мегабайты'
);
hostingCompatibilityAssert(
    HostingCompatibility::iniBytes('1G') === 1024 * 1024 * 1024,
    'Парсер memory_limit не понимает гигабайты'
);
hostingCompatibilityAssert(
    HostingCompatibility::functionAvailable('strlen'),
    'Доступная встроенная функция ошибочно признана отключённой'
);

foreach ([
    ['8.0.0', true, 'MySQL'],
    ['8.4.3', true, 'MySQL'],
    ['5.7.44', false, 'MySQL'],
    ['10.5.0-MariaDB', true, 'MariaDB'],
    ['10.11.8-MariaDB-0ubuntu0.24.04.1', true, 'MariaDB'],
    ['5.5.5-10.11.8-MariaDB', true, 'MariaDB'],
    ['10.4.34-MariaDB', false, 'MariaDB'],
] as [$raw, $expected, $engine]) {
    $support = HostingCompatibility::databaseServerSupport($raw);
    hostingCompatibilityAssert(
        $support['supported'] === $expected,
        'Неверная оценка совместимости БД: ' . $raw
    );
    hostingCompatibilityAssert(
        $support['engine'] === $engine,
        'Неверно определён движок БД: ' . $raw
    );
}

$https = HostingCompatibility::outboundHttpsPrerequisites();
hostingCompatibilityAssert(
    isset($https['ok'], $https['missing']) && is_bool($https['ok']) && is_array($https['missing']),
    'Проверка исходящего HTTPS вернула некорректную структуру'
);

$environment = (string) file_get_contents($root . '/core/Environment.php');
$installer = (string) file_get_contents($root . '/install.php');
$readiness = (string) file_get_contents($root . '/core/UpdateReadiness.php');
$database = (string) file_get_contents($root . '/core/DatabaseManager.php');
$longPoll = (string) file_get_contents($root . '/modules/messenger/services/MessengerLongPollService.php');
$avatar = (string) file_get_contents($root . '/modules/profile/services/AvatarImageProcessor.php');
$backup = (string) file_get_contents($root . '/core/UpdateBackupManager.php');
$remoteTransport = (string) file_get_contents($root . '/core/UpdateRemoteTransport.php');
$releaseCandidate = (string) file_get_contents($root . '/core/UpdateReleaseCandidate.php');

hostingCompatibilityAssert(
    str_contains($environment, 'HostingCompatibility::processEnvironmentAvailable()'),
    'Загрузчик окружения не проверяет getenv/putenv'
);
hostingCompatibilityAssert(
    str_contains($installer, "'getenv / putenv'")
        && str_contains($installer, "'ini_get'")
        && str_contains($installer, "'Writable PHP upload temp'")
        && str_contains($installer, "'flock / atomic rename'")
        && str_contains($installer, 'assertPrivateStorageFilesystemContract')
        && str_contains($installer, 'assertDatabaseServerCompatibility')
        && str_contains($installer, 'assertDatabaseSchemaPrivileges')
        && str_contains($installer, 'CREATE TRIGGER')
        && str_contains($installer, 'HostingCompatibility::memoryLimitBytes()')
        && str_contains($installer, 'connection_aborted')
        && str_contains($installer, 'PHP session storage недоступно')
        && str_contains($installer, 'Фактический доступ к исходящему TCP/443'),
    'Установщик не содержит полный preflight виртуального хостинга'
);
hostingCompatibilityAssert(
    str_contains($readiness, "'outbound_https_prerequisites'")
        && str_contains($readiness, "'disk_free_'")
        && str_contains($readiness, "'web_execution_time'")
        && str_contains($readiness, "'memory_limit'"),
    'Updater readiness не учитывает сеть, диск, timeout или память'
);
hostingCompatibilityAssert(
    str_contains($database, 'public function releaseIdleConnection()'),
    'DatabaseManager не умеет освобождать простаивающее соединение'
);
hostingCompatibilityAssert(
    str_contains($longPoll, '$this->db?->releaseIdleConnection()')
        && str_contains($longPoll, 'POLL_INTERVAL_MICROSECONDS = 1000000'),
    'Long Poll продолжает удерживать MySQL между проверками'
);
hostingCompatibilityAssert(
    str_contains($avatar, 'DEFAULT_MAX_SOURCE_PIXELS')
        && str_contains($avatar, 'HostingCompatibility::memoryLimitBytes()'),
    'Profile не ограничивает декодирование изображения по пикселям и памяти'
);
hostingCompatibilityAssert(
    str_contains($backup, 'assertCapacity($db)')
        && str_contains($backup, 'DATABASE_DUMP_EXPANSION_FACTOR')
        && str_contains($backup, 'HostingCompatibility::freeDiskBytes'),
    'Updater не проверяет запас диска до создания rollback backup'
);
hostingCompatibilityAssert(
    str_contains($remoteTransport, 'HostingCompatibility::freeDiskBytes($parent)')
        && str_contains($remoteTransport, 'Недостаточно свободного места для загрузки обновления'),
    'Updater не проверяет место до загрузки подписанного пакета'
);
hostingCompatibilityAssert(
    str_contains($releaseCandidate, 'assertCapacity($candidateRoot, $entries)')
        && str_contains($releaseCandidate, 'Недостаточно свободного места для release candidate'),
    'Updater не проверяет место до распаковки release candidate'
);

echo "[OK] Контракт жёсткого виртуального хостинга выполнен\n";
