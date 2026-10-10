#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
\Core\CliRuntime::assertCli(1);

if (!defined('SITEPATH')) {
    define('SITEPATH', \Core\CliRuntime::projectRoot());
}

\Core\CliRuntime::loadEnvironment(SITEPATH);
\Core\CliRuntime::registerAutoloader(SITEPATH);

use App\Services\FileLifecycleService;
use Core\CliRuntime;

$options = getopt('', ['cleanup-deleted']);
$cleanupDeleted = array_key_exists('cleanup-deleted', $options);

try {
    $result = (new FileLifecycleService())->reconcile($cleanupDeleted);
    CliRuntime::writeJson($result);

    // Отсутствующие активные файлы означают видимую пользователю потерю согласованности
    // и должны завершать проверку с ненулевым кодом даже без удаления.
    exit($result['active_missing'] === [] ? 0 : 2);
} catch (Throwable $e) {
    error_log('Ошибка сверки хранилища файлов: ' . $e->getMessage());
    CliRuntime::fail($e, false, 'file_storage_reconciliation_failed');
}
