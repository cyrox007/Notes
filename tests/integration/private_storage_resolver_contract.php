<?php

declare(strict_types=1);

use Core\PrivateStorageResolver;

$root = dirname(__DIR__, 2);
require_once $root . '/core/PrivateStorageResolver.php';

function privateStorageAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit(1);
}

function privateStorageRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            privateStorageRemoveTree($target);
            continue;
        }

        @unlink($target);
    }

    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/wo-private-storage-' . bin2hex(random_bytes(6));
$app = $temp . '/domains/notes.local';
$home = $temp . '/home';

privateStorageAssert(mkdir($app, 0700, true), 'Не удалось создать тестовое дерево приложения');
privateStorageAssert(mkdir($home, 0700, true), 'Не удалось создать тестовый HOME');

$previousPrivate = getenv('PRIVATE_STORAGE_PATH');
$previousHome = getenv('HOME');
$previousEnvPrivate = $_ENV['PRIVATE_STORAGE_PATH'] ?? null;
$previousServerPrivate = $_SERVER['PRIVATE_STORAGE_PATH'] ?? null;

try {
    putenv('PRIVATE_STORAGE_PATH');
    putenv('HOME=' . $home);
    unset($_ENV['PRIVATE_STORAGE_PATH'], $_SERVER['PRIVATE_STORAGE_PATH']);

    $resolver = new PrivateStorageResolver($app, '');
    $candidate = $resolver->candidate();

    $normalizedApp = rtrim(str_replace('\\', '/', (string) realpath($app)), '/');
    $suffix = substr(hash('sha256', $normalizedApp), 0, 10);
    $expected = rtrim(str_replace('\\', '/', dirname((string) realpath($app))), '/')
        . '/.workspace-organizer-private-'
        . $suffix;

    privateStorageAssert(
        str_replace('\\', '/', $candidate) === $expected,
        'Resolver не сохранил исторический sibling-путь установщика'
    );
    privateStorageAssert(!is_dir($candidate), 'Read-only candidate неожиданно создал каталог');

    $prepared = $resolver->prepareAndPublish();
    privateStorageAssert(is_dir($prepared), 'Private storage не был создан автоматически');
    privateStorageAssert(is_writable($prepared), 'Созданный private storage недоступен на запись');
    privateStorageAssert(
        str_replace('\\', '/', (string) getenv('PRIVATE_STORAGE_PATH')) === str_replace('\\', '/', $prepared),
        'Подготовленный private storage не опубликован в текущем процессе'
    );

    putenv('PRIVATE_STORAGE_PATH');
    unset($_ENV['PRIVATE_STORAGE_PATH'], $_SERVER['PRIVATE_STORAGE_PATH']);

    $historical = (new PrivateStorageResolver($app, ''))->candidate();
    privateStorageAssert(
        realpath($historical) === realpath($prepared),
        'Исторический sibling private storage не был найден повторно'
    );

    $configured = $temp . '/configured-private';
    putenv('PRIVATE_STORAGE_PATH=' . $configured);
    $configuredResolver = new PrivateStorageResolver($app, '');
    privateStorageAssert(
        str_replace('\\', '/', $configuredResolver->candidate())
            === str_replace('\\', '/', $configured),
        'Явно настроенный безопасный private storage не получил приоритет'
    );
    privateStorageAssert(
        is_dir($configuredResolver->prepare()),
        'Явно настроенный private storage не был подготовлен'
    );

    putenv('PRIVATE_STORAGE_PATH=' . $app . '/private');
    try {
        (new PrivateStorageResolver($app, ''))->candidate();
        privateStorageAssert(false, 'Private storage внутри приложения был ошибочно разрешён');
    } catch (RuntimeException $e) {
        privateStorageAssert(
            str_contains($e->getMessage(), 'вне дерева приложения'),
            'Запрет private storage внутри приложения вернул неожиданную ошибку'
        );
    }

    echo "[OK] Единый resolver private storage: historical sibling, auto-create и защита границы\n";
} finally {
    if ($previousPrivate === false) {
        putenv('PRIVATE_STORAGE_PATH');
    } else {
        putenv('PRIVATE_STORAGE_PATH=' . $previousPrivate);
    }

    if ($previousHome === false) {
        putenv('HOME');
    } else {
        putenv('HOME=' . $previousHome);
    }

    if ($previousEnvPrivate === null) {
        unset($_ENV['PRIVATE_STORAGE_PATH']);
    } else {
        $_ENV['PRIVATE_STORAGE_PATH'] = $previousEnvPrivate;
    }

    if ($previousServerPrivate === null) {
        unset($_SERVER['PRIVATE_STORAGE_PATH']);
    } else {
        $_SERVER['PRIVATE_STORAGE_PATH'] = $previousServerPrivate;
    }

    privateStorageRemoveTree($temp);
}
