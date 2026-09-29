<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bootstrap совместимости updater 1.0.9 доступен только из CLI.\n");
    exit(2);
}

$options = getopt('', ['yes', 'root:', 'help']);
if (isset($options['help']) || !isset($options['yes'])) {
    echo "Одноразовый bootstrap совместимости updater Workspace Organizer 1.0.9\n\n";
    echo "Использование из корня установленного приложения:\n";
    echo "  php bootstrap-1.0.9-updater.php --yes\n\n";
    echo "Либо с явным путём к установке:\n";
    echo "  php bootstrap-1.0.9-updater.php --yes --root=/path/to/workspace\n\n";
    echo "После успешного bootstrap запустите обычное подписанное обновление из интерфейса Workspace Organizer.\n";
    exit(isset($options['help']) ? 0 : 2);
}

$requestedRoot = isset($options['root']) ? trim((string) $options['root']) : getcwd();
$root = is_string($requestedRoot) ? realpath($requestedRoot) : false;
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Не удалось определить корень установки Workspace Organizer.\n");
    exit(2);
}

$versionPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Version.php';
$environmentPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Environment.php';
$compatibilityPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'HostingCompatibility.php';
$runtimePath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateExternalRuntime.php';
$webTransactionPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateWebTransaction.php';

foreach ([$versionPath, $environmentPath, $compatibilityPath, $runtimePath, $webTransactionPath] as $requiredPath) {
    if (!is_file($requiredPath) || is_link($requiredPath) || !is_readable($requiredPath)) {
        fwrite(STDERR, "Не найден обязательный файл exact-установки 1.0.9: {$requiredPath}\n");
        exit(2);
    }
}

$versionSource = file_get_contents($versionPath);
$environmentSource = file_get_contents($environmentPath);
$runtimeOriginal = file_get_contents($runtimePath);
$webTransactionOriginal = file_get_contents($webTransactionPath);
if (
    !is_string($versionSource)
    || !is_string($environmentSource)
    || !is_string($runtimeOriginal)
    || !is_string($webTransactionOriginal)
) {
    fwrite(STDERR, "Не удалось прочитать файлы updater 1.0.9.\n");
    exit(2);
}

$versionOk = preg_match("/public const VERSION = '1\\.0\\.9';/", $versionSource) === 1;
$versionCodeOk = preg_match('/public const VERSION_CODE = 10009;/', $versionSource) === 1;
if (!$versionOk || !$versionCodeOk) {
    fwrite(STDERR, "Bootstrap предназначен только для exact Workspace Organizer 1.0.9 (10009).\n");
    exit(3);
}

if (!str_contains($environmentSource, "require_once __DIR__ . '/HostingCompatibility.php';")) {
    fwrite(STDERR, "Environment.php не соответствует известной exact-схеме 1.0.9; изменение отменено.\n");
    exit(4);
}

$runtimeFixedNeedle = "        'core/Environment.php',\n        'core/HostingCompatibility.php',\n        'core/Version.php',";
$runtimeLegacyPattern = "/        'core\\/Environment\\.php',\\R        'core\\/Version\\.php',/";

$runtimePatched = $runtimeOriginal;
if (!str_contains($runtimePatched, $runtimeFixedNeedle)) {
    $runtimePatched = preg_replace(
        $runtimeLegacyPattern,
        $runtimeFixedNeedle,
        $runtimeOriginal,
        1,
        $runtimeReplacements
    );
    if (!is_string($runtimePatched) || $runtimeReplacements !== 1) {
        fwrite(STDERR, "UpdateExternalRuntime.php не соответствует известной exact-схеме 1.0.9; изменение отменено.\n");
        exit(4);
    }
}

if (substr_count($runtimePatched, "'core/HostingCompatibility.php'") !== 1) {
    fwrite(STDERR, "Не удалось однозначно сформировать исправленный список updater runtime.\n");
    exit(4);
}

$legacyMaintenanceImport = 'use AppServicesMaintenanceModeService;';
$fixedMaintenanceImport = 'use App\\Services\\MaintenanceModeService;';
$webTransactionPatched = $webTransactionOriginal;

if (!str_contains($webTransactionPatched, $fixedMaintenanceImport)) {
    if (!str_contains($webTransactionPatched, $legacyMaintenanceImport)) {
        fwrite(STDERR, "UpdateWebTransaction.php не соответствует известной exact-схеме 1.0.9; изменение отменено.\n");
        exit(4);
    }

    $webTransactionPatched = str_replace(
        $legacyMaintenanceImport,
        $fixedMaintenanceImport,
        $webTransactionOriginal,
        $webTransactionReplacements
    );
    if ($webTransactionReplacements !== 1) {
        fwrite(STDERR, "Не удалось однозначно исправить импорт MaintenanceModeService в updater 1.0.9.\n");
        exit(4);
    }
}

if ($runtimePatched === $runtimeOriginal && $webTransactionPatched === $webTransactionOriginal) {
    fwrite(STDOUT, "Bootstrap уже применён: updater 1.0.9 содержит все исправления совместимости.\n");
    exit(0);
}

$patches = [
    [$runtimePath, $runtimePatched, 'UpdateExternalRuntime.php'],
    [$webTransactionPath, $webTransactionPatched, 'UpdateWebTransaction.php'],
];

foreach ($patches as [$path, $contents, $label]) {
    $current = file_get_contents($path);
    if (!is_string($current) || $current === $contents) {
        continue;
    }

    $temporary = $path . '.bootstrap-' . bin2hex(random_bytes(6)) . '.tmp';
    $mode = fileperms($path);
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        fwrite(STDERR, "Не удалось подготовить временный файл bootstrap для {$label}.\n");
        exit(5);
    }
    if (is_int($mode)) {
        @chmod($temporary, $mode & 0777);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        fwrite(STDERR, "Не удалось атомарно применить bootstrap-исправление к {$label}.\n");
        exit(5);
    }
}

$runtimeWritten = file_get_contents($runtimePath);
$webTransactionWritten = file_get_contents($webTransactionPath);
if (
    !is_string($runtimeWritten)
    || !str_contains($runtimeWritten, $runtimeFixedNeedle)
    || !is_string($webTransactionWritten)
    || !str_contains($webTransactionWritten, $fixedMaintenanceImport)
) {
    fwrite(STDERR, "Проверка записанных bootstrap-исправлений updater 1.0.9 не пройдена.\n");
    exit(5);
}

fwrite(
    STDOUT,
    "Bootstrap применён. Updater 1.0.9 теперь формирует замкнутый автономный runtime "
    . "и корректно загружает MaintenanceModeService. Продолжите обновление обычной кнопкой "
    . "в Workspace Organizer.\n"
);
