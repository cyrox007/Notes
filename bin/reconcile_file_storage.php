#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__DIR__));
}

// Keep this maintenance command usable from cron/CI when configuration is
// supplied through environment variables and no project .env file is present.
require_once SITEPATH . '/core/config.php';
require_once SITEPATH . '/core/DatabaseManager.php';
require_once SITEPATH . '/app/services/FileLifecycleService.php';

use App\Services\FileLifecycleService;

$options = getopt('', ['cleanup-deleted']);
$cleanupDeleted = array_key_exists('cleanup-deleted', $options);

try {
    $result = (new FileLifecycleService())->reconcile($cleanupDeleted);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

    // Missing active files indicate user-visible data inconsistency and should
    // surface to operators even in report-only mode.
    exit($result['active_missing'] === [] ? 0 : 2);
} catch (\Throwable $e) {
    error_log('File Manager storage reconciliation failed: ' . $e->getMessage());
    fwrite(STDERR, "File Manager storage reconciliation failed.\n");
    exit(1);
}
