<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

final class SecurityEventLog
{
    public const SCHEMA = 1;
    private const MAX_LINE_BYTES = 16384;
    private const SEVERITIES = ['info', 'warning', 'critical'];

    private string $path;
    private string $appRoot;

    public function __construct(?string $path = null, ?string $appRoot = null)
    {
        $resolvedRoot = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedRoot) || !is_dir($resolvedRoot)) {
            throw new RuntimeException('Application root cannot be resolved for security event log');
        }
        $this->appRoot = self::normalize($resolvedRoot);
        $this->path = $this->resolvePath($path);
    }

    /**
     * Best-effort runtime emitter. Security logging must not turn an otherwise
     * valid user request into an outage; healthcheck separately verifies that
     * the configured storage is writable.
     *
     * @param array<string,mixed> $context
     */
    public static function emit(
        string $event,
        string $severity,
        string $source,
        string $actorType = 'system',
        int|string|null $actorId = null,
        array $context = []
    ): bool {
        try {
            (new self())->record($event, $severity, $source, $actorType, $actorId, $context);
            return true;
        } catch (Throwable $e) {
            error_log('Security event log unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /** @param array<string,mixed> $context */
    public function record(
        string $event,
        string $severity,
        string $source,
        string $actorType = 'system',
        int|string|null $actorId = null,
        array $context = []
    ): void {
        if (preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D', $event) !== 1) {
            throw new RuntimeException('Invalid security event name');
        }
        if (!in_array($severity, self::SEVERITIES, true)) {
            throw new RuntimeException('Invalid security event severity');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $source) !== 1) {
            throw new RuntimeException('Invalid security event source');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,31}$/D', $actorType) !== 1) {
            throw new RuntimeException('Invalid security event actor type');
        }

        $now = time();
        $payload = [
            'schema' => self::SCHEMA,
            'ts' => $now,
            'at' => gmdate('c', $now),
            'event' => $event,
            'severity' => $severity,
            'source' => $source,
            'actor_type' => $actorType,
            'actor_id' => $actorId === null ? null : (string) $actorId,
            'context' => $this->sanitizeContext($context),
        ];

        $line = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($line) > self::MAX_LINE_BYTES) {
            throw new RuntimeException('Security event exceeds maximum line size');
        }

        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Cannot open security event log');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock security event log');
            }
            $written = fwrite($handle, $line);
            if ($written !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Cannot append complete security event');
            }
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array{critical?:int,auth_failures?:int,rate_limited?:int} $thresholds
     * @return array<string,mixed>
     */
    public function summarize(int $windowSeconds, array $thresholds = [], ?int $now = null): array
    {
        $windowSeconds = max(60, min($windowSeconds, 86400 * 30));
        $now ??= time();
        $cutoff = $now - $windowSeconds;

        $counts = [];
        $severity = ['info' => 0, 'warning' => 0, 'critical' => 0];
        $parseErrors = 0;
        $matched = 0;

        if (is_file($this->path)) {
            $handle = fopen($this->path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Cannot read security event log');
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    if (strlen($line) > self::MAX_LINE_BYTES) {
                        $parseErrors++;
                        continue;
                    }
                    try {
                        $event = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        $parseErrors++;
                        continue;
                    }
                    if (!is_array($event) || (int) ($event['schema'] ?? 0) !== self::SCHEMA) {
                        $parseErrors++;
                        continue;
                    }
                    $ts = (int) ($event['ts'] ?? 0);
                    if ($ts < $cutoff || $ts > $now + 300) {
                        continue;
                    }
                    $name = (string) ($event['event'] ?? '');
                    $level = (string) ($event['severity'] ?? '');
                    if ($name === '' || !isset($severity[$level])) {
                        $parseErrors++;
                        continue;
                    }
                    $counts[$name] = (int) ($counts[$name] ?? 0) + 1;
                    $severity[$level]++;
                    $matched++;
                }
            } finally {
                fclose($handle);
            }
        }

        ksort($counts);
        $limits = [
            'critical' => max(1, (int) ($thresholds['critical'] ?? 1)),
            'auth_failures' => max(1, (int) ($thresholds['auth_failures'] ?? 10)),
            'rate_limited' => max(1, (int) ($thresholds['rate_limited'] ?? 3)),
        ];
        $alerts = [];
        if ($severity['critical'] >= $limits['critical']) {
            $alerts[] = [
                'code' => 'critical_security_events',
                'count' => $severity['critical'],
                'threshold' => $limits['critical'],
            ];
        }
        $authFailures = (int) ($counts['auth.login_failed'] ?? 0) + (int) ($counts['auth.login_blocked'] ?? 0);
        if ($authFailures >= $limits['auth_failures']) {
            $alerts[] = [
                'code' => 'authentication_failures',
                'count' => $authFailures,
                'threshold' => $limits['auth_failures'],
            ];
        }
        $rateLimited = (int) ($counts['auth.rate_limited'] ?? 0);
        if ($rateLimited >= $limits['rate_limited']) {
            $alerts[] = [
                'code' => 'authentication_rate_limited',
                'count' => $rateLimited,
                'threshold' => $limits['rate_limited'],
            ];
        }
        if ($parseErrors > 0) {
            $alerts[] = [
                'code' => 'security_event_log_parse_errors',
                'count' => $parseErrors,
                'threshold' => 1,
            ];
        }

        return [
            'status' => $alerts === [] ? 'ok' : 'alert',
            'window_seconds' => $windowSeconds,
            'from_ts' => $cutoff,
            'to_ts' => $now,
            'events' => $matched,
            'severity' => $severity,
            'counts' => $counts,
            'parse_errors' => $parseErrors,
            'alerts' => $alerts,
            'log_path' => $this->path,
        ];
    }

    /** @return array{ok:bool,path:string,details:string} */
    public function health(): array
    {
        $directory = dirname($this->path);
        $ok = is_dir($directory)
            && is_writable($directory)
            && (!file_exists($this->path) || (is_file($this->path) && !is_link($this->path) && is_writable($this->path)));

        return [
            'ok' => $ok,
            'path' => $this->path,
            'details' => $ok ? 'writable external JSONL security event log' : 'security event log path is not writable',
        ];
    }

    private function resolvePath(?string $explicit): string
    {
        $path = trim((string) ($explicit ?? ''));
        if ($path === '') {
            $configured = getenv('SECURITY_EVENT_LOG_PATH');
            $path = is_string($configured) ? trim($configured) : '';
        }
        if ($path === '') {
            $private = getenv('PRIVATE_STORAGE_PATH');
            $private = is_string($private) ? trim($private) : '';
            if ($private === '') {
                throw new RuntimeException('PRIVATE_STORAGE_PATH or SECURITY_EVENT_LOG_PATH is required');
            }
            $path = rtrim($private, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'security-events.jsonl';
        }
        if (!self::isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Security event log path must be an absolute non-symlink path');
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            $oldUmask = umask(0077);
            $created = @mkdir($directory, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($directory)) {
                throw new RuntimeException('Cannot create security event log directory');
            }
        }
        if (is_link($directory) || !is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Security event log directory is unsafe or not writable');
        }
        @chmod($directory, 0700);

        $resolvedDirectory = realpath($directory);
        if (!is_string($resolvedDirectory)) {
            throw new RuntimeException('Cannot resolve security event log directory');
        }
        $resolvedDirectory = self::normalize($resolvedDirectory);
        if (self::inside($resolvedDirectory, $this->appRoot)) {
            throw new RuntimeException('Security event log must be outside the application tree');
        }

        $resolved = $resolvedDirectory . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($resolved) && (!is_file($resolved) || is_link($resolved))) {
            throw new RuntimeException('Security event log target is unsafe');
        }

        return $resolved;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitizeContext(array $context): array
    {
        $out = [];
        $count = 0;
        foreach ($context as $key => $value) {
            if ($count++ >= 32) {
                break;
            }
            $name = substr((string) $key, 0, 64);
            if ($name === '') {
                continue;
            }
            if (preg_match('/(?:^|_)(password|passphrase|secret|token|authorization|cookie|csrf|session)(?:$|_)/i', $name) === 1) {
                $out[$name] = '[redacted]';
                continue;
            }
            $out[$name] = $this->sanitizeValue($value, 0);
        }
        return $out;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 512);
        }
        if (is_array($value) && $depth < 2) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if ($count++ >= 16) {
                    break;
                }
                $name = substr((string) $key, 0, 64);
                if (preg_match('/(?:^|_)(password|passphrase|secret|token|authorization|cookie|csrf|session)(?:$|_)/i', $name) === 1) {
                    $out[$name] = '[redacted]';
                    continue;
                }
                $out[$name] = $this->sanitizeValue($child, $depth + 1);
            }
            return $out;
        }
        return '[' . get_debug_type($value) . ']';
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private static function inside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
