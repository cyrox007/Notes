<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/SystemEventOutbox.php';

use Core\SystemEventOutbox;

function systemOutboxAssert(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

$path = sys_get_temp_dir() . '/system-outbox-' . bin2hex(random_bytes(8));
$outbox = new SystemEventOutbox($path);
try {
    $outbox->enqueue('update.installed', 'transaction-123');
    $outbox->enqueue('update.installed', 'transaction-123');
    try {
        $outbox->drain(static function (array $event): bool { throw new RuntimeException('DB unavailable'); });
        throw new LogicException('Expected publication failure');
    } catch (RuntimeException $error) {
        systemOutboxAssert($error->getMessage() === 'DB unavailable', 'Unexpected exception');
    }
    systemOutboxAssert(count(glob($path . '/*.json') ?: []) === 1, 'Failure must preserve the deduplicated event');
    $outbox->enqueue('system.health_failed', '2026-10-13:abc123');
    $events = [];
    $publish = static function (array $event) use (&$events): bool { $events[] = $event; return true; };
    systemOutboxAssert($outbox->drain($publish, 1) === 1, 'Batch must stop at its limit');
    systemOutboxAssert($outbox->drain($publish, 1) === 1, 'Next batch must resume');
    systemOutboxAssert($outbox->drain($publish, 1) === 0, 'Acknowledged events must disappear');
    systemOutboxAssert(count($events) === 2, 'Duplicate event leaked');
    foreach ($events as $event) systemOutboxAssert(array_keys($event) === ['name', 'identity', 'at'], 'Outbox must not carry raw diagnostics');
    foreach ([['unknown.event', '123'], ['system.health_failed', 'secret/path?password=x']] as [$name, $identity]) {
        try {
            $outbox->enqueue($name, $identity);
            throw new LogicException('Invalid event accepted');
        } catch (RuntimeException) {
        }
    }
    echo "[OK] System outbox: outage recovery, deduplication, bounded batches and safe envelopes\n";
} finally {
    foreach (new DirectoryIterator($path) as $entry) if (!$entry->isDot()) unlink($entry->getPathname());
    rmdir($path);
}
