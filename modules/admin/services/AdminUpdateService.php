<?php

declare(strict_types=1);

namespace App\Services;

use Core\UpdateAccessBootstrap;
use Core\UpdateCredentialRefreshingTransport;
use Core\UpdateArchiveInspector;
use Core\UpdateDownloadCredentials;
use Core\UpdateHttpsTransport;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdatePhpCli;
use Core\UpdateProcessRunner;
use Core\UpdateRemoteDelivery;
use Core\UpdateRemoteTransport;
use Core\UpdateReadiness;
use Core\Version;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

$updateCoreRoot = dirname(__DIR__, 3);
require_once $updateCoreRoot . '/core/UpdateAccessBootstrap.php';
require_once $updateCoreRoot . '/core/UpdateManifestVerifier.php';
require_once $updateCoreRoot . '/core/UpdatePackageStager.php';
require_once $updateCoreRoot . '/core/UpdateArchiveInspector.php';
require_once $updateCoreRoot . '/core/UpdatePhpCli.php';
require_once $updateCoreRoot . '/core/UpdateProcessRunner.php';
require_once $updateCoreRoot . '/core/UpdateRemoteTransport.php';
require_once $updateCoreRoot . '/core/UpdateRemoteDelivery.php';
require_once $updateCoreRoot . '/core/UpdateDownloadCredentials.php';
require_once $updateCoreRoot . '/core/UpdateReadiness.php';

/**
 * Web-фасад подписанного обновлятора.
 *
 * Проверка и staging выполняются напрямую через безопасные классы доставки.
 * Установка не дублирует destructive-логику: она запускает существующий
 * транзакционный bin/update_run.php фиксированным argv без shell.
 */
final class AdminUpdateService
{
    private const UI_CONNECT_TIMEOUT_SECONDS = 5;
    private const UI_READ_TIMEOUT_SECONDS = 15;

    private string $appRoot;
    private PermissionService $permissions;
    private UpdateManifestVerifier $verifier;
    private ?UpdateRemoteTransport $transport;

    /** @var (\Closure(list<string>,string,int):array{code:int,stdout:string,stderr:string})|null */
    private ?\Closure $processInvoker;

    public function __construct(
        ?PermissionService $permissions = null,
        ?UpdateManifestVerifier $verifier = null,
        ?UpdateRemoteTransport $transport = null,
        ?\Closure $processInvoker = null
    ) {
        $root = realpath(dirname(__DIR__, 3));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Не удалось определить корень приложения для проверки обновлений');
        }
        $this->appRoot = $root;
        $this->permissions = $permissions ?? new PermissionService();
        $this->verifier = $verifier ?? new UpdateManifestVerifier();
        $this->transport = $transport;
        $this->processInvoker = $processInvoker;
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

        $operator = (new UpdateReadiness($this->appRoot, $this->verifier))->inspect();
        $issues = [];
        [$accessReady, $accessAutomatic, $accessMode] = $this->accessState($feedUrl);

        if (!$trustConfigured) {
            $issues[] = 'В сборке не настроен публичный ключ проверки обновлений.';
        }
        if (!$opensslAvailable) {
            $issues[] = 'PHP extension openssl недоступно: удалённая проверка обновлений отключена.';
        }
        if (!$feedConfigured) {
            $issues[] = 'Не удалось определить адрес канала обновлений.';
        } elseif (!str_starts_with(strtolower($feedUrl), 'https://')) {
            $issues[] = 'Канал обновлений должен использовать HTTPS.';
        }
        if (!$channelValid) {
            $issues[] = 'Канал обновлений должен быть alpha, beta или stable.';
        }
        if (!$stageConfigured) {
            $issues[] = 'Не настроен внешний каталог подготовки обновлений.';
        }
        if (!$accessReady && !$accessAutomatic && $accessMode !== 'offline') {
            $issues[] = 'Для автоматического доступа к обновлениям нужна действующая лицензия.';
        }

        $canCheck = $trustConfigured
            && ($accessReady || $accessAutomatic)
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
            'update_access_ready' => $accessReady,
            'update_access_automatic' => $accessAutomatic,
            'update_access_mode' => $accessMode,
            'can_check' => $canCheck,
            'can_stage' => $canCheck && $stageConfigured && $canManageStage,
            'can_manage_stage' => $canManageStage,
            'can_apply' => $canManageStage && (bool) ($operator['ready_for_apply'] ?? false),
            'operator_ready' => (bool) ($operator['ready_for_apply'] ?? false),
            'operator_issues' => is_array($operator['issues'] ?? null) ? $operator['issues'] : [],
            'issues' => $issues,
        ];
    }

    /** @return array<string,mixed> */
    public function check(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        $this->ensureAutomaticAccess();
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

    /**
     * Устанавливает последнее совместимое подписанное обновление за одно действие.
     *
     * Проверка feed и привязка к version_code/SHA-256 выполняются внутри этого
     * же запроса, поэтому пользователь не обязан предварительно открывать
     * отдельную ручную проверку обновлений.
     *
     * @return array<string,mixed>
     */
    public function applyLatest(int $actorId): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Установка обновления доступна только суперадминистратору', 403);
        }

        $check = $this->check($actorId);
        if (($check['status'] ?? '') !== 'update_available' || empty($check['update_available'])) {
            throw new DomainException(
                'Для этой установки сейчас нет совместимого нового обновления',
                409
            );
        }

        $targetVersionCode = (int) ($check['target_version_code'] ?? 0);
        $packageSha256 = strtolower(trim((string) ($check['package_sha256'] ?? '')));
        if ($targetVersionCode <= Version::VERSION_CODE
            || preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
            throw new RuntimeException('Сервер обновлений вернул некорректную привязку релиза');
        }

        return $this->apply($actorId, $targetVersionCode, $packageSha256);
    }

    /** @return array<string,mixed> */
    public function stage(int $actorId, int $expectedTargetVersionCode, string $expectedPackageSha256): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Загрузка и staging обновления доступны только суперадминистратору', 403);
        }
        if ($expectedTargetVersionCode <= 0) {
            throw new InvalidArgumentException('Повторно проверьте обновление перед staging');
        }
        $expectedPackageSha256 = strtolower(trim($expectedPackageSha256));
        if (preg_match('/^[0-9a-f]{64}$/', $expectedPackageSha256) !== 1) {
            throw new InvalidArgumentException('Повторно проверьте обновление перед staging');
        }

        $this->ensureAutomaticAccess();
        $state = $this->snapshot($actorId);
        if (empty($state['can_check']) || empty($state['stage_configured'])) {
            throw new DomainException('Staging обновления недоступен, пока не устранены ошибки конфигурации', 503);
        }

        return $this->delivery()->stage(
            $this->feedUrlOrFail(),
            $this->channelOrFail(),
            $this->stageRootOrFail(),
            Version::VERSION_CODE,
            PHP_VERSION,
            $expectedTargetVersionCode,
            $expectedPackageSha256
        );
    }

    /** @return array<string,mixed> */
    public function apply(int $actorId, int $expectedTargetVersionCode, string $expectedPackageSha256): array
    {
        $this->permissions->requirePermission($actorId, 'admin.settings.manage');
        if (!$this->permissions->hasRole($actorId, 'superadmin')) {
            throw new DomainException('Установка обновления доступна только суперадминистратору', 403);
        }

        if ($expectedTargetVersionCode <= 0) {
            throw new InvalidArgumentException('Повторно проверьте обновление перед установкой');
        }

        $expectedPackageSha256 = strtolower(trim($expectedPackageSha256));
        if (preg_match('/^[0-9a-f]{64}$/', $expectedPackageSha256) !== 1) {
            throw new InvalidArgumentException('Повторно проверьте обновление перед установкой');
        }

        $this->ensureAutomaticAccess();
        $state = $this->snapshot($actorId);
        if (empty($state['operator_ready'])) {
            throw new DomainException(
                'Установка обновления недоступна, пока не устранены ошибки локальной готовности',
                503
            );
        }

        $command = [
            UpdatePhpCli::resolve(),
            $this->appRoot . '/bin/update_run.php',
            '--yes',
            '--json',
            '--expected-version-code=' . $expectedTargetVersionCode,
            '--expected-package-sha256=' . $expectedPackageSha256,
        ];

        if (PHP_SAPI !== 'cli') {
            @ignore_user_abort(true);
            @set_time_limit(0);
        }

        $process = $this->runProcess($command, 3600);
        $payload = $this->decodeProcessPayload($process['stdout']);

        if ($process['code'] !== 0) {
            $message = is_string($payload['message'] ?? null) && trim((string) $payload['message']) !== ''
                ? trim((string) $payload['message'])
                : 'Установка обновления завершилась ошибкой';

            if (($payload['code'] ?? '') === 'apply_failed_recovered'
                && !empty($payload['automatic_recovery'])) {
                $message = 'Обновление не установлено. Рабочая версия автоматически восстановлена и проверена.';
            }

            throw new RuntimeException($message, $process['code'] > 0 ? $process['code'] : 1);
        }

        if (($payload['status'] ?? '') !== 'committed') {
            throw new RuntimeException('Обновлятор не подтвердил завершение транзакции');
        }
        if ((int) ($payload['target_version_code'] ?? 0) !== $expectedTargetVersionCode) {
            throw new RuntimeException('Обновлятор вернул другую целевую версию');
        }
        if (!hash_equals(
            $expectedPackageSha256,
            strtolower((string) ($payload['package_sha256'] ?? ''))
        )) {
            throw new RuntimeException('Обновлятор вернул другой SHA-256 пакета');
        }

        $apply = is_array($payload['apply'] ?? null) ? $payload['apply'] : [];

        return [
            'status' => 'committed',
            'transaction_id' => (string) ($payload['transaction_id'] ?? ''),
            'target_version' => (string) ($payload['target_version'] ?? ''),
            'target_version_code' => (int) ($payload['target_version_code'] ?? 0),
            'package_sha256' => strtolower((string) ($payload['package_sha256'] ?? '')),
            'installed_version' => (string) ($apply['installed_version'] ?? ($payload['target_version'] ?? '')),
        ];
    }

    /** @param list<string> $command @return array{code:int,stdout:string,stderr:string} */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        if ($this->processInvoker !== null) {
            return ($this->processInvoker)($command, $this->appRoot, $timeoutSeconds);
        }

        return (new UpdateProcessRunner())->run($command, $this->appRoot, $timeoutSeconds);
    }

    /** @return array<string,mixed> */
    private function decodeProcessPayload(string $stdout): array
    {
        try {
            $payload = json_decode(trim($stdout), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Обновлятор вернул некорректный JSON-ответ');
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('Обновлятор вернул некорректный JSON-ответ');
        }

        return $payload;
    }

    /** @return array{0:bool,1:bool,2:string} */
    private function accessState(string $feedUrl): array
    {
        if ($this->transport !== null) {
            return [true, false, 'test'];
        }

        try {
            $mode = UpdateDownloadCredentials::accessMode();
        } catch (\Throwable) {
            return [false, false, 'invalid'];
        }

        if ($mode === 'offline') {
            return [true, false, $mode];
        }

        try {
            $credentials = UpdateDownloadCredentials::fromEnvironment();
            if ($credentials !== null) {
                $credentials->headersFor($feedUrl);
                $installationId = (new LicenseService())->installationId();
                if (hash_equals($installationId, $credentials->installationId())) {
                    return [true, false, $mode];
                }
            }
        } catch (\Throwable) {
            // Небезопасный путь из старого .env, отсутствующий или повреждённый
            // credential не требует действий пользователя: 1.0.2 восстановит его.
        }

        try {
            $license = (new LicenseService())->status();
            return [false, !empty($license['valid']), $mode];
        } catch (\Throwable) {
            return [false, false, $mode];
        }
    }

    private function ensureAutomaticAccess(): void
    {
        if ($this->transport !== null) {
            return;
        }
        if (UpdateDownloadCredentials::accessMode() === 'offline') {
            return;
        }

        (new LicenseService())->ensureUpdateAccess();
    }

    private function delivery(): UpdateRemoteDelivery
    {
        $transport = $this->transport;
        if ($transport === null) {
            $transport = new UpdateCredentialRefreshingTransport(
                fn (): UpdateRemoteTransport => UpdateHttpsTransport::fromEnvironment(
                    self::UI_CONNECT_TIMEOUT_SECONDS,
                    self::UI_READ_TIMEOUT_SECONDS
                ),
                static function (): void {
                    (new LicenseService())->refreshUpdateAccess();
                }
            );
        }

        return new UpdateRemoteDelivery(
            $this->appRoot,
            $this->verifier,
            $transport,
            new UpdatePackageStager($this->appRoot),
            new UpdateArchiveInspector()
        );
    }

    private function feedUrl(): string
    {
        return UpdateAccessBootstrap::feedUrl();
    }

    private function feedUrlOrFail(): string
    {
        $value = $this->feedUrl();
        if ($value === '') {
            throw new RuntimeException('Не удалось определить канал обновлений');
        }
        return $value;
    }

    private function channel(): string
    {
        try {
            return UpdateAccessBootstrap::channel();
        } catch (\Throwable) {
            return '';
        }
    }

    private function channelOrFail(): string
    {
        $value = $this->channel();
        if (!in_array($value, ['alpha', 'beta', 'stable'], true)) {
            throw new RuntimeException('Канал обновлений должен быть alpha, beta или stable');
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
