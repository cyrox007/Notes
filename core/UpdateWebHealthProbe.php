<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DatabaseOwnership.php';
require_once __DIR__ . '/SchemaReadiness.php';
require_once __DIR__ . '/SecurityEventLog.php';
require_once __DIR__ . '/WebSocketEndpoint.php';

/**
 * Проверка работоспособности, пригодная для web-режима обновлятора.
 *
 * Не запускает внешние команды и не зависит от PHP CLI. Проверяются те же
 * критические границы, которые нужны до commit или после rollback: PHP,
 * обязательные расширения и секреты, внешнее хранилище, схема БД и настройки
 * Messenger для установленной композиции модулей.
 */
final class UpdateWebHealthProbe
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $resolved = realpath($root ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('Не удалось безопасно определить корень приложения для health-check');
        }
        $this->root = rtrim($resolved, '/\\');
    }

    /** @return array{status:string,checks:list<array{name:string,ok:bool,details:mixed}>} */
    public function inspect(): array
    {
        $checks = [];
        $failed = false;

        $record = static function (
            string $name,
            bool $ok,
            mixed $details = null
        ) use (&$checks, &$failed): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'details' => $details];
            if (!$ok) {
                $failed = true;
            }
        };

        $record(
            'php_version',
            version_compare(PHP_VERSION, '8.1.0', '>='),
            PHP_VERSION
        );

        foreach (['mysqli', 'pdo_mysql', 'mbstring', 'sodium', 'fileinfo', 'gd'] as $extension) {
            $record('extension_' . $extension, extension_loaded($extension));
        }

        try {
            $ownership = DatabaseOwnership::fromPackageRoot($this->root);
            $modules = $ownership->moduleIds();
            $record('database_ownership', true, implode(',', $modules));
        } catch (Throwable $e) {
            $modules = [];
            $record('database_ownership', false, $e->getMessage());
        }

        $hasMessenger = in_array('messenger', $modules, true);
        $needsPrivateStorage = array_intersect($modules, ['notes', 'files', 'messenger']) !== [];

        $requiredSecrets = ['UNIQUE_KEY'];
        if ($hasMessenger) {
            $requiredSecrets[] = 'MSG_SECRET_KEY';
            $requiredSecrets[] = 'WS_TICKET_SECRET';
        }
        foreach ($requiredSecrets as $secretName) {
            $secret = $this->env($secretName);
            $record(
                'secret_' . strtolower($secretName),
                strlen($secret) >= 32,
                $secret === '' ? 'missing' : 'configured'
            );
        }

        $privateStorage = $this->env('PRIVATE_STORAGE_PATH');
        $privateReal = $privateStorage !== '' ? realpath($privateStorage) : false;
        $privateOk = !$needsPrivateStorage
            || (is_string($privateReal) && is_dir($privateReal) && is_writable($privateReal));
        $record(
            'private_storage',
            $privateOk,
            !$needsPrivateStorage
                ? 'not required'
                : ($privateStorage === '' ? 'missing' : $privateStorage)
        );

        $appReal = realpath($this->root);
        if ($needsPrivateStorage) {
            $outsideApp = $privateOk
                && is_string($privateReal)
                && is_string($appReal)
                && !$this->pathInside($privateReal, $appReal);
            $record(
                'private_storage_outside_app_root',
                $outsideApp,
                is_string($privateReal) ? $privateReal : 'unresolved'
            );
        }

        try {
            $securityHealth = (new SecurityEventLog())->health();
            $record(
                'security_event_log',
                (bool) ($securityHealth['ok'] ?? false),
                (string) ($securityHealth['path'] ?? '')
            );
        } catch (Throwable $e) {
            $record('security_event_log', false, $e->getMessage());
        }

        try {
            $schema = SchemaReadiness::inspect($this->root);
            $record(
                'database_schema',
                (bool) ($schema['ready'] ?? false),
                $schema
            );
        } catch (Throwable $e) {
            $record('database_schema', false, $e->getMessage());
        }

        $siteUrl = $this->env('SITEURL');
        $siteScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME));
        $siteReady = $siteUrl !== '' && in_array($siteScheme, ['http', 'https'], true);
        $record('site_url', $siteReady, $siteUrl === '' ? 'missing' : $siteUrl);

        if ($hasMessenger) {
            try {
                $publicUrl = WebSocketEndpoint::publicUrl();
                $bindHost = WebSocketEndpoint::bindHost();
                $port = WebSocketEndpoint::port();
                $record('websocket_configuration', true, [
                    'public_url' => $publicUrl,
                    'listener' => sprintf('tcp://%s:%d', $bindHost, $port),
                ]);
            } catch (Throwable $e) {
                $record('websocket_configuration', false, $e->getMessage());
            }

            $origins = array_values(array_filter(
                array_map('trim', explode(',', $this->env('WS_ALLOWED_ORIGINS')))
            ));
            $originsOk = $origins !== [];
            foreach ($origins as $origin) {
                $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)
                    || ($siteScheme === 'https' && $scheme !== 'https')) {
                    $originsOk = false;
                    break;
                }
            }
            $record(
                'websocket_allowed_origins',
                $originsOk,
                $origins === [] ? 'missing' : implode(', ', $origins)
            );
        } else {
            $record('messenger_websocket', true, 'not required');
        }

        $nodeCountRaw = $this->env('DEPLOYMENT_NODE_COUNT');
        $nodeCount = $nodeCountRaw === '' ? 1 : (int) $nodeCountRaw;
        $record(
            'deployment_node_count',
            $nodeCount >= 1,
            $nodeCountRaw === '' ? '1' : $nodeCountRaw
        );

        if ($needsPrivateStorage) {
            $rateLimitStorage = $this->env('RATE_LIMIT_STORAGE_PATH');
            $rateLimitReal = $rateLimitStorage !== '' ? realpath($rateLimitStorage) : false;
            $usesPrivate = $rateLimitStorage === '';
            $resolvedRateLimit = $usesPrivate ? $privateReal : $rateLimitReal;
            $rateLimitOk = is_string($resolvedRateLimit)
                && is_dir($resolvedRateLimit)
                && is_writable($resolvedRateLimit);
            if ($nodeCount > 1 && $usesPrivate) {
                $rateLimitOk = false;
            }
            $record(
                'rate_limit_storage',
                $rateLimitOk,
                $usesPrivate ? 'PRIVATE_STORAGE_PATH' : $rateLimitStorage
            );
        }

        return [
            'status' => $failed ? 'fail' : 'ok',
            'checks' => $checks,
        ];
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        return is_string($value) ? trim($value) : '';
    }

    private function pathInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
