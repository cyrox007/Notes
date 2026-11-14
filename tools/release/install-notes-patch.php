<?php

declare(strict_types=1);

/**
 * Установщик пофайлового патча Notes без смены версии.
 *
 * Проверяет файлы до применения, сам создаёт защитную копию вне сайта
 * и возвращает прежние файлы при штатно перехваченной ошибке.
 * Установка: php repair.php --yes --root=/путь/к/Notes.
 * Ни базу данных, ни приватные пользовательские файлы не меняет.
 */
function finishWithError(string $message): never
{
    fwrite(STDERR, "ОШИБКА: " . $message . PHP_EOL);
    exit(1);
}

function within(string $child, string $parent): bool
{
    $child = rtrim(str_replace('\\', '/', $child), '/');
    $parent = rtrim(str_replace('\\', '/', $parent), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $child = strtolower($child);
        $parent = strtolower($parent);
    }
    return $child === $parent || str_starts_with($child, $parent . '/');
}

function safeFile(string $root, string $path): string
{
    if (!preg_match('~^(?:app|core|modules)/[A-Za-z0-9._/-]+$~D', $path)
        || str_contains($path, '//')
        || in_array('..', explode('/', $path), true)
        || in_array('.', explode('/', $path), true)) {
        finishWithError('Небезопасный путь в реестре: ' . $path);
    }

    $relative = '';
    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        $relative .= '/' . $segment;
        $candidate = $root . $relative;
        if (is_link($candidate)) {
            finishWithError('Обнаружена символическая ссылка: ' . $path);
        }
        if (file_exists($candidate)) {
            $actual = realpath($candidate);
            if (!is_string($actual) || !within($actual, $root)) {
                finishWithError('Путь выходит за пределы установки: ' . $path);
            }
        }
    }

    return $root . '/' . $path;
}

function copyWithHash(string $source, string $target, string $hash): void
{
    if (!is_file($source) || is_link($source)
        || !hash_equals($hash, (string) hash_file('sha256', $source))) {
        throw new RuntimeException('Повреждён исходный файл для установки: ' . basename($source));
    }

    $parent = dirname($target);
    if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('Не удалось создать целевой каталог: ' . $parent);
    }
    $temp = $parent . '/.notes-patch-' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        if (!@copy($source, $temp)
            || !hash_equals($hash, (string) hash_file('sha256', $temp))) {
            throw new RuntimeException('Не удалось скопировать проверенный файл: ' . basename($target));
        }
        @chmod($temp, 0644);
        if (!@rename($temp, $target)) {
            // Windows может запрещать rename поверх файла: не удаляем оригинал до успешной записи.
            if (!@copy($temp, $target)) {
                throw new RuntimeException('Не удалось заменить файл: ' . basename($target));
            }
        }
        if (!hash_equals($hash, (string) hash_file('sha256', $target))) {
            throw new RuntimeException('Контроль файла после замены не прошёл: ' . basename($target));
        }
    } finally {
        if (file_exists($temp)) @unlink($temp);
    }
}

function entryData(string $dir): array
{
    $file = $dir . '/manifest.json';
    if (!is_file($file) || is_link($file)) {
        finishWithError('Не найден реестр manifest.json рядом с repair.php');
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)
        || ($decoded['format'] ?? null) !== 'notes-overlay-patch-v1'
        || !is_array($decoded['changed_files'] ?? null)) {
        finishWithError('Некорректный формат реестра патча');
    }
    return $decoded;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Установщик доступен только через PHP CLI');
}

$args = getopt('', ['root:', 'yes', 'check', 'help']);
if (isset($args['help'])) {
    echo "Патч Notes 1.2.0-rc.1 без смены версии\n";
    echo "Установка: php repair.php --yes --root=/путь/к/Notes\n";
    echo "Проверка (необязательно): php repair.php --check --root=/путь/к/Notes\n";
    exit(0);
}
$check = array_key_exists('check', $args);
$apply = array_key_exists('yes', $args);
if (($apply && $check) || (!$apply && !$check)) {
    finishWithError('Для установки укажите --yes --root=/путь/к/Notes');
}

$rootArg = $args['root'] ?? null;
$target = is_string($rootArg) ? realpath($rootArg) : false;
if (!is_string($target) || !is_dir($target) || is_link((string) $rootArg)) {
    finishWithError('Укажите существующий корень сайта через --root=/путь/к/Notes');
}
$target = rtrim($target, '/\\');
$patchDir = realpath(__DIR__);
if (!is_string($patchDir) || within($patchDir, $target) || within($target, $patchDir)) {
    finishWithError('Распакуйте архив отдельно от каталога установленного сайта');
}
$manifest = entryData(__DIR__);

$expectedVersion = (string) ($manifest['application_version'] ?? '');
$installedVersion = @file_get_contents($target . '/core/Version.php');
if (!is_string($installedVersion)
    || !str_contains($installedVersion, "public const VERSION = '" . $expectedVersion . "'")) {
    finishWithError('Версия установки отличается от версии архива: ' . $expectedVersion);
}
if (!empty($manifest['database_migrations']) || !empty($manifest['changes_application_version'])) {
    finishWithError('Архив содержит запрещённое изменение версии или миграции');
}

$entries = $manifest['changed_files'];
if ($entries === []) finishWithError('В архиве нет рабочих файлов');
$seen = [];
$allTarget = true;
$allBase = true;

foreach ($entries as $entry) {
    if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
        finishWithError('Некорректная запись в реестре патча');
    }
    $relative = $entry['path'];
    if (isset($seen[$relative])) finishWithError('Повторяющийся путь: ' . $relative);
    $seen[$relative] = true;
    $live = safeFile($target, $relative);
    $bundle = safeFile(__DIR__, $relative);
    $hash = (string) ($entry['target_sha256'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/D', $hash)
        || !is_file($bundle) || is_link($bundle)
        || !hash_equals($hash, (string) hash_file('sha256', $bundle))) {
        finishWithError('Повреждён файл патча: ' . $relative);
    }
    $current = is_file($live) ? hash_file('sha256', $live) : null;
    $base = $entry['base_sha256'] ?? null;
    if ($base !== null && (!is_string($base) || !preg_match('/^[0-9a-f]{64}$/D', $base))) {
        finishWithError('Некорректная исходная сумма: ' . $relative);
    }
    $allTarget = $allTarget && $current !== null && hash_equals($hash, $current);
    $allBase = $allBase && ($base === null
        ? $current === null
        : is_string($current) && hash_equals($base, $current));
}

if ($allTarget) {
    echo "Патч уже установлен, все " . count($entries) . " файлов проверены.\n";
    exit(0);
}
if (!$allBase) {
    finishWithError(
        'Установка отличается от ожидаемой базовой сборки; замена запрещена. '
        . 'Не копируйте файлы вручную и не удаляйте базу данных.'
    );
}

if ($check) {
    echo 'Проверка успешна. Базовая сборка подходит, рабочих файлов: ' . count($entries) . PHP_EOL;
    echo "Установка: php repair.php --yes --root=/путь/к/Notes\n";
    exit(0);
}

$parent = dirname($target);
if (!is_dir($parent) || !is_writable($parent)) {
    finishWithError('Нет прав на создание автоматической защитной копии рядом с каталогом сайта');
}
$lockPath = $parent . '/.' . basename($target) . '-notes-repair.lock';
if (is_link($lockPath)) {
    finishWithError('Небезопасный путь файла блокировки');
}
$lock = @fopen($lockPath, 'c+b');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    finishWithError('Установка патча уже выполняется');
}

// Повторно проверяем файлы после получения блокировки, до первой замены.
foreach ($entries as $entry) {
    $live = safeFile($target, $entry['path']);
    $base = $entry['base_sha256'];
    $current = is_file($live) ? hash_file('sha256', $live) : null;
    if ($base === null ? $current !== null
        : !is_string($current) || !hash_equals($base, $current)) {
        finishWithError('Исходные файлы изменились после проверки: ' . $entry['path']);
    }
}

$backupDir = $parent . '/.' . basename($target)
    . '-notes-repair-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
if (!@mkdir($backupDir, 0700)) {
    finishWithError('Не удалось автоматически создать защитную копию: ' . $backupDir);
}
$replaced = [];

try {
    // Сначала создаём и проверяем ВСЕ оригиналы до первой замены.
    foreach ($entries as $entry) {
        $path = $entry['path'];
        if ($entry['base_sha256'] === null) continue;
        $source = safeFile($target, $path);
        $backup = $backupDir . '/' . $path;
        $parent = dirname($backup);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Не удалось создать защитную копию для ' . $path);
        }
        if (!@copy($source, $backup)
            || !hash_equals($entry['base_sha256'], (string) hash_file('sha256', $backup))) {
            throw new RuntimeException('Исходный файл не удалось сохранить: ' . $path);
        }
        @chmod($backup, 0600);
    }
    file_put_contents(
        $backupDir . '/manifest.json',
        json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    // Только после проверенной защитной копии изменяем рабочие файлы.
    foreach ($entries as $entry) {
        $relative = $entry['path'];
        $replaced[] = $entry;
        copyWithHash(
            safeFile(__DIR__, $relative),
            safeFile($target, $relative),
            $entry['target_sha256']
        );
    }
    echo 'Патч установлен. Файлов: ' . count($replaced) . PHP_EOL;
    echo 'Защитная копия прежних файлов: ' . $backupDir . PHP_EOL;
    echo "Номер версии не изменён. Перезапустите PHP и обновите браузер через Ctrl+F5.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Не удалось установить патч: ' . $error->getMessage() . PHP_EOL);
    $rollbackFailed = false;
    foreach (array_reverse($replaced) as $entry) {
        try {
            $relative = $entry['path'];
            $live = safeFile($target, $relative);
            if ($entry['base_sha256'] === null) {
                if (file_exists($live) && !@unlink($live)) {
                    throw new RuntimeException('Не удалось удалить новый файл ' . $relative);
                }
                continue;
            }
            copyWithHash($backupDir . '/' . $relative, $live, $entry['base_sha256']);
        } catch (Throwable $restoreError) {
            fwrite(STDERR, 'СБОЙ ОТКАТА: ' . $restoreError->getMessage() . PHP_EOL);
            $rollbackFailed = true;
        }
    }
    fwrite(STDERR, $rollbackFailed
        ? "КРИТИЧЕСКАЯ ОШИБКА: восстановите файлы вручную из указанной защитной папки.\n"
        : "Предыдущие файлы возвращены из защитной папки.\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
