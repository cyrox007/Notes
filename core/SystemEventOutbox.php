<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/PrivateStorageResolver.php';

use DirectoryIterator;
use RuntimeException;
use Throwable;

/** Durable, bounded operational events; never depends on the application DB. */
final class SystemEventOutbox
{
    public const NAMES = ['update.available', 'update.installed', 'update.rollback', 'system.health_failed', 'system.storage_low'];

    public function __construct(private readonly string $root)
    {
        if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700, true))) {
            throw new RuntimeException('System event storage unavailable');
        }
    }

    public static function forApplication(string $appRoot): self
    {
        return new self((new PrivateStorageResolver($appRoot))->candidate() . '/system-events');
    }

    /** Operational failures must never interrupt apply, recovery or diagnostics. */
    public static function emit(string $appRoot, string $name, string $identity): void
    {
        try {
            self::forApplication($appRoot)->enqueue($name, $identity);
        } catch (Throwable) {
            error_log('System notification could not be queued');
        }
    }

    public function enqueue(string $name, string $identity): void
    {
        if (!in_array($name, self::NAMES, true) || preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $identity) !== 1) {
            throw new RuntimeException('Invalid system event identity');
        }
        $lock = fopen($this->root . '/.lock', 'c');
        if ($lock === false) throw new RuntimeException('System event lock unavailable');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('System event lock unavailable');
            $path = $this->root . '/' . hash('sha256', $name . ':' . $identity) . '.json';
            if (is_file($path)) return;
            $count = 0;
            foreach (new DirectoryIterator($this->root) as $entry) {
                if (!$entry->isDot() && ++$count > 1000) throw new RuntimeException('System event queue full');
            }
            $temp = $this->root . '/.' . bin2hex(random_bytes(8)) . '.tmp';
            try {
                $bytes = json_encode(['name' => $name, 'identity' => $identity, 'at' => time()], JSON_THROW_ON_ERROR);
                if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('System event write failed');
                chmod($temp, 0600);
                if (!rename($temp, $path)) throw new RuntimeException('System event commit failed');
            } finally {
                if (is_file($temp)) unlink($temp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param callable(array{name:string,identity:string,at:int}):bool $publish */
    public function drain(callable $publish, int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) throw new RuntimeException('Invalid system event batch');
        $lock = fopen($this->root . '/.lock', 'c');
        if ($lock === false) throw new RuntimeException('System event lock unavailable');
        $done = 0;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) return 0;
            $scanned = 0;
            foreach (new DirectoryIterator($this->root) as $entry) {
                if (++$scanned > 1002 || $done >= $limit) break;
                if ($entry->isLink() || !$entry->isFile() || preg_match('/^[a-f0-9]{64}\.json$/D', $entry->getFilename()) !== 1) continue;
                if ($entry->getSize() > 2048) throw new RuntimeException('Invalid system event size');
                $event = json_decode((string) file_get_contents($entry->getPathname()), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($event) || !in_array($event['name'] ?? null, self::NAMES, true)
                    || !is_string($event['identity'] ?? null) || preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $event['identity']) !== 1
                    || !is_int($event['at'] ?? null)
                    || hash('sha256', $event['name'] . ':' . $event['identity']) . '.json' !== $entry->getFilename()) {
                    throw new RuntimeException('Invalid stored system event');
                }
                if (!$publish($event)) break;
                if (!unlink($entry->getPathname())) throw new RuntimeException('System event acknowledgement failed');
                ++$done;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $done;
    }
}
