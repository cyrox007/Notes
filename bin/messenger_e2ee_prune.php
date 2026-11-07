<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/modules/messenger/services/MessengerE2eeIdentity.php';
require_once $root . '/modules/messenger/services/MessengerE2eeRelayService.php';
require_once $root . '/modules/messenger/services/MessengerE2eeMediaService.php';

use App\Services\MessengerE2eeRelayService;
use App\Services\MessengerE2eeMediaService;

$options = getopt('', ['limit:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Очистка истёкших E2EE-пакетов и использованных одноразовых ключей.\n";
    echo "Использование: php bin/messenger_e2ee_prune.php [--limit=500] [--json]\n";
    exit(0);
}
$limitRaw = $options['limit'] ?? '500';
if (!is_string($limitRaw) || preg_match('/^[1-9][0-9]{0,3}$/D', $limitRaw) !== 1
    || (int) $limitRaw > 2000) {
    fwrite(STDERR, "Некорректный лимит: допустимы целые числа 1–2000.\n");
    exit(2);
}
try {
    $result = (new MessengerE2eeRelayService())->pruneExpired((int) $limitRaw);
    $result['orphan_attachments'] = (new MessengerE2eeMediaService())->pruneOrphans((int)$limitRaw);
    $result['status'] = 'ok';
    if (isset($options['json'])) {
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Удалено истёкших пакетов: {$result['mailbox']}; использованных ключей: {$result['prekeys']}; неприкреплённых файлов: {$result['orphan_attachments']}.\n";
    }
} catch (Throwable $e) {
    error_log('Ошибка очистки E2EE: ' . get_class($e));
    if (isset($options['json'])) {
        echo '{"status":"error","message":"Очистка не выполнена"}' . "\n";
    } else {
        fwrite(STDERR, "Не удалось очистить E2EE-очередь.\n");
    }
    exit(1);
}
