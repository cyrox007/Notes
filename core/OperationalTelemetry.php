<?php

declare(strict_types=1);

namespace Core;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Minimal production observability boundary.
 *
 * Events are append-only JSONL under private storage. A small rolling metrics
 * window is maintained with flock so cron/monitoring can evaluate alerts
 * without requiring an external metrics backend.
 */
final class OperationalTelemetry
{
    private const EVENT_SCHEMA = 1;
    private const METRICS_SCHEMA = 1;
    private const MAX_EVENT_BYTES = 16384;
    private const MAX_CONTEXT_DEPTH = 3;
    private const MAX_CONTEXT_ITEMS = 50;
    private const MAX_STRING_BYTES = 512;

    private static ?self $default = null;

    private string $root;
    private string $appRoot;
    private Closure $clock;
    private int $windowSeconds;
    private string $requestId;

    public function __construct(
        ?string $root = null,
        ?callable $clock = null,
        ?int $windowSeconds = null,
        ?string $appRoot = null
    ) {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for observability');
        }
        $this->appRoot = $this->normalize($resolvedApp);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn (): int => time();

        $configuredWindow = $windowSeconds ?? $this->envInt('OBSERVABILITY_WINDOW_SECONDS', 300);
        if ($configuredWindow < 60 || $configuredWindow > 86400) {
            throw new RuntimeException('OBSERVABILITY_WINDOW_SECONDS must be between 60 and 86400');
        }
        $this->windowSeconds = $configuredWindow;
        $this->root = $this->resolveRoot($root);
        $this->requestId = $this->resolveRequestId();
    }

    public static function emit(string $event, string $severity = 'info', array $context = []): void
    {
        try {
            self::$default ??= new self();
            self::$default->record($event, $severity, $context);
        } catch (Throwable $e) {
            // Telemetry must never become a new application availability
            // dependency. Monitoring surfaces use snapshot/evaluateAlerts
            // directly and therefore still fail closed when storage is broken.
            error_log('[observability] event write failed: ' . $e->getMessage());
        }
    }

    public static function resetDefault(): void
    {
        self::$default = null;
    }

    public function rootPath(): string
    {
        return $this->root;
    }

    public function record(string $event, string $severity = 'info', array $context = []): void
    {
        $event = strtolower(trim($event));
        $severity = strtolower(trim($severity));
        if (preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D', $event) !== 1) {
            throw new RuntimeException('Invalid observability event name');
        }
        if (!in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
            throw new RuntimeException('Invalid observability severity');
        }

        $now = ($this->clock)();
        if ($now <= 0) {
            throw new RuntimeException('Observability clock returned an invalid timestamp');
        }

        $payload = [
            'schema' => self::EVENT_SCHEMA,
            'at' => gmdate('c', $now),
            'ts' => $now,
            'event' => $event,
            'severity' => $severity,
            'request_id' => $this->requestId,
            'context' => $this->sanitizeArray($context, 0),
        ];
        $line = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($line) > self::MAX_EVENT_BYTES) {
            throw new RuntimeException('Observability event exceeds size limit');
        }

        $eventPath = $this->root . DIRECTORY_SEPARATOR . 'events-' . gmdate('Y-m-d', $now) . '.jsonl';
        $this->appendLocked($eventPath, $line);
        $this->incrementMetrics($event, $severity, $now);
    }

    /**
     * @return array{
     *   schema:int,
     *   window_start:int,
     *   window_seconds:int,
     *   updated_at:int,
     *   counters:array<string,int>,
     *   severities:array<string,int>
     * }
     */
    public function snapshot(): array
    {
        $now = ($this->clock)();
        $currentWindow = $this->windowStart($now);
        $path = $this->metricsPath();
        if (!is_file($path)) {
            return $this->emptyMetrics($currentWindow);
        }
        if (is_link($path)) {
            throw new RuntimeException('Observability metrics path must not be a symlink');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open observability metrics');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Cannot lock observability metrics for reading');
            }
            $bytes = stream_get_contents($handle);
            if (!is_string($bytes)) {
                throw new RuntimeException('Cannot read observability metrics');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $state = $this->decodeMetrics($bytes);
        if ((int) $state['window_start'] !== $currentWindow) {
            return $this->emptyMetrics($currentWindow);
        }
        return $state;
    }

    /**
     * @param array<string,int>|null $thresholds
     * @return array{status:string,root:string,window:array<string,mixed>,alerts:list<array<string,mixed>>}
     */
    public function evaluateAlerts(?array $thresholds = null): array
    {
        $window = $this->snapshot();
        $thresholds ??= [
            'auth.login.failed' => $this->envInt('OBSERVABILITY_AUTH_FAILURE_ALERT', 20),
            'security.rate_limit.denied' => $this->envInt('OBSERVABILITY_RATE_LIMIT_ALERT', 50),
            'license.activation.rejected' => $this->envInt('OBSERVABILITY_LICENSE_REJECT_ALERT', 5),
            'update.transaction.rollback_failed' => $this->envInt('OBSERVABILITY_ROLLBACK_FAILURE_ALERT', 1),
            'severity.error' => $this->envInt('OBSERVABILITY_ERROR_ALERT', 5),
        ];

        $alerts = [];
        foreach ($thresholds as $metric => $threshold) {
            $threshold = (int) $threshold;
            if ($threshold < 1) {
                continue;
            }
            if (str_starts_with($metric, 'severity.')) {
                $key = substr($metric, strlen('severity.'));
                $value = (int) ($window['severities'][$key] ?? 0);
            } else {
                $value = (int) ($window['counters'][$metric] ?? 0);
            }
            if ($value >= $threshold) {
                $alerts[] = [
                    'metric' => $metric,
                    'value' => $value,
                    'threshold' => $threshold,
                ];
            }
        }

        return [
            'status' => $alerts === [] ? 'ok' : 'alert',
            'root' => $this->root,
            'window' => $window,
            'alerts' => $alerts,
        ];
    }

    private function resolveRoot(?string $explicit): string
    {
        $candidate = trim((string) ($explicit ?? ''));
        if ($candidate === '') {
            $candidate = trim((string) (getenv('OBSERVABILITY_PATH') ?: ''));
        }
        if ($candidate === '') {
            $private = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
            if ($private !== '') {
                $candidate = rtrim($private, '/\\') . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'observability';
            }
        }
        if ($candidate === '') {
            $candidate = rtrim(sys_get_temp_dir(), '/\\')
                . DIRECTORY_SEPARATOR . 'workspace-private'
                . DIRECTORY_SEPARATOR . 'logs'
                . DIRECTORY_SEPARATOR . 'observability';
        }

        if (!$this->isAbsolutePath($candidate) || is_link($candidate)) {
            throw new RuntimeException('Observability path must be an absolute non-symlink path');
        }

        if (!is_dir($candidate)) {
            $oldUmask = umask(0077);
            $created = @mkdir($candidate, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($candidate)) {
                throw new RuntimeException('Cannot create observability storage');
            }
        }
        @chmod($candidate, 0700);

        $resolved = realpath($candidate);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Observability storage is not writable');
        }
        $resolved = $this->normalize($resolved);
        if ($this->inside($resolved, $this->appRoot)) {
            throw new RuntimeException('Observability storage must remain outside the application tree');
        }

        return $resolved;
    }

    private function appendLocked(string $path, string $line): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Observability event path is unsafe');
        }
        $oldUmask = umask(0077);
        $handle = @fopen($path, 'ab');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot open observability event log');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock observability event log');
            }
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete observability event');
            }
            @chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function incrementMetrics(string $event, string $severity, int $now): void
    {
        $path = $this->metricsPath();
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Observability metrics path is unsafe');
        }

        $oldUmask = umask(0077);
        $handle = @fopen($path, 'c+');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot open observability metrics state');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock observability metrics state');
            }
            rewind($handle);
            $bytes = stream_get_contents($handle);
            $windowStart = $this->windowStart($now);
            $state = null;
            if (is_string($bytes) && trim($bytes) !== '') {
                try {
                    $candidate = $this->decodeMetrics($bytes);
                    if ((int) $candidate['window_start'] === $windowStart) {
                        $state = $candidate;
                    }
                } catch (Throwable) {
                    // A damaged metrics cache is rebuildable from this event.
                    // The append-only JSONL audit stream remains authoritative.
                    $state = null;
                }
            }
            $state ??= $this->emptyMetrics($windowStart);
            $state['counters'][$event] = (int) ($state['counters'][$event] ?? 0) + 1;
            $state['severities'][$severity] = (int) ($state['severities'][$severity] ?? 0) + 1;
            $state['updated_at'] = $now;

            $encoded = json_encode(
                $state,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
                throw new RuntimeException('Cannot persist observability metrics state');
            }
            @chmod($path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    private function sanitizeArray(array $value, int $depth): array
    {
        if ($depth > self::MAX_CONTEXT_DEPTH) {
            return ['truncated' => true];
        }
        $out = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= self::MAX_CONTEXT_ITEMS) {
                $out['_truncated'] = true;
                break;
            }
            $name = is_int($key) ? (string) $key : substr((string) $key, 0, 80);
            if ($this->isSensitiveKey($name)) {
                $out[$name] = '[REDACTED]';
            } else {
                $out[$name] = $this->sanitizeValue($item, $depth + 1);
            }
            $count++;
        }
        return $out;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->sanitizeArray($value, $depth);
        }
        if (is_string($value)) {
            return strlen($value) > self::MAX_STRING_BYTES
                ? substr($value, 0, self::MAX_STRING_BYTES) . '…'
                : $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        return '[' . get_debug_type($value) . ']';
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/(?:password|passwd|secret|token|authorization|cookie|signature|private[_-]?key|credential)/i',
            $key
        ) === 1;
    }

    /** @return array{schema:int,window_start:int,window_seconds:int,updated_at:int,counters:array<string,int>,severities:array<string,int>} */
    private function emptyMetrics(int $windowStart): array
    {
        return [
            'schema' => self::METRICS_SCHEMA,
            'window_start' => $windowStart,
            'window_seconds' => $this->windowSeconds,
            'updated_at' => $windowStart,
            'counters' => [],
            'severities' => [],
        ];
    }

    /** @return array{schema:int,window_start:int,window_seconds:int,updated_at:int,counters:array<string,int>,severities:array<string,int>} */
    private function decodeMetrics(string $bytes): array
    {
        try {
            $state = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Observability metrics state is invalid JSON', 0, $e);
        }
        if (!is_array($state)
            || ($state['schema'] ?? null) !== self::METRICS_SCHEMA
            || !is_int($state['window_start'] ?? null)
            || !is_int($state['window_seconds'] ?? null)
            || !is_int($state['updated_at'] ?? null)
            || !is_array($state['counters'] ?? null)
            || !is_array($state['severities'] ?? null)) {
            throw new RuntimeException('Observability metrics state failed schema validation');
        }
        return $state;
    }

    private function metricsPath(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'metrics-window.json';
    }

    private function windowStart(int $timestamp): int
    {
        return intdiv($timestamp, $this->windowSeconds) * $this->windowSeconds;
    }

    private function resolveRequestId(): string
    {
        $incoming = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $incoming) === 1) {
            return $incoming;
        }
        return bin2hex(random_bytes(12));
    }

    private function envInt(string $name, int $default): int
    {
        $raw = trim((string) (getenv($name) ?: ''));
        if ($raw === '') {
            return $default;
        }
        if (preg_match('/^[0-9]{1,9}$/D', $raw) !== 1) {
            throw new RuntimeException("{$name} must be a positive integer");
        }
        return (int) $raw;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
