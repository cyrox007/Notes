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

foreach ([$versionPath, $environmentPath, $compatibilityPath, $runtimePath] as $requiredPath) {
    if (!is_file($requiredPath) || is_link($requiredPath) || !is_readable($requiredPath)) {
        fwrite(STDERR, "Не найден обязательный файл exact-установки 1.0.9: {$requiredPath}\n");
        exit(2);
    }
}

$versionSource = file_get_contents($versionPath);
$environmentSource = file_get_contents($environmentPath);
$original = file_get_contents($runtimePath);
if (!is_string($versionSource) || !is_string($environmentSource) || !is_string($original)) {
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

$fixedNeedle = "        'core/Environment.php',\n        'core/HostingCompatibility.php',\n        'core/Version.php',";
if (str_contains($original, $fixedNeedle)) {
    fwrite(STDOUT, "Bootstrap уже применён: автономный updater runtime 1.0.9 содержит HostingCompatibility.php.\n");
    exit(0);
}

$legacyPattern = "/        'core\\/Environment\\.php',\\R        'core\\/Version\\.php',/";
$patched = preg_replace($legacyPattern, $fixedNeedle, $original, 1, $replacements);
if (!is_string($patched) || $replacements !== 1) {
    fwrite(STDERR, "UpdateExternalRuntime.php не соответствует известной exact-схеме 1.0.9; изменение отменено.\n");
    exit(4);
}

if (substr_count($patched, "'core/HostingCompatibility.php'") !== 1) {
    fwrite(STDERR, "Не удалось однозначно сформировать исправленный список updater runtime.\n");
    exit(4);
}

$temporary = $runtimePath . '.bootstrap-' . bin2hex(random_bytes(6)) . '.tmp';
$mode = fileperms($runtimePath);
if (file_put_contents($temporary, $patched, LOCK_EX) === false) {
    fwrite(STDERR, "Не удалось подготовить временный файл bootstrap.\n");
    exit(5);
}
if (is_int($mode)) {
    @chmod($temporary, $mode & 0777);
}
if (!@rename($temporary, $runtimePath)) {
    @unlink($temporary);
    fwrite(STDERR, "Не удалось атомарно применить bootstrap-исправление.\n");
    exit(5);
}

$written = file_get_contents($runtimePath);
if (!is_string($written) || !str_contains($written, $fixedNeedle)) {
    fwrite(STDERR, "Проверка записанного updater runtime не пройдена.\n");
    exit(5);
}

fwrite(
    STDOUT,
    "Bootstrap применён. Updater 1.0.9 теперь формирует замкнутый автономный runtime. "
    . "Продолжите обновление обычной кнопкой в Workspace Organizer.\n"
);
