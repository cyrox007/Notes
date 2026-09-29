<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdateAccessBootstrap.php';
require_once __DIR__ . '/PrivateStorageResolver.php';
require_once __DIR__ . '/UpdatePhpCli.php';
require_once __DIR__ . '/UpdateProcessRunner.php';
require_once __DIR__ . '/HostingCompatibility.php';

/**
 * Проверка локальной готовности подписанного обновлятора.
 *
 * Класс не выполняет сетевые запросы, не создаёт каталоги, не включает
 * maintenance, не скачивает пакеты и не меняет БД.
 */
final class UpdateReadiness
{
    private string $appRoot;
    private UpdateManifestVerifier $verifier;

    public function __construct(?string $appRoot = null, ?UpdateManifestVerifier $verifier = null)
    {
        $resolved = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Не удалось определить корень приложения для проверки готовности обновлятора');
        }
        $this->appRoot = $this->normalize($resolved);
        $this->verifier = $verifier ?? new UpdateManifestVerifier();
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $checks = [];
        $issues = [];

        $record = static function (string $name, bool $ok, string $message = '') use (&$checks, &$issues): void {
            $checks[$name] = ['ok' => $ok, 'message' => $message];
            if (!$ok && $message !== '') {
                $issues[] = $message;
            }
        };
        $recordOptional = static function (string $name, bool $ok, string $message = '') use (&$checks): void {
            $checks[$name] = ['ok' => $ok, 'message' => $message];
        };

        $trustReady = $this->verifier->hasTrustedKeys();
        $record(
            'update_trust',
            $trustReady,
            $trustReady ? '' : 'В сборке отсутствует доверенный публичный ключ проверки обновлений.'
        );

        $openssl = extension_loaded('openssl');
        $sodium = extension_loaded('sodium');
        $mysqli = extension_loaded('mysqli');
        $zlib = extension_loaded('zlib');
        $record('extension_openssl', $openssl, $openssl ? '' : 'PHP extension openssl недоступно.');
        $record('extension_sodium', $sodium, $sodium ? '' : 'PHP extension sodium недоступно.');
        $record('extension_mysqli', $mysqli, $mysqli ? '' : 'PHP extension mysqli недоступно.');
        $record('extension_zlib', $zlib, $zlib ? '' : 'PHP extension zlib недоступно.');

        $httpsPrerequisites = HostingCompatibility::outboundHttpsPrerequisites();
        $recordOptional(
            'outbound_https_prerequisites',
            $httpsPrerequisites['ok'],
            $httpsPrerequisites['ok']
                ? 'Локальные PHP-предпосылки исходящего HTTPS готовы; сетевой TCP/443 проверяется только реальным запросом.'
                : 'Для онлайн-обновлений не хватает: ' . implode(', ', $httpsPrerequisites['missing'])
        );

        $procOpen = UpdateProcessRunner::available();
        $recordOptional(
            'proc_open',
            $procOpen,
            $procOpen
                ? 'Доступен ускоренный режим с отдельным PHP-процессом.'
                : 'Недоступен; будет использован совместимый web-режим без запуска процессов.'
        );

        try {
            UpdatePhpCli::resolve();
            $phpCliReady = true;
            $phpCliIssue = 'PHP CLI доступен для ускоренного режима.';
        } catch (Throwable) {
            $phpCliReady = false;
            $phpCliIssue = 'PHP CLI недоступен; будет использован совместимый web-режим.';
        }
        $recordOptional('php_cli', $phpCliReady, $phpCliIssue);
        $processModeAvailable = $procOpen && $phpCliReady;
        $installMode = $processModeAvailable ? 'process' : 'web';

        $maxExecution = (int) ini_get('max_execution_time');
        $executionComfortable = $installMode !== 'web' || $maxExecution <= 0 || $maxExecution >= 30;
        $recordOptional(
            'web_execution_time',
            $executionComfortable,
            $executionComfortable
                ? ($maxExecution <= 0 ? 'Жёсткий PHP timeout не заявлен.' : 'max_execution_time=' . $maxExecution . ' с.')
                : 'max_execution_time=' . $maxExecution . ' с: на большой базе web-updater может не успеть создать резервную точку за один шаг.'
        );

        $memoryLimit = HostingCompatibility::memoryLimitBytes();
        $memoryComfortable = $memoryLimit === null || $memoryLimit >= HostingCompatibility::RECOMMENDED_MEMORY_BYTES;
        $recordOptional(
            'memory_limit',
            $memoryComfortable,
            $memoryLimit === null
                ? 'Конечный memory_limit не заявлен.'
                : sprintf(
                    'memory_limit=%.0f МБ%s',
                    $memoryLimit / 1048576,
                    $memoryComfortable ? '' : '; рекомендуется не менее 128 МБ на PHP-процесс'
                )
        );

        try {
            $feed = UpdateAccessBootstrap::feedUrl();
        } catch (Throwable) {
            $feed = '';
        }
        $feedReady = $this->validFeedUrl($feed);
        $record(
            'feed_url',
            $feedReady,
            $feedReady ? '' : 'Не удалось определить безопасный HTTPS-канал обновлений.'
        );

        try {
            $channel = UpdateAccessBootstrap::channel();
            $channelReady = true;
        } catch (Throwable) {
            $channel = '';
            $channelReady = false;
        }
        $record(
            'channel',
            $channelReady,
            $channelReady ? '' : 'Канал обновлений должен быть alpha, beta или stable.'
        );

        try {
            $accessMode = UpdateDownloadCredentials::accessMode();
            $accessReady = true;
            if ($accessMode !== 'offline') {
                $credentials = null;
                try {
                    $credentials = UpdateDownloadCredentials::fromEnvironment();
                } catch (Throwable) {
                    // Старый, отсутствующий или повреждённый файл доступа
                    // восстанавливается автоматически по лицензии.
                }

                if ($credentials !== null && $feedReady) {
                    $credentials->headersFor($feed);
                } else {
                    UpdateDownloadCredentials::credentialsPath(false);
                }
            }
            $record('update_access', true);
        } catch (Throwable $e) {
            $accessMode = 'invalid';
            $accessReady = false;
            $record(
                'update_access',
                false,
                'Автоматический доступ к обновлениям не готов: ' . $e->getMessage()
            );
        }

        try {
            $private = (new PrivateStorageResolver($this->appRoot))->candidate();
        } catch (Throwable) {
            $private = '';
        }
        $paths = [
            'staging' => $this->configuredRoot('UPDATE_STAGING_PATH', $private, 'updates'),
            'state' => $this->configuredRoot('UPDATE_STATE_PATH', $private, 'updates'),
            'backup' => $this->configuredRoot('UPDATE_BACKUP_PATH', $private, 'update-backups'),
            'release' => $this->configuredRoot('UPDATE_RELEASE_PATH', $private, 'update-releases'),
        ];
        $pathReady = true;
        foreach ($paths as $name => $path) {
            [$ok, $reason] = $this->externalWritablePath($path);
            $pathReady = $pathReady && $ok;
            $record(
                'path_' . $name,
                $ok,
                $ok ? '' : sprintf('Updater %s path не готов: %s', $name, $reason)
            );

            if ($ok) {
                $freeBytes = HostingCompatibility::freeDiskBytes($path);
                $recordOptional(
                    'disk_free_' . $name,
                    $freeBytes === null || $freeBytes >= 256 * 1024 * 1024,
                    $freeBytes === null
                        ? 'Свободное место средствами PHP определить не удалось.'
                        : sprintf(
                            'Свободно %.1f МБ%s',
                            $freeBytes / 1048576,
                            $freeBytes >= 256 * 1024 * 1024 ? '' : '; запас меньше рекомендуемых 256 МБ'
                        )
                );
            }
        }

        $dbUser = trim((string) (getenv('DBUSER') ?: ''));
        $dbName = trim((string) (getenv('DBNAME') ?: ''));
        $dbConfigReady = $dbUser !== '' && $dbName !== '';
        $record(
            'database_config',
            $dbConfigReady,
            $dbConfigReady ? '' : 'Для rollback backup должны быть настроены DBUSER и DBNAME.'
        );

        $networkReady = $accessMode === 'offline' || $httpsPrerequisites['ok'];
        if (!$networkReady) {
            $issues[] = 'Локальные PHP-предпосылки исходящего HTTPS не выполнены: '
                . implode(', ', $httpsPrerequisites['missing']);
        }
        $readyForCheck = $trustReady
            && $openssl
            && $sodium
            && $feedReady
            && $channelReady
            && $accessReady
            && $networkReady;
        $readyForApply = $readyForCheck
            && $mysqli
            && $zlib
            && $pathReady
            && $dbConfigReady;

        return [
            'ready_for_check' => $readyForCheck,
            'ready_for_apply' => $readyForApply,
            'install_mode' => $installMode,
            'process_mode_available' => $processModeAvailable,
            'channel' => $channel,
            'access_mode' => $accessMode,
            'trusted_key_ids' => $this->verifier->trustedKeyIds(),
            'checks' => $checks,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function configuredRoot(string $envName, string $privateRoot, string $fallbackSuffix): string
    {
        $configured = trim((string) (getenv($envName) ?: ''));
        if ($configured !== '') {
            return $configured;
        }
        if ($privateRoot !== '') {
            return rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . $fallbackSuffix;
        }
        return '';
    }

    /** @return array{0:bool,1:string} */
    private function externalWritablePath(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [false, 'путь не настроен и PRIVATE_STORAGE_PATH недоступен'];
        }
        if (!$this->isAbsolute($path)) {
            return [false, 'требуется абсолютный путь'];
        }
        if (preg_match('~(?:^|[\\/])\.\.(?:[\\/]|$)~', $path) === 1) {
            return [false, 'сегменты .. запрещены'];
        }
        if (is_link($path)) {
            return [false, 'symlink не допускается'];
        }

        $normalized = $this->normalize($path);
        if ($this->pathInside($normalized, $this->appRoot)) {
            return [false, 'путь должен находиться вне дерева приложения'];
        }

        if (file_exists($path)) {
            if (!is_dir($path)) {
                return [false, 'существующий путь не является каталогом'];
            }
            $resolved = realpath($path);
            if (!is_string($resolved)) {
                return [false, 'каталог не удалось разрешить'];
            }
            $resolved = $this->normalize($resolved);
            if ($this->pathInside($resolved, $this->appRoot)) {
                return [false, 'разрешённый каталог находится внутри приложения'];
            }
            return is_writable($resolved)
                ? [true, '']
                : [false, 'каталог недоступен на запись'];
        }

        $parent = dirname($path);
        while ($parent !== '' && $parent !== dirname($parent) && !file_exists($parent)) {
            $parent = dirname($parent);
        }
        if ($parent === '' || !is_dir($parent)) {
            return [false, 'не найден существующий родительский каталог'];
        }
        $resolvedParent = realpath($parent);
        if (!is_string($resolvedParent)) {
            return [false, 'родительский каталог не удалось разрешить'];
        }
        if ($this->pathInside($this->normalize($resolvedParent), $this->appRoot)) {
            return [false, 'родительский каталог находится внутри приложения'];
        }
        return is_writable($resolvedParent)
            ? [true, '']
            : [false, 'родительский каталог недоступен на запись'];
    }

    private function validFeedUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }
        $port = $parts['port'] ?? 443;
        return (int) $port === 443;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function pathInside(string $path, string $parent): bool
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
