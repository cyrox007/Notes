<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bootstrap обновления 1.0.2 доступен только из CLI.\n");
    exit(2);
}

$options = getopt('', ['yes', 'root:', 'help']);
if (isset($options['help']) || !isset($options['yes'])) {
    echo "Одноразовый bootstrap обновлятора Workspace Organizer 1.0.2\n\n";
    echo "Использование из корня установленного приложения:\n";
    echo "  php bootstrap-1.0.2-updater.php --yes\n\n";
    echo "При необходимости корень установки можно указать явно:\n";
    echo "  php bootstrap-1.0.2-updater.php --yes --root=/path/to/workspace\n";
    exit(isset($options['help']) ? 0 : 2);
}

$requestedRoot = isset($options['root']) ? trim((string) $options['root']) : getcwd();
$root = is_string($requestedRoot) ? realpath($requestedRoot) : false;
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Не удалось определить корень установки Workspace Organizer.\n");
    exit(2);
}

$versionPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Version.php';
$applyPath = $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'UpdateApplyCommand.php';
$runPath = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update_run.php';

foreach ([$versionPath, $applyPath, $runPath] as $requiredPath) {
    if (!is_file($requiredPath)) {
        fwrite(STDERR, "Не найден обязательный файл установки: {$requiredPath}\n");
        exit(2);
    }
}

$versionSource = file_get_contents($versionPath);
if (!is_string($versionSource)) {
    fwrite(STDERR, "Не удалось прочитать core/Version.php.\n");
    exit(2);
}

$versionOk = preg_match("/public const VERSION = '1\\.0\\.2';/", $versionSource) === 1;
$versionCodeOk = preg_match('/public const VERSION_CODE = 10002;/', $versionSource) === 1;
if (!$versionOk || !$versionCodeOk) {
    fwrite(STDERR, "Bootstrap предназначен только для exact Workspace Organizer 1.0.2 (10002).\n");
    exit(3);
}

$original = file_get_contents($applyPath);
if (!is_string($original)) {
    fwrite(STDERR, "Не удалось прочитать core/UpdateApplyCommand.php.\n");
    exit(2);
}

$legacyNeedle = "if (\$result['code'] === 1 && str_contains(strtolower(\$result['stdout']), 'not running')) {";
$fixedNeedle = "if (\$result['code'] === 1) {";

if (!str_contains($original, $legacyNeedle)) {
    if (str_contains($original, $fixedNeedle)) {
        fwrite(STDOUT, "Известный дефект проверки WebSocket уже исправлен; запускаю штатный updater.\n");
        $patched = $original;
    } else {
        fwrite(STDERR, "Файл updater не соответствует известному exact 1.0.2; автоматическое изменение отменено.\n");
        exit(4);
    }
} else {
    $patched = str_replace($legacyNeedle, $fixedNeedle, $original, $replacements);
    if ($replacements !== 1) {
        fwrite(STDERR, "Не удалось однозначно применить bootstrap-исправление.\n");
        exit(4);
    }

    $temporary = $applyPath . '.bootstrap-' . bin2hex(random_bytes(6)) . '.tmp';
    $mode = fileperms($applyPath);
    if (file_put_contents($temporary, $patched, LOCK_EX) === false) {
        fwrite(STDERR, "Не удалось подготовить временный файл bootstrap.\n");
        exit(5);
    }

    if (is_int($mode)) {
        @chmod($temporary, $mode & 0777);
    }

    if (!@rename($temporary, $applyPath)) {
        @unlink($temporary);
        fwrite(STDERR, "Не удалось атомарно применить bootstrap-исправление.\n");
        exit(5);
    }

    fwrite(STDOUT, "Известный дефект updater 1.0.2 исправлен локально для одной операции обновления.\n");
}

$command = [PHP_BINARY, $runPath, '--yes', '--json'];
$descriptorSpec = [
    0 => ['file', 'php://stdin', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    if ($patched !== $original && is_file($applyPath)) {
        file_put_contents($applyPath, $original, LOCK_EX);
    }
    fwrite(STDERR, "Не удалось запустить штатный updater.\n");
    exit(6);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if (is_string($stdout) && $stdout !== '') {
    fwrite(STDOUT, $stdout);
}
if (is_string($stderr) && $stderr !== '') {
    fwrite(STDERR, $stderr);
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Bootstrap завершён: штатный подписанный updater выполнил обновление.\n");
    exit(0);
}

// Если рабочее дерево осталось на 1.0.2 и временное исправление всё ещё на месте,
// возвращаем exact-файл 1.0.2. При начавшейся смене версии ничего не маскируем:
// дальнейшие действия должны идти через recovery штатного updater.
$currentVersion = @file_get_contents($versionPath);
$currentApply = @file_get_contents($applyPath);
$stillExact102 = is_string($currentVersion)
    && preg_match("/public const VERSION = '1\\.0\\.2';/", $currentVersion) === 1
    && preg_match('/public const VERSION_CODE = 10002;/', $currentVersion) === 1;
$stillPatched = is_string($currentApply) && str_contains($currentApply, $fixedNeedle);

if ($patched !== $original && $stillExact102 && $stillPatched) {
    $restoreTemporary = $applyPath . '.restore-' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($restoreTemporary, $original, LOCK_EX) !== false) {
        if (is_int($mode)) {
            @chmod($restoreTemporary, $mode & 0777);
        }
        if (@rename($restoreTemporary, $applyPath)) {
            fwrite(STDERR, "Updater завершился с ошибкой до смены версии; exact-файл 1.0.2 восстановлен.\n");
        } else {
            @unlink($restoreTemporary);
        }
    }
}

fwrite(STDERR, "Bootstrap не завершил обновление. Используйте recovery-команду из вывода updater, если она указана.\n");
exit($exitCode > 0 ? $exitCode : 1);
