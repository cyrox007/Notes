<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use App\Services\LicenseModuleEntitlementService;
use App\Services\LicenseService;
use Core\CliRuntime;
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
        CliRuntime::writeJson($payload, true);
        return;
    }

    if (isset($payload['license']) && is_array($payload['license'])) {
        $license = $payload['license'];
        echo 'ID установки: ' . (string) ($license['installation_id'] ?? '') . PHP_EOL;
        echo 'Лицензия: ' . (string) ($license['code'] ?? 'неизвестно') . PHP_EOL;
        echo 'Действительна: ' . (!empty($license['valid']) ? 'да' : 'нет') . PHP_EOL;
        if (!empty($license['license_id'])) {
            echo 'ID лицензии: ' . (string) $license['license_id'] . PHP_EOL;
        }
        return;
    }

    if (isset($payload['modules']) && is_array($payload['modules'])) {
        foreach ($payload['modules'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo sprintf(
                "%-12s задано=%-11s фактически=%-11s версия=%s%s\n",
                (string) ($row['module_id'] ?? ''),
                (string) ($row['configured_state'] ?? ''),
                (string) ($row['effective_state'] ?? ''),
                (string) ($row['installed_version'] ?? ''),
                empty($row['last_error']) ? '' : ' ошибка=' . (string) $row['last_error']
            );
        }
        return;
    }

    if (isset($payload['module']) && is_array($payload['module'])) {
        $row = $payload['module'];
        echo sprintf(
            "%s: задано=%s фактически=%s\n",
            (string) ($row['module_id'] ?? ''),
            (string) ($row['configured_state'] ?? ''),
            (string) ($row['effective_state'] ?? '')
        );
    }
}

function controlUsage(): void
{
    echo "Использование:\n";
    echo "  php bin/control.php license status [--json]\n";
    echo "  php bin/control.php license activate --stdin [--json]\n";
    echo "  php bin/control.php license activate --token-file=/защищённый/путь/license.txt [--json]\n";
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
                throw new RuntimeException('Выберите ровно один источник токена: --stdin или --token-file=PATH');
            }

            if ($fromStdin) {
                $token = stream_get_contents(STDIN);
                if (!is_string($token)) {
                    throw new RuntimeException('Не удалось прочитать лицензионный токен из STDIN');
                }
            } else {
                $resolved = realpath((string) $tokenFile);
                if ($resolved === false || !is_file($resolved) || !is_readable($resolved) || is_link((string) $tokenFile)) {
                    throw new RuntimeException('Файл лицензионного токена отсутствует, недоступен или небезопасен');
                }
                $token = file_get_contents($resolved);
                if (!is_string($token)) {
                    throw new RuntimeException('Не удалось прочитать файл лицензионного токена');
                }
            }

            $result = ['status' => 'ok', 'license' => $service->activateFromControlPlane($context, $token)];
        } elseif ($action === 'clear') {
            if (!controlHasFlag($args, '--yes')) {
                throw new RuntimeException('Для очистки лицензии установки требуется явное подтверждение --yes');
            }
            $result = ['status' => 'ok', 'license' => $service->clearFromControlPlane($context)];
        } else {
            throw new RuntimeException('Неизвестное действие лицензии; используйте status, activate или clear');
        }

        controlEmit($result, $json);
        exit(0);
    }

    if ($area === 'modules') {
        $db = DatabaseManager::getInstance();
        $registry = ModuleRegistry::boot(
            $root . '/modules',
            Version::VERSION,
            new ModuleLifecycleStore(
                $db,
                new LicenseModuleEntitlementService(),
            )
        );

        if ($action === 'list') {
            $result = [
                'status' => 'ok',
                'modules' => array_values($registry->lifecycle()),
            ];
        } elseif (in_array($action, ['install', 'enable', 'disable'], true)) {
            $moduleId = trim((string) ($args[2] ?? ''));
            if ($moduleId === '') {
                throw new RuntimeException('Требуется идентификатор модуля');
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
            throw new RuntimeException('Неизвестное действие модулей; используйте list, install, enable или disable');
        }

        controlEmit($result, $json);
        exit(0);
    }

    throw new RuntimeException('Неизвестная область управления; используйте license или modules');
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'control_plane_error');
}
