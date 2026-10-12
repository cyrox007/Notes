<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);

use Core\DatabaseManager;

$options = getopt('', ['output:', 'commit:', 'environment:', 'help']);
if (isset($options['help'])) {
    echo "Использование: php tools/performance/collect-environment.php [--output=FILE] [--commit=SHA] [--environment=NAME]\n";
    echo "Сохраняет обезличенный снимок эталонной конфигурации без секретов подключения.\n";
    exit(0);
}

$output = trim((string) ($options['output'] ?? ''));
$commit = trim((string) ($options['commit'] ?? getenv('PERF_COMMIT_SHA') ?: ''));
$environment = trim((string) ($options['environment'] ?? getenv('PERF_ENVIRONMENT_NAME') ?: 'reference'));

try {
    $db = DatabaseManager::getInstance();
    $storagePath = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: $root));
    if (!is_dir($storagePath)) {
        $storagePath = $root;
    }

    $memory = procMeminfo();
    $cpu = procCpuInfo();
    $mysqlVariables = mysqlVariables($db);
    $mysqlVersion = (string) $db->fetchValue('SELECT VERSION()');
    $databaseSize = databaseSize($db);
    $site = parse_url((string) (getenv('SITEURL') ?: ''));

    if ($commit === '') {
        $commit = gitHead($root);
    }

    $snapshot = [
        'schema' => 1,
        'captured_at' => gmdate(DATE_ATOM),
        'environment' => $environment,
        'commit_sha' => $commit,
        'host' => [
            'hostname' => (string) (gethostname() ?: 'unknown'),
            'os_family' => PHP_OS_FAMILY,
            'uname' => php_uname(),
            'cpu_logical' => $cpu['logical'],
            'cpu_model' => $cpu['model'],
            'memory_total_bytes' => ($memory['MemTotal'] ?? 0) * 1024,
            'swap_total_bytes' => ($memory['SwapTotal'] ?? 0) * 1024,
        ],
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => ini_get('opcache.enable'),
            'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps'),
            'extensions' => sortedExtensions(),
        ],
        'mysql' => [
            'version' => $mysqlVersion,
            'variables' => $mysqlVariables,
            'database_size_bytes' => $databaseSize,
        ],
        'storage' => [
            'path_kind' => $storagePath === $root ? 'application_root' : 'private_storage',
            'total_bytes' => safeDiskTotal($storagePath),
            'free_bytes' => safeDiskFree($storagePath),
            'filesystem' => filesystemType($storagePath),
        ],
        'network' => [
            'site_scheme' => is_array($site) ? (string) ($site['scheme'] ?? '') : '',
            'site_host' => is_array($site) ? (string) ($site['host'] ?? '') : '',
        ],
    ];

    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($output === '') {
        echo $json;
    } else {
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог для снимка окружения');
        }
        if (file_put_contents($output, $json, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить снимок окружения');
        }
        @chmod($output, 0600);
        echo 'Снимок окружения сохранён: ' . $output . PHP_EOL;
    }

    DatabaseManager::resetInstance();
    exit(0);
} catch (Throwable $e) {
    DatabaseManager::resetInstance();
    fwrite(STDERR, 'Ошибка снимка эталонного окружения: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string,int> */
function procMeminfo(): array
{
    $result = [];
    $raw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw)) {
        return $result;
    }
    foreach ($raw as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $match) === 1) {
            $result[$match[1]] = (int) $match[2];
        }
    }
    return $result;
}

/** @return array{logical:int,model:string} */
function procCpuInfo(): array
{
    $logical = 0;
    $model = '';
    $raw = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES);
    if (is_array($raw)) {
        foreach ($raw as $line) {
            if (str_starts_with($line, 'processor')) {
                $logical++;
            }
            if ($model === '' && preg_match('/^model name\s*:\s*(.+)$/', $line, $match) === 1) {
                $model = trim($match[1]);
            }
        }
    }
    if ($logical === 0) {
        $logical = 1;
    }
    return ['logical' => $logical, 'model' => $model !== '' ? $model : 'unknown'];
}

/** @return list<string> */
function sortedExtensions(): array
{
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    return array_values($extensions);
}

/** @return array<string,string> */
function mysqlVariables(DatabaseManager $db): array
{
    $wanted = [
        'max_connections',
        'innodb_buffer_pool_size',
        'innodb_log_file_size',
        'innodb_flush_log_at_trx_commit',
        'transaction_isolation',
        'slow_query_log',
        'long_query_time',
        'max_allowed_packet',
        'tmp_table_size',
        'max_heap_table_size',
        'wait_timeout',
    ];
    $quoted = implode(',', array_map(static fn (string $name): string => "'" . $name . "'", $wanted));
    $rows = $db->fetchAll('SHOW VARIABLES WHERE Variable_name IN (' . $quoted . ')');
    $result = [];
    foreach ($rows as $row) {
        $result[(string) $row['Variable_name']] = (string) $row['Value'];
    }
    ksort($result, SORT_STRING);
    return $result;
}

function databaseSize(DatabaseManager $db): int
{
    return (int) $db->fetchValue(
        'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()'
    );
}

function gitHead(string $root): string
{
    if (!function_exists('exec')) {
        return 'unknown';
    }
    $lines = [];
    $code = 1;
    @exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null', $lines, $code);
    if ($code !== 0 || !isset($lines[0]) || preg_match('/^[a-f0-9]{40}$/D', trim($lines[0])) !== 1) {
        return 'unknown';
    }
    return trim($lines[0]);
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

function filesystemType(string $path): string
{
    if (!function_exists('exec')) {
        return 'unknown';
    }
    $lines = [];
    $code = 1;
    @exec('df -PT ' . escapeshellarg($path) . ' 2>/dev/null | tail -n 1', $lines, $code);
    if ($code !== 0 || !isset($lines[0])) {
        return 'unknown';
    }
    $parts = preg_split('/\s+/', trim($lines[0]));
    return is_array($parts) && isset($parts[1]) ? (string) $parts[1] : 'unknown';
}
