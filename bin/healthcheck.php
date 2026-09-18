<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/WebSocketEndpoint.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/DatabaseOwnership.php';
require_once $root . '/core/SecurityEventLog.php';

$json = in_array('--json', $argv, true);
$checks = [];
$failed = false;

try {
    $databaseOwnership = \Core\DatabaseOwnership::fromPackageRoot($root);
    $packagedModules = $databaseOwnership->moduleIds();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot resolve packaged module database ownership: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$hasMessenger = in_array('messenger', $packagedModules, true);
$hasFiles = in_array('files', $packagedModules, true);
$needsPrivateStorage = array_intersect($packagedModules, ['notes', 'files', 'messenger']) !== [];

/** @param mixed $details */
function recordHealth(array &$checks, bool &$failed, string $name, bool $ok, $details = null): void
{
    $checks[] = ['name' => $name, 'ok' => $ok, 'details' => $details];
    if (!$ok) {
        $failed = true;
    }
}

function envValue(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

function pathIsInside(string $path, string $parent): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $parent = rtrim(str_replace('\\', '/', $parent), '/');
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

recordHealth($checks, $failed, 'php_version', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION . ' (technical floor 8.1; production 8.3+ recommended)');
foreach (['mysqli', 'pdo_mysql', 'mbstring', 'sodium', 'fileinfo', 'gd'] as $extension) {
    recordHealth($checks, $failed, 'extension_' . $extension, extension_loaded($extension));
}

$requiredSecrets = ['UNIQUE_KEY'];
if ($hasMessenger) {
    $requiredSecrets[] = 'MSG_SECRET_KEY';
    $requiredSecrets[] = 'WS_TICKET_SECRET';
}
foreach ($requiredSecrets as $secretName) {
    $secret = envValue($secretName);
    recordHealth(
        $checks,
        $failed,
        'secret_' . strtolower($secretName),
        strlen($secret) >= 32,
        $secret === '' ? 'missing' : 'configured'
    );
}

$privateStorage = envValue('PRIVATE_STORAGE_PATH');
$privateReal = $privateStorage !== '' ? realpath($privateStorage) : false;
$privateOk = !$needsPrivateStorage || (is_string($privateReal) && is_dir($privateReal) && is_writable($privateReal));
recordHealth(
    $checks,
    $failed,
    'private_storage',
    $privateOk,
    !$needsPrivateStorage ? 'not required by packaged composition' : ($privateStorage === '' ? 'missing' : $privateStorage)
);

$appReal = realpath($root);

try {
    $securityEventLog = new \Core\SecurityEventLog();
    $securityEventHealth = $securityEventLog->health();
    recordHealth(
        $checks,
        $failed,
        'security_event_log',
        (bool) $securityEventHealth['ok'],
        (string) $securityEventHealth['path']
    );
} catch (Throwable $e) {
    recordHealth($checks, $failed, 'security_event_log', false, $e->getMessage());
}

if ($needsPrivateStorage) {
    $outsideApp = $privateOk
        && is_string($privateReal)
        && is_string($appReal)
        && !pathIsInside($privateReal, $appReal);
    recordHealth(
        $checks,
        $failed,
        'private_storage_outside_app_root',
        $outsideApp,
        $privateReal ?: 'unresolved'
    );
}

$nodeCountRaw = envValue('DEPLOYMENT_NODE_COUNT');
$nodeCount = $nodeCountRaw === '' ? 1 : (int) $nodeCountRaw;
recordHealth(
    $checks,
    $failed,
    'deployment_node_count',
    $nodeCount >= 1,
    $nodeCountRaw === '' ? '1 (default)' : $nodeCountRaw
);

if ($needsPrivateStorage) {
    $rateLimitStorage = envValue('RATE_LIMIT_STORAGE_PATH');
    $rateLimitReal = $rateLimitStorage !== '' ? realpath($rateLimitStorage) : false;
    $rateLimitUsesPrivate = $rateLimitStorage === '';
    $rateLimitResolved = $rateLimitUsesPrivate ? $privateReal : $rateLimitReal;
    $rateLimitOk = is_string($rateLimitResolved) && is_dir($rateLimitResolved) && is_writable($rateLimitResolved);
    if ($nodeCount > 1 && $rateLimitUsesPrivate) {
        $rateLimitOk = false;
    }
    recordHealth(
        $checks,
        $failed,
        'rate_limit_storage',
        $rateLimitOk,
        $nodeCount > 1 && $rateLimitUsesPrivate
            ? 'multi-node requires explicit shared RATE_LIMIT_STORAGE_PATH'
            : ($rateLimitUsesPrivate ? 'PRIVATE_STORAGE_PATH (single-node default)' : $rateLimitStorage)
    );

    if (!$rateLimitUsesPrivate) {
        $rateLimitOutsideApp = is_string($rateLimitReal)
            && is_string($appReal)
            && !pathIsInside($rateLimitReal, $appReal);
        recordHealth(
            $checks,
            $failed,
            'rate_limit_storage_outside_app_root',
            $rateLimitOutsideApp,
            $rateLimitReal ?: 'unresolved'
        );
    }
}

$trustedProxyValues = array_values(array_filter(array_map('trim', explode(',', envValue('TRUSTED_PROXY_IPS')))));
$trustedProxyOk = true;
foreach ($trustedProxyValues as $proxyIp) {
    if (filter_var($proxyIp, FILTER_VALIDATE_IP) === false) {
        $trustedProxyOk = false;
        break;
    }
}
recordHealth(
    $checks,
    $failed,
    'trusted_proxy_ips',
    $trustedProxyOk,
    $trustedProxyValues === [] ? 'none configured' : implode(', ', $trustedProxyValues)
);

$siteUrl = envValue('SITEURL');
$siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
if ($hasMessenger) {
    try {
        $siteUrl = \Core\WebSocketEndpoint::siteUrl();
        $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        recordHealth($checks, $failed, 'site_url', true, $siteUrl);

        $wsPublicUrl = \Core\WebSocketEndpoint::publicUrl();
        recordHealth($checks, $failed, 'websocket_url', true, $wsPublicUrl);

        $wsBindHost = \Core\WebSocketEndpoint::bindHost();
        $wsPort = \Core\WebSocketEndpoint::port();
        recordHealth($checks, $failed, 'websocket_listener', true, sprintf('tcp://%s:%d', $wsBindHost, $wsPort));

        if (\Core\WebSocketEndpoint::usesSameOriginProxy()) {
            recordHealth(
                $checks,
                $failed,
                'websocket_proxy_contract',
                true,
                \Core\WebSocketEndpoint::proxyPath() . ' -> ' . \Core\WebSocketEndpoint::proxyBackendUrl()
                    . ' (verify reachability with php bin/ws_doctor.php)'
            );
        } else {
            recordHealth($checks, $failed, 'websocket_proxy_contract', true, 'custom/external public WebSocket endpoint');
        }
    } catch (Throwable $e) {
        recordHealth($checks, $failed, 'site_url', false, $siteUrl !== '' ? $siteUrl : 'missing');
        recordHealth($checks, $failed, 'websocket_url', false, $e->getMessage());
    }

    $origins = array_values(array_filter(array_map('trim', explode(',', envValue('WS_ALLOWED_ORIGINS')))));
    $originsOk = $origins !== [];
    foreach ($origins as $origin) {
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || ($siteScheme === 'https' && $scheme !== 'https')) {
            $originsOk = false;
            break;
        }
    }
    recordHealth(
        $checks,
        $failed,
        'websocket_allowed_origins',
        $originsOk,
        $origins === [] ? 'missing' : implode(', ', $origins)
    );
} else {
    recordHealth($checks, $failed, 'messenger_websocket', true, 'not required by packaged composition');
}

$requiredTables = $databaseOwnership->tables();

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $host = envValue('DBHOST') ?: 'localhost';
    $port = (int) (envValue('DBPORT') ?: '3306');
    $user = envValue('DBUSER');
    $pass = (string) (getenv('DBPASS') ?: '');
    $database = envValue('DBNAME');

    if ($user === '' || $database === '') {
        throw new RuntimeException('DBUSER/DBNAME are not configured');
    }

    $db = new mysqli($host, $user, $pass, $database, $port);
    $db->set_charset('utf8mb4');
    recordHealth($checks, $failed, 'database_connection', true, $database);

    $escaped = array_map(
        static fn (string $table): string => "'" . $db->real_escape_string($table) . "'",
        $requiredTables
    );
    $result = $db->query(
        'SELECT TABLE_NAME AS table_name FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND TABLE_NAME IN (' . implode(',', $escaped) . ')'
    );
    $existing = [];
    while ($row = $result->fetch_assoc()) {
        $existing[] = (string) ($row['table_name'] ?? '');
    }
    $missing = array_values(array_diff($requiredTables, $existing));
    recordHealth(
        $checks,
        $failed,
        'database_contract',
        $missing === [],
        $missing === [] ? count($requiredTables) . ' required tables present' : 'missing: ' . implode(', ', $missing)
    );

    if ($missing === [] && $hasFiles) {
        $quotaSeed = $db->query(
            "SELECT setting_value FROM system_settings WHERE setting_key='file_manager_default_quota_bytes' LIMIT 1"
        )->fetch_row();
        $quotaSeedOk = isset($quotaSeed[0]) && ctype_digit((string) $quotaSeed[0]) && (int) $quotaSeed[0] >= 10485760;
        recordHealth(
            $checks,
            $failed,
            'storage_quota_setting',
            $quotaSeedOk,
            $quotaSeedOk ? (string) $quotaSeed[0] . ' bytes default' : 'missing/invalid default quota'
        );
    }
    $db->close();
} catch (Throwable $e) {
    recordHealth($checks, $failed, 'database_connection', false, $e->getMessage());
}

if ($json) {
    echo json_encode([
        'status' => $failed ? 'fail' : 'ok',
        'packaged_modules' => $packagedModules,
        'checks' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    foreach ($checks as $check) {
        $prefix = $check['ok'] ? '[OK]  ' : '[FAIL]';
        $details = $check['details'] !== null && $check['details'] !== '' ? ' — ' . $check['details'] : '';
        echo $prefix . ' ' . $check['name'] . $details . PHP_EOL;
    }
    echo $failed ? "Healthcheck: FAIL\n" : "Healthcheck: OK\n";
}

exit($failed ? 1 : 0);
