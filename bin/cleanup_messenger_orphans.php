#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
\Core\CliRuntime::assertCli(1);

if (!defined('SITEPATH')) {
    define('SITEPATH', \Core\CliRuntime::projectRoot());
}

require_once SITEPATH . '/core.php';

use App\Services\MessengerMediaCleanupService;
use Core\CliRuntime;

$options = getopt('', ['ttl::', 'limit::']);
$ttl = isset($options['ttl']) && ctype_digit((string) $options['ttl'])
    ? (int) $options['ttl']
    : null;
$limit = isset($options['limit']) && ctype_digit((string) $options['limit'])
    ? (int) $options['limit']
    : 250;

try {
    $result = (new MessengerMediaCleanupService())->cleanup($ttl, $limit);
    CliRuntime::writeJson($result);
} catch (Throwable $e) {
    error_log('Ошибка очистки сиротских файлов Messenger: ' . $e->getMessage());
    CliRuntime::fail($e, false, 'messenger_cleanup_failed');
}
