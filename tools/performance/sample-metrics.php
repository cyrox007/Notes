<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);

use Core\DatabaseManager;

$options = getopt('', ['duration:', 'interval:', 'output:', 'commit:', 'environment:', 'help']);
if (isset($options['help'])) {
    echo "Использование: php tools/performance/sample-metrics.php [--duration=10800] [--interval=5] [--output=FILE]\n";
    echo "Пишет NDJSON с CPU/RAM/swap/process/storage/network/MySQL и очередями приложения.\n";
    exit(0);
}

$duration = isset($options['duration']) ? (int) $options['duration'] : 10800;
$interval = isset($options['interval']) ? (int) $options['interval'] : 5;
$output = trim((string) ($options['output'] ?? (sys_get_temp_dir() . '/workspace-performance-metrics.ndjson')));
$commit = trim((string) ($options['commit'] ?? getenv('PERF_COMMIT_SHA') ?: 'unknown'));
$environment = trim((string) ($options['environment'] ?? getenv('PERF_ENVIRONMENT_NAME') ?: 'reference'));

try {
    if ($duration < 1 || $duration > 14400) {
        throw new InvalidArgumentException('duration должен быть в диапазоне 1..14400 секунд');
    }
    if ($interval < 1 || $interval > 60) {
        throw new InvalidArgumentException('interval должен быть в диапазоне 1..60 секунд');
    }
    if ($output === '') {
        throw new InvalidArgumentException('Не задан путь к файлу метрик');
    }

    $directory = dirname($output);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог для метрик');
    }
    $handle = fopen($output, 'wb');
    if (!is_resource($handle)) {
        throw new RuntimeException('Не удалось открыть файл метрик');
    }
    @chmod($output, 0600);

    $db = DatabaseManager::getInstance();
    writeLine($handle, [
        'type' => 'metadata',
        'schema' => 1,
        'started_at' => gmdate(DATE_ATOM),
        'duration_seconds' => $duration,
        'interval_seconds' => $interval,
        'commit_sha' => $commit,
        'environment' => $environment,
    ]);

    $started = microtime(true);
    $next = $started;
    $previousCpu = readCpuCounters();

    while (true) {
        $now = microtime(true);
        if ($now >= $next) {
            [$cpuPercent, $previousCpu] = cpuPercent($previousCpu, readCpuCounters());
            writeLine($handle, collectSample($db, $cpuPercent));
            fflush($handle);
            $next += $interval;
        }

        if (($now - $started) >= $duration) {
            break;
        }
        $sleep = max(0.05, min(0.25, $next - microtime(true)));
        usleep((int) round($sleep * 1_000_000));
    }

    writeLine($handle, [
        'type' => 'completed',
        'completed_at' => gmdate(DATE_ATOM),
        'elapsed_seconds' => round(microtime(true) - $started, 3),
    ]);
    fflush($handle);
    fclose($handle);
    @chmod($output, 0600);
    DatabaseManager::resetInstance();
    echo 'Сбор метрик завершён: ' . $output . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    DatabaseManager::resetInstance();
    fwrite(STDERR, 'Ошибка сборщика метрик: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @param resource $handle @param array<string,mixed> $payload */
function writeLine($handle, array $payload): void
{
    $line = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (fwrite($handle, $line . PHP_EOL) === false) {
        throw new RuntimeException('Не удалось записать метрики');
    }
}

/** @return array<string,mixed> */
function collectSample(DatabaseManager $db, ?float $cpuPercent): array
{
    $memory = readMeminfo();
    $storagePath = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: dirname(__DIR__, 2)));
    if (!is_dir($storagePath)) {
        $storagePath = dirname(__DIR__, 2);
    }

    $sample = [
        'type' => 'sample',
        'timestamp' => gmdate(DATE_ATOM),
        'monotonic_ns' => hrtime(true),
        'host' => [
            'cpu_percent' => $cpuPercent,
            'load' => readLoad(),
            'memory_total_bytes' => ($memory['MemTotal'] ?? 0) * 1024,
            'memory_available_bytes' => ($memory['MemAvailable'] ?? 0) * 1024,
            'swap_total_bytes' => ($memory['SwapTotal'] ?? 0) * 1024,
            'swap_free_bytes' => ($memory['SwapFree'] ?? 0) * 1024,
            'processes' => webProcessCounts(),
            'disk' => readDiskCounters(),
            'network' => readNetworkCounters(),
        ],
        'storage' => [
            'total_bytes' => safeDiskTotal($storagePath),
            'free_bytes' => safeDiskFree($storagePath),
        ],
        'mysql' => [],
        'application' => [],
    ];

    try {
        $sample['mysql'] = mysqlStatus($db);
        $sample['application'] = applicationQueues($db);
    } catch (Throwable $e) {
        $sample['mysql'] = ['sample_error' => $e::class];
        $sample['application'] = ['sample_error' => $e::class];
    }

    return $sample;
}

/** @return array{total:int,idle:int}|null */
function readCpuCounters(): ?array
{
    $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? null;
    if (!is_string($line) || preg_match('/^cpu\s+(.+)$/', $line, $match) !== 1) {
        return null;
    }
    $values = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
    if (count($values) < 4) {
        return null;
    }
    $total = array_sum($values);
    $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
    return ['total' => $total, 'idle' => $idle];
}

/** @param array{total:int,idle:int}|null $previous @param array{total:int,idle:int}|null $current @return array{0:?float,1:array{total:int,idle:int}|null} */
function cpuPercent(?array $previous, ?array $current): array
{
    if ($previous === null || $current === null) {
        return [null, $current];
    }
    $total = $current['total'] - $previous['total'];
    $idle = $current['idle'] - $previous['idle'];
    if ($total <= 0) {
        return [null, $current];
    }
    return [round(max(0.0, min(100.0, (($total - $idle) / $total) * 100)), 3), $current];
}

/** @return array<string,int> */
function readMeminfo(): array
{
    $result = [];
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $result;
    }
    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $match) === 1) {
            $result[$match[1]] = (int) $match[2];
        }
    }
    return $result;
}

/** @return array{one:?float,five:?float,fifteen:?float} */
function readLoad(): array
{
    $raw = trim((string) @file_get_contents('/proc/loadavg'));
    $parts = preg_split('/\s+/', $raw) ?: [];
    return [
        'one' => isset($parts[0]) ? (float) $parts[0] : null,
        'five' => isset($parts[1]) ? (float) $parts[1] : null,
        'fifteen' => isset($parts[2]) ? (float) $parts[2] : null,
    ];
}

/** @return array{php_fpm:int,apache:int,nginx:int,php_cli:int,total_visible:int} */
function webProcessCounts(): array
{
    $counts = ['php_fpm' => 0, 'apache' => 0, 'nginx' => 0, 'php_cli' => 0, 'total_visible' => 0];
    $paths = glob('/proc/[0-9]*/cmdline');
    if (!is_array($paths)) {
        return $counts;
    }
    foreach ($paths as $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $counts['total_visible']++;
        $cmd = strtolower(str_replace("\0", ' ', $raw));
        if (str_contains($cmd, 'php-fpm')) {
            $counts['php_fpm']++;
        } elseif (str_contains($cmd, 'apache2') || str_contains($cmd, 'httpd')) {
            $counts['apache']++;
        } elseif (str_contains($cmd, 'nginx')) {
            $counts['nginx']++;
        } elseif (preg_match('/(^|\s)php(\s|$)/', $cmd) === 1) {
            $counts['php_cli']++;
        }
    }
    return $counts;
}

/** @return array{read_bytes:int,written_bytes:int,reads:int,writes:int} */
function readDiskCounters(): array
{
    $result = ['read_bytes' => 0, 'written_bytes' => 0, 'reads' => 0, 'writes' => 0];
    $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $result;
    }
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        $device = (string) ($parts[2] ?? '');
        if (preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+)$/', $device) !== 1) {
            continue;
        }
        $result['reads'] += (int) ($parts[3] ?? 0);
        $result['read_bytes'] += (int) ($parts[5] ?? 0) * 512;
        $result['writes'] += (int) ($parts[7] ?? 0);
        $result['written_bytes'] += (int) ($parts[9] ?? 0) * 512;
    }
    return $result;
}

/** @return array{rx_bytes:int,tx_bytes:int} */
function readNetworkCounters(): array
{
    $result = ['rx_bytes' => 0, 'tx_bytes' => 0];
    $lines = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $result;
    }
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $stats] = array_map('trim', explode(':', $line, 2));
        if ($name === 'lo') {
            continue;
        }
        $parts = preg_split('/\s+/', $stats) ?: [];
        $result['rx_bytes'] += (int) ($parts[0] ?? 0);
        $result['tx_bytes'] += (int) ($parts[8] ?? 0);
    }
    return $result;
}

/** @return array<string,int|float|string> */
function mysqlStatus(DatabaseManager $db): array
{
    $wanted = [
        'Threads_connected', 'Threads_running', 'Connections', 'Aborted_connects',
        'Questions', 'Queries', 'Slow_queries', 'Created_tmp_disk_tables',
        'Innodb_row_lock_current_waits', 'Innodb_row_lock_time', 'Innodb_row_lock_waits',
        'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests',
        'Bytes_received', 'Bytes_sent',
    ];
    $quoted = implode(',', array_map(static fn (string $name): string => "'" . $name . "'", $wanted));
    $rows = $db->fetchAll('SHOW GLOBAL STATUS WHERE Variable_name IN (' . $quoted . ')');
    $result = [];
    foreach ($rows as $row) {
        $value = (string) $row['Value'];
        $result[(string) $row['Variable_name']] = is_numeric($value) ? (int) $value : $value;
    }
    $result['database_size_bytes'] = (int) $db->fetchValue(
        'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()'
    );
    return $result;
}

/** @return array<string,int> */
function applicationQueues(DatabaseManager $db): array
{
    $result = [];
    if (tableExists($db, 'notification_delivery_jobs')) {
        $result['notification_jobs_ready'] = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM notification_delivery_jobs WHERE state IN ('pending','retry','processing')"
        );
    }
    if (tableExists($db, 'calendar_reminders')) {
        $result['calendar_reminders_pending'] = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM calendar_reminders WHERE state = 'pending'"
        );
    }
    if (tableExists($db, 'messenger_activity')) {
        $result['messenger_activity_active'] = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM messenger_activity WHERE expires_at > NOW(3)'
        );
    }
    return $result;
}

function tableExists(DatabaseManager $db, string $table): bool
{
    return (int) $db->fetchValue(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
        [':table' => $table]
    ) === 1;
}

function safeDiskTotal(string $path): int
{
    $value = @disk_total_space($path);
    return is_float($value) ? (int) $value : 0;
}

function safeDiskFree(string $path): int
{
    $value = @disk_free_space($path);
    return is_float($value) ? (int) $value : 0;
}
