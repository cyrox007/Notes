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

require_once SITEPATH . '/core.php';

use App\Services\MessengerMediaCleanupService;

$options = getopt('', ['ttl::', 'limit::']);
$ttl = isset($options['ttl']) && ctype_digit((string) $options['ttl'])
    ? (int) $options['ttl']
    : null;
$limit = isset($options['limit']) && ctype_digit((string) $options['limit'])
    ? (int) $options['limit']
    : 250;

try {
    $result = (new MessengerMediaCleanupService())->cleanup($ttl, $limit);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    error_log('Messenger orphan cleanup failed: ' . $e->getMessage());
    fwrite(STDERR, "Messenger orphan cleanup failed.\n");
    exit(1);
}
