<?php

declare(strict_types=1);

namespace App\Services;

use Core\UpdateArchiveInspector;
use Core\UpdateHttpsTransport;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateRemoteDelivery;
use Core\Version;
use DomainException;
use RuntimeException;

$updateCoreRoot = dirname(__DIR__, 2);
require_once $updateCoreRoot . '/core/UpdateManifestVerifier.php';
require_once $updateCoreRoot . '/core/UpdatePackageStager.php';
require_once $updateCoreRoot . '/core/UpdateArchiveInspector.php';
require_once $updateCoreRoot . '/core/UpdateRemoteTransport.php';
require_once $updateCoreRoot . '/core/UpdateRemoteDelivery.php';

/**
 * Web-facing read/check/stage facade for the signed updater.
 *
 * This service intentionally stops at immutable external staging. It does not
 * enter maintenance, create an update transaction, create rollback backups,
 * extract release candidates or invoke live apply/recovery.
 */
final class AdminUpdateService
{
    private const UI_CONNECT_TIMEOUT_SECONDS = 5;
    private const UI_READ_TIMEOUT_SECONDS = 15;

    private string $appRoot;
    private PermissionService $permissions;
    private UpdateManifestVerifier $verifier;

    public function __construct(
        ?PermissionService $permissions = null,
        ?UpdateManifestVerifier $verifier = null
    ) {
        $root = realpath(dirname(__DIR__, 2));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Не удалось определить корень приложения для проверки обновлений');
        }
        $this->appRoot = $root;
        $this->permissions = $permissions ?? new PermissionService();
        $this->verifier = $verifier ?? new UpdateManifestVerifier();
    }

    /** @return array<string,mixed> */
    public function snapshot(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');

        $feedUrl = $this->feedUrl();
        $channel = $this->channel();
        $stageRoot = $this->stageRoot();
        $trustConfigured = $this->verifier->hasTrustedKeys();
        $opensslAvailable = extension_loaded('openssl');
        $feedConfigured = $feedUrl !== '';
        $channelValid = in_array($channel, ['alpha', 'beta', 'stable'], true);
        $stageConfigured = $stageRoot !== '';
        $canManageStage = $this->permissions->hasRole($actorId, 'superadmin');

        $issues = [];
        if (!$trustConfigured) {
            $issues[] = 'В сборке не настроен публичный ключ проверки обновлений.';
        }
        if (!$opensslAvailable) {
            $issues[] = 'PHP extension openssl недоступно: удалённая проверка обновлений отключена.';
        }
        if (!$feedConfigured) {
            $issues[] = 'UPDATE_FEED_URL не настроен.';
        } elseif (!str_starts_with(strtolower($feedUrl), 'https://')) {
            $issues[] = 'UPDATE_FEED_URL должен использовать HTTPS.';
        }
        if (!$channelValid) {
            $issues[] = 'UPDATE_CHANNEL должен быть alpha, beta или stable.';
        }
        if (!$stageConfigured) {
            $issues[] = 'Не настроен внешний каталог staging (UPDATE_STAGING_PATH или PRIVATE_STORAGE_PATH).';
        }

        $canCheck = $trustConfigured
            && $opensslAvailable
            && $feedConfigured
            && str_starts_with(strtolower($feedUrl), 'https://')
            && $channelValid;

        return [
            'installed_version' => Version::VERSION,
            'installed_version_code' => Version::VERSION_CODE,
            'feed_configured' => $feedConfigured,
            'feed_label' => $feedConfigured ? $this->safeFeedLabel($feedUrl) : '',
            'channel' => $channel,
            'channel_valid' => $channelValid,
            'trust_configured' => $trustConfigured,
            'trusted_key_ids' => $this->verifier->trustedKeyIds(),
            'openssl_available' => $opensslAvailable,
            'stage_configured' => $stageConfigured,
            'can_check' => $canCheck,
            'can_stage' => $canCheck && $stageConfigured && $canManageStage,
            'can_manage_stage' => $canManageStage,
            'issues' => $issues,
        ];
    }

    /** @return array<string,mixed> */
    public function check(int $actorId): array
    {
        $state = $this->snapshot($actorId);
        if (empty($state['can_check'])) {
            throw new DomainException('Проверка обновлений недоступна, пока не устранены ошибки конфигурации', 503);
        }

        return $this->delivery()->check(
            $this->feedUrlOrFail(),
            $this->channelOrFail(),
            Version::VERSION_CODE,
            PHP_VERSION
        );
    }

    /** @return array<string,mixed> */
    public function stage(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Загрузка и staging обновления доступны только суперадминистратору', 403);
        }

        $state = $this->snapshot($actorId);
        if (empty($state['can_check']) || empty($state['stage_configured'])) {
            throw new DomainException('Staging обновления недоступен, пока не устранены ошибки конфигурации', 503);
        }

        return $this->delivery()->stage(
            $this->feedUrlOrFail(),
            $this->channelOrFail(),
            $this->stageRootOrFail(),
            Version::VERSION_CODE,
            PHP_VERSION
        );
    }

    private function delivery(): UpdateRemoteDelivery
    {
        return new UpdateRemoteDelivery(
            $this->appRoot,
            $this->verifier,
            new UpdateHttpsTransport(
                self::UI_CONNECT_TIMEOUT_SECONDS,
                self::UI_READ_TIMEOUT_SECONDS
            ),
            new UpdatePackageStager($this->appRoot),
            new UpdateArchiveInspector()
        );
    }

    private function feedUrl(): string
    {
        $value = getenv('UPDATE_FEED_URL');
        return is_string($value) ? trim($value) : '';
    }

    private function feedUrlOrFail(): string
    {
        $value = $this->feedUrl();
        if ($value === '') {
            throw new RuntimeException('UPDATE_FEED_URL не настроен');
        }
        return $value;
    }

    private function channel(): string
    {
        $value = getenv('UPDATE_CHANNEL');
        $value = is_string($value) ? trim($value) : '';
        return $value !== '' ? $value : 'stable';
    }

    private function channelOrFail(): string
    {
        $value = $this->channel();
        if (!in_array($value, ['alpha', 'beta', 'stable'], true)) {
            throw new RuntimeException('UPDATE_CHANNEL должен быть alpha, beta или stable');
        }
        return $value;
    }

    private function stageRoot(): string
    {
        $configured = getenv('UPDATE_STAGING_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $private = getenv('PRIVATE_STORAGE_PATH');
        if (is_string($private) && trim($private) !== '') {
            return rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . 'updates';
        }
        return '';
    }

    private function stageRootOrFail(): string
    {
        $value = $this->stageRoot();
        if ($value === '') {
            throw new RuntimeException('Внешний каталог staging не настроен');
        }
        return $value;
    }

    private function safeFeedLabel(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '[некорректный URL]';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '') {
            return '[некорректный URL]';
        }
        return $host . ($path !== '' ? $path : '/');
    }
}
