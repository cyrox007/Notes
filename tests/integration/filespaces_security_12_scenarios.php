<?php

declare(strict_types=1);

// Проверка того, что PRIVATE_STORAGE_PATH нельзя расположить в корне сайта.
require_once $root . '/modules/files/services/FileSpaceStorageGuard.php';

use App\Services\Files\FileSpaceStorageGuard;
use App\Services\Files\FileSpaceTransferService;
use App\Services\Files\FileSpacePurgeService;

$blocked = static function (callable $operation): bool {
    try {
        $operation();
    } catch (\RuntimeException $error) {
        return str_contains($error->getMessage(), 'вне каталогов сайта');
    }
    return false;
};

filespaceCheck($blocked(static fn () =>
    FileSpaceStorageGuard::assertOutsideWebRoot($root)
), 'код приложения не может быть приватным хранилищем');
filespaceCheck($blocked(static fn () =>
    FileSpaceStorageGuard::assertOutsideWebRoot($root . '/uploads')
), 'веб-каталог uploads не может быть приватным хранилищем');
filespaceCheck($blocked(static fn () =>
    FileSpaceStorageGuard::assertOutsideWebRoot(dirname($root))
), 'общий родитель веб-корня не может хранить приватные файлы');

$outside = sys_get_temp_dir() . '/fsp_private_guard_' . bin2hex(random_bytes(8));
filespaceCheck(mkdir($outside, 0700), 'создан безопасный тестовый каталог');
FileSpaceStorageGuard::assertOutsideWebRoot($outside);

$previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
try {
    $_SERVER['DOCUMENT_ROOT'] = $outside;
    filespaceCheck($blocked(static fn () =>
        FileSpaceStorageGuard::assertOutsideWebRoot($outside)
    ), 'запрещён и альтернативный DOCUMENT_ROOT');
} finally {
    if ($previousDocumentRoot === null) {
        unset($_SERVER['DOCUMENT_ROOT']);
    } else {
        $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
    }
}

$transfer = new FileSpaceTransferService(
    $pdo, $access, new \Core\MutationCommandStore(), $root
);
$transferDirectory = new \ReflectionMethod(FileSpaceTransferService::class, 'directory');
filespaceCheck($blocked(static fn () => $transferDirectory->invoke($transfer, false)),
    'прямой путь скачивания отказывает при хранении файлов внутри сайта');

$purge = new FileSpacePurgeService($pdo, $root);
$purgeDirectory = new \ReflectionMethod(FileSpacePurgeService::class, 'storageDirectory');
filespaceCheck($blocked(static fn () => $purgeDirectory->invoke($purge)),
    'команда очистки не работает с каталогом внутри сайта');
filespaceCheck(rmdir($outside), 'тестовый приватный каталог очищен');
echo "Контракт изоляции физического хранилища Files 1.2 выполнен\n";
