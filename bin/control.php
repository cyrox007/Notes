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
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/config.php';

use App\Services\LicenseService;
use Core\DatabaseManager;
use Core\LocalControlPlaneContext;
use Core\ModuleLifecycleStore;
use Core\ModuleRegistry;
use Core\Version;

/** @param list<string> $args */
function controlHasFlag(array $args, string $flag): bool
{
    return in_array($flag, $args, true);
}

/** @param list<string> $args */
function controlOptionValue(array $args, string $name): ?string
{
    $prefix = $name . '=';
    foreach ($args as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

/** @param array<string,mixed> $payload */
function controlEmit(array $payload, bool $json): void
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    if (($payload['status'] ?? '') !== 'ok') {
        fwrite(STDERR, '[FAIL] ' . (string) ($payload['message'] ?? 'Control-plane operation failed') . PHP_EOL);
        return;
    }

    if (isset($payload['license']) && is_array($payload['license'])) {
        $license = $payload['license'];
        echo 'Installation ID: ' . (string) ($license['installation_id'] ?? '') . PHP_EOL;
        echo 'License: ' . (string) ($license['code'] ?? 'unknown') . PHP_EOL;
        echo 'Valid: ' . (!empty($license['valid']) ? 'yes' : 'no') . PHP_EOL;
        if (!empty($license['license_id'])) {
            echo 'License ID: ' . (string) $license['license_id'] . PHP_EOL;
        }
        return;
    }

    if (isset($payload['modules']) && is_array($payload['modules'])) {
        foreach ($payload['modules'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo sprintf(
                "%-12s configured=%-11s effective=%-11s version=%s%s\n",
                (string) ($row['module_id'] ?? ''),
                (string) ($row['configured_state'] ?? ''),
                (string) ($row['effective_state'] ?? ''),
                (string) ($row['installed_version'] ?? ''),
                empty($row['last_error']) ? '' : ' error=' . (string) $row['last_error']
            );
        }
        return;
    }

    if (isset($payload['module']) && is_array($payload['module'])) {
        $row = $payload['module'];
        echo sprintf(
            "%s: configured=%s effective=%s\n",
            (string) ($row['module_id'] ?? ''),
            (string) ($row['configured_state'] ?? ''),
            (string) ($row['effective_state'] ?? '')
        );
    }
}

function controlUsage(): void
{
    echo "Usage:\n";
    echo "  php bin/control.php license status [--json]\n";
    echo "  php bin/control.php license activate --stdin [--json]\n";
    echo "  php bin/control.php license activate --token-file=/secure/path/license.txt [--json]\n";
    echo "  php bin/control.php license clear --yes [--json]\n";
    echo "  php bin/control.php modules list [--json]\n";
    echo "  php bin/control.php modules install <module-id> [--json]\n";
    echo "  php bin/control.php modules enable <module-id> [--json]\n";
    echo "  php bin/control.php modules disable <module-id> [--json]\n";
}

$args = array_values(array_slice($argv, 1));
$json = controlHasFlag($args, '--json');
$area = strtolower((string) ($args[0] ?? ''));
$action = strtolower((string) ($args[1] ?? ''));

if ($area === '' || $action === '' || controlHasFlag($args, '--help')) {
    controlUsage();
    exit($area === '' || $action === '' ? 2 : 0);
}

try {
    $context = LocalControlPlaneContext::forCli();

    if ($area === 'license') {
        $service = new LicenseService();

        if ($action === 'status') {
            $result = ['status' => 'ok', 'license' => $service->status()];
        } elseif ($action === 'activate') {
            $fromStdin = controlHasFlag($args, '--stdin');
            $tokenFile = controlOptionValue($args, '--token-file');
            if ($fromStdin === ($tokenFile !== null)) {
                throw new RuntimeException('Choose exactly one token source: --stdin or --token-file=PATH');
            }

            if ($fromStdin) {
                $token = stream_get_contents(STDIN);
                if (!is_string($token)) {
                    throw new RuntimeException('Unable to read license token from STDIN');
                }
            } else {
                $resolved = realpath((string) $tokenFile);
                if ($resolved === false || !is_file($resolved) || !is_readable($resolved) || is_link((string) $tokenFile)) {
                    throw new RuntimeException('License token file is missing, unreadable or unsafe');
                }
                $token = file_get_contents($resolved);
                if (!is_string($token)) {
                    throw new RuntimeException('Unable to read license token file');
                }
            }

            $result = ['status' => 'ok', 'license' => $service->activateFromControlPlane($context, $token)];
        } elseif ($action === 'clear') {
            if (!controlHasFlag($args, '--yes')) {
                throw new RuntimeException('Refusing to clear the installation license without --yes');
            }
            $result = ['status' => 'ok', 'license' => $service->clearFromControlPlane($context)];
        } else {
            throw new RuntimeException('Unknown license action; use status, activate or clear');
        }

        controlEmit($result, $json);
        exit(0);
    }

    if ($area === 'modules') {
        $db = DatabaseManager::getInstance();
        $registry = ModuleRegistry::boot(
            $root . '/modules',
            Version::VERSION,
            new ModuleLifecycleStore($db)
        );

        if ($action === 'list') {
            $result = [
                'status' => 'ok',
                'modules' => array_values($registry->lifecycle()),
            ];
        } elseif (in_array($action, ['install', 'enable', 'disable'], true)) {
            $moduleId = trim((string) ($args[2] ?? ''));
            if ($moduleId === '') {
                throw new RuntimeException('Module id is required');
            }
            $target = match ($action) {
                'install' => 'installed',
                'enable' => 'enabled',
                'disable' => 'disabled',
            };
            $result = [
                'status' => 'ok',
                'module' => $registry->transitionLifecycle($moduleId, $target),
            ];
        } else {
            throw new RuntimeException('Unknown modules action; use list, install, enable or disable');
        }

        controlEmit($result, $json);
        exit(0);
    }

    throw new RuntimeException('Unknown control-plane area; use license or modules');
} catch (Throwable $e) {
    $payload = [
        'status' => 'fail',
        'error' => 'control_plane_error',
        'message' => $e->getMessage(),
    ];
    controlEmit($payload, $json);
    exit(1);
}
