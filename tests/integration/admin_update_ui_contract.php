<?php

declare(strict_types=1);

use App\Services\AdminUpdateService;
use App\Services\PermissionService;
use Core\DatabaseManager;
use Core\UpdateManifestVerifier;
use Core\UpdateRemoteTransport;
use Core\Version;

$root = dirname(__DIR__, 2);
require_once $root . '/core/Version.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/services/PermissionService.php';
require_once $root . '/modules/admin/services/AdminUpdateService.php';

function adminUpdateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function adminUpdateRemoveTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            adminUpdateRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param list<array{name:string,data?:string,directory?:bool}> $entries */
function adminUpdateBuildZip(array $entries): string
{
    $body = '';
    $central = '';
    $count = 0;
    foreach ($entries as $spec) {
        $name = $spec['name'];
        $directory = (bool) ($spec['directory'] ?? str_ends_with($name, '/'));
        $data = $directory ? '' : (string) ($spec['data'] ?? '');
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $size = strlen($data);
        $offset = strlen($body);
        $external = (($directory ? 0040755 : 0100644) << 16);
        $versionMade = ((3 << 8) | 20);

        $body .= "PK\x03\x04" . pack(
            'vvvvvVVVvv',
            20,
            0,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        ) . $name . $data;

        $central .= "PK\x01\x02" . pack(
            'vvvvvvVVVvvvvvVV',
            $versionMade,
            20,
            0,
            0,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            $external,
            $offset
        ) . $name;
        $count++;
    }

    $centralOffset = strlen($body);
    return $body . $central . "PK\x05\x06" . pack(
        'vvvvVVv',
        0,
        0,
        $count,
        $count,
        strlen($central),
        $centralOffset,
        0
    );
}

/** @param array<string,mixed> $manifest @return array{bytes:string,token:string} */
function adminUpdateSignManifest(array $manifest, string $secret, string $keyId): array
{
    $bytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $token = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode(
            sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $bytes, $secret)
        );
    return ['bytes' => $bytes, 'token' => $token];
}

final class AdminUpdateFakeDb extends DatabaseManager
{
    public function __construct()
    {
    }

    public function fetchOne(string $query, array $params = []): ?array
    {
        if (str_contains($query, 'FROM users WHERE id')) {
            return ['is_active' => 1, 'account_status' => 'active'];
        }
        return null;
    }

    public function fetchValue(string $query, array $params = []): mixed
    {
        if (str_contains($query, 'FROM permissions WHERE code')) {
            return 1;
        }
        if (str_contains($query, 'r.code = :role')) {
            return ((int) ($params[':user_id'] ?? 0) === 42 && ($params[':role'] ?? '') === 'superadmin') ? 1 : null;
        }
        if (str_contains($query, 'JOIN role_permissions')) {
            return 1;
        }
        return null;
    }
}

final class AdminUpdateFakeTransport implements UpdateRemoteTransport
{
    /** @var array<string,string> */
    public array $text = [];
    /** @var array<string,string> */
    public array $downloads = [];
    /** @var list<string> */
    public array $downloadCalls = [];

    public function fetchText(string $url, int $maxBytes): string
    {
        if (!isset($this->text[$url])) {
            throw new RuntimeException('fake admin update response missing');
        }
        $bytes = $this->text[$url];
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('fake admin update response exceeds limit');
        }
        return $bytes;
    }

    public function downloadExact(
        string $url,
        string $destination,
        int $expectedBytes,
        string $expectedSha256
    ): array {
        $this->downloadCalls[] = $url;
        if (!isset($this->downloads[$url])) {
            throw new RuntimeException('fake admin update package missing');
        }
        $bytes = $this->downloads[$url];
        if (strlen($bytes) !== $expectedBytes || !hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('fake admin update package violates signed contract');
        }
        if (file_put_contents($destination, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('fake admin update package write failed');
        }
        @chmod($destination, 0600);
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}

adminUpdateAssert(extension_loaded('sodium'), 'sodium is required');
adminUpdateAssert(extension_loaded('openssl'), 'openssl is required');

$temp = sys_get_temp_dir() . '/wo-admin-update-ui-' . bin2hex(random_bytes(6));
adminUpdateAssert(mkdir($temp, 0700, true), 'cannot create admin update temp root');
$previousFeed = getenv('UPDATE_FEED_URL');
$previousChannel = getenv('UPDATE_CHANNEL');
$previousAccessMode = getenv('UPDATE_ACCESS_MODE');
$previousStage = getenv('UPDATE_STAGING_PATH');
$previousPrivate = getenv('PRIVATE_STORAGE_PATH');
$previousState = getenv('UPDATE_STATE_PATH');
$previousBackup = getenv('UPDATE_BACKUP_PATH');
$previousRelease = getenv('UPDATE_RELEASE_PATH');
$previousDbUser = getenv('DBUSER');
$previousDbName = getenv('DBNAME');

try {
    $feedUrl = 'https://updates.example.test/stable/feed.json';
    $manifestName = 'workspace-organizer.update.json';
    $signatureName = 'workspace-organizer.update.sig';
    $manifestUrl = 'https://updates.example.test/stable/' . $manifestName;
    $signatureUrl = 'https://updates.example.test/stable/' . $signatureName;
    $packageName = 'workspace-organizer-v1.0.0-admin-test.zip';
    $packageUrl = 'https://updates.example.test/stable/' . $packageName;
    $packageBytes = adminUpdateBuildZip([
        ['name' => 'workspace-organizer-v1.0.0-admin-test/', 'directory' => true],
        ['name' => 'workspace-organizer-v1.0.0-admin-test/index.php', 'data' => "<?php echo 'admin-update';\n"],
        ['name' => 'workspace-organizer-v1.0.0-admin-test/core/', 'directory' => true],
        ['name' => 'workspace-organizer-v1.0.0-admin-test/core/Version.php', 'data' => "<?php echo 'version';\n"],
    ]);

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $keyId = 'admin-update-contract';
    $manifest = [
        'schema' => UpdateManifestVerifier::MANIFEST_SCHEMA,
        'product' => 'workspace-organizer',
        'version' => '1.0.0-admin-test',
        'version_code' => Version::VERSION_CODE + 100,
        'channel' => 'stable',
        'issued_at' => time() - 5,
        'source_commit' => str_repeat('c', 40),
        'min_source_version_code' => Version::VERSION_CODE,
        'requires_php' => '8.1.0',
        'package' => [
            'filename' => $packageName,
            'sha256' => hash('sha256', $packageBytes),
            'size' => strlen($packageBytes),
            'format' => 'zip',
        ],
        'notes' => 'Admin update UI contract fixture',
    ];
    $signed = adminUpdateSignManifest($manifest, $secret, $keyId);
    $manifestBytes = $signed['bytes'];
    $signatureToken = $signed['token'];
    $feedBytes = json_encode([
        'schema' => 1,
        'product' => 'workspace-organizer',
        'channel' => 'stable',
        'manifest' => $manifestName,
        'signature' => $signatureName,
        'package' => 'unsigned-feed-pointer-must-not-win.zip',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    putenv('UPDATE_FEED_URL=' . $feedUrl);
    putenv('UPDATE_CHANNEL=stable');
    putenv('UPDATE_ACCESS_MODE=offline');
    putenv('UPDATE_STAGING_PATH=' . $temp . '/stage');
    putenv('PRIVATE_STORAGE_PATH=' . $temp . '/private');
    putenv('UPDATE_STATE_PATH=' . $temp . '/state');
    putenv('UPDATE_BACKUP_PATH=' . $temp . '/backups');
    putenv('UPDATE_RELEASE_PATH=' . $temp . '/releases');
    putenv('DBUSER=admin-update-contract');
    putenv('DBNAME=admin-update-contract');

    $transport = new AdminUpdateFakeTransport();
    $transport->text = [
        $feedUrl => $feedBytes,
        $manifestUrl => $manifestBytes,
        $signatureUrl => $signatureToken . PHP_EOL,
    ];
    $transport->downloads[$packageUrl] = $packageBytes;

    $verifier = new UpdateManifestVerifier([
        $keyId => UpdateManifestVerifier::base64UrlEncode($public),
    ]);
    $permissions = new PermissionService(new AdminUpdateFakeDb());
    $capturedApplyCommands = [];
    $processInvoker = static function (array $command, string $cwd, int $timeout) use (&$capturedApplyCommands): array {
        $capturedApplyCommands[] = [
            'command' => $command,
            'cwd' => $cwd,
            'timeout' => $timeout,
        ];

        $versionCode = 0;
        $sha256 = '';
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--expected-version-code=')) {
                $versionCode = (int) substr($argument, strlen('--expected-version-code='));
            }
            if (str_starts_with($argument, '--expected-package-sha256=')) {
                $sha256 = substr($argument, strlen('--expected-package-sha256='));
            }
        }

        return [
            'code' => 0,
            'stderr' => '',
            'stdout' => json_encode([
                'status' => 'committed',
                'transaction_id' => 'update-admin-ui-contract',
                'target_version' => '1.0.3-admin-test',
                'target_version_code' => $versionCode,
                'package_sha256' => $sha256,
                'apply' => [
                    'installed_version' => '1.0.3-admin-test',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    };
    $service = new AdminUpdateService($permissions, $verifier, $transport, $processInvoker);

    $snapshot = $service->snapshot(42);
    adminUpdateAssert(($snapshot['can_check'] ?? false) === true, 'superadmin cannot check configured signed feed');
    adminUpdateAssert(($snapshot['can_stage'] ?? false) === true, 'superadmin cannot stage configured signed update');
    adminUpdateAssert(($snapshot['can_apply'] ?? false) === true, 'superadmin cannot apply configured signed update from Admin UI');
    adminUpdateAssert(($snapshot['operator_ready'] ?? false) === true, 'configured updater is not operator-ready');
    adminUpdateAssert(($snapshot['operator_command'] ?? '') === 'php bin/update_run.php --yes --json', 'operator command changed unexpectedly');
    adminUpdateAssert(($snapshot['feed_label'] ?? '') === 'updates.example.test/stable/feed.json', 'feed label exposes unexpected data');
    adminUpdateAssert(!str_contains((string) $snapshot['feed_label'], 'https://'), 'feed label should be display-only host/path');

    $check = $service->check(42);
    adminUpdateAssert(($check['status'] ?? '') === 'update_available', 'admin service did not report signed update');
    adminUpdateAssert(($check['package_downloaded'] ?? true) === false, 'admin check downloaded package bytes');
    adminUpdateAssert($transport->downloadCalls === [], 'admin check invoked package transport');
    $reviewedVersionCode = (int) ($check['target_version_code'] ?? 0);
    $reviewedPackageSha256 = (string) ($check['package_sha256'] ?? '');
    adminUpdateAssert($reviewedVersionCode === $manifest['version_code'], 'reviewed target version binding changed');
    adminUpdateAssert(hash_equals($manifest['package']['sha256'], $reviewedPackageSha256), 'reviewed package hash binding changed');

    $applied = $service->apply(42, $reviewedVersionCode, $reviewedPackageSha256);
    adminUpdateAssert(($applied['status'] ?? '') === 'committed', 'admin UI apply did not return committed status');
    adminUpdateAssert(($applied['target_version_code'] ?? 0) === $reviewedVersionCode, 'admin UI apply lost reviewed version binding');
    adminUpdateAssert(hash_equals($reviewedPackageSha256, (string) ($applied['package_sha256'] ?? '')), 'admin UI apply lost reviewed SHA-256 binding');
    adminUpdateAssert(count($capturedApplyCommands) === 1, 'admin UI apply did not start exactly one updater process');
    $captured = $capturedApplyCommands[0]['command'];
    adminUpdateAssert(is_array($captured) && count($captured) >= 6, 'admin UI apply argv is incomplete');
    adminUpdateAssert(
        str_ends_with(str_replace('\\', '/', (string) ($captured[1] ?? '')), '/bin/update_run.php'),
        'admin UI apply does not reuse bin/update_run.php'
    );
    adminUpdateAssert(in_array('--yes', $captured, true), 'admin UI apply lost destructive confirmation flag');
    adminUpdateAssert(in_array('--json', $captured, true), 'admin UI apply lost JSON contract');
    adminUpdateAssert(
        in_array('--expected-version-code=' . $reviewedVersionCode, $captured, true),
        'admin UI apply does not bind reviewed version code'
    );
    adminUpdateAssert(
        in_array('--expected-package-sha256=' . $reviewedPackageSha256, $captured, true),
        'admin UI apply does not bind reviewed package SHA-256'
    );
    adminUpdateAssert(($capturedApplyCommands[0]['timeout'] ?? 0) === 3600, 'admin UI apply timeout boundary changed');

    $oneClick = $service->applyLatest(42);
    adminUpdateAssert(($oneClick['status'] ?? '') === 'committed', 'one-click apply did not commit latest signed update');
    adminUpdateAssert(
        ($oneClick['target_version_code'] ?? 0) === $reviewedVersionCode,
        'one-click apply selected unexpected target version'
    );
    adminUpdateAssert(
        hash_equals($reviewedPackageSha256, (string) ($oneClick['package_sha256'] ?? '')),
        'one-click apply selected unexpected package SHA-256'
    );
    adminUpdateAssert(
        count($capturedApplyCommands) === 2,
        'one-click apply did not start exactly one additional updater process'
    );

    $ordinarySnapshot = $service->snapshot(43);
    adminUpdateAssert(($ordinarySnapshot['can_check'] ?? false) === true, 'settings manager cannot perform read-only update check');
    adminUpdateAssert(($ordinarySnapshot['can_stage'] ?? true) === false, 'non-superadmin was allowed installation-wide staging');
    adminUpdateAssert(($ordinarySnapshot['can_apply'] ?? true) === false, 'non-superadmin was allowed installation-wide apply');
    $applyCountBeforeRejectedAttempt = count($capturedApplyCommands);
    $ordinaryApplyRejected = false;
    try {
        $service->apply(43, $reviewedVersionCode, $reviewedPackageSha256);
    } catch (DomainException $e) {
        $ordinaryApplyRejected = $e->getCode() === 403;
    }
    adminUpdateAssert($ordinaryApplyRejected, 'non-superadmin apply action was not rejected');
    adminUpdateAssert(
        count($capturedApplyCommands) === $applyCountBeforeRejectedAttempt,
        'rejected apply started updater process'
    );

    $ordinaryLatestRejected = false;
    try {
        $service->applyLatest(43);
    } catch (DomainException $e) {
        $ordinaryLatestRejected = $e->getCode() === 403;
    }
    adminUpdateAssert($ordinaryLatestRejected, 'non-superadmin one-click apply action was not rejected');
    adminUpdateAssert(
        count($capturedApplyCommands) === $applyCountBeforeRejectedAttempt,
        'rejected one-click apply started updater process'
    );

    $ordinaryStageRejected = false;
    try {
        $service->stage(43, $reviewedVersionCode, $reviewedPackageSha256);
    } catch (DomainException $e) {
        $ordinaryStageRejected = $e->getCode() === 403;
    }
    adminUpdateAssert($ordinaryStageRejected, 'non-superadmin stage action was not rejected');
    adminUpdateAssert($transport->downloadCalls === [], 'rejected stage downloaded package bytes');

    // TOCTOU guard: if the signed feed advances after the operator reviewed the
    // first release, staging must fail before any package bytes are downloaded.
    $advanced = $manifest;
    $advanced['version'] = '1.0.0-admin-test-next';
    $advanced['version_code'] = $manifest['version_code'] + 1;
    $advanced['source_commit'] = str_repeat('d', 40);
    $advancedSigned = adminUpdateSignManifest($advanced, $secret, $keyId);
    $transport->text[$manifestUrl] = $advancedSigned['bytes'];
    $transport->text[$signatureUrl] = $advancedSigned['token'] . PHP_EOL;
    $feedAdvanceRejected = false;
    try {
        $service->stage(42, $reviewedVersionCode, $reviewedPackageSha256);
    } catch (Throwable $e) {
        $feedAdvanceRejected = str_contains(strtolower($e->getMessage()), 'changed since operator confirmation');
    }
    adminUpdateAssert($feedAdvanceRejected, 'admin staging accepted a feed that changed after review');
    adminUpdateAssert($transport->downloadCalls === [], 'changed feed downloaded package bytes before binding rejection');

    // Restore the exact signed release the operator reviewed, then staging may proceed.
    $transport->text[$manifestUrl] = $manifestBytes;
    $transport->text[$signatureUrl] = $signatureToken . PHP_EOL;
    $staged = $service->stage(42, $reviewedVersionCode, $reviewedPackageSha256);
    adminUpdateAssert(($staged['status'] ?? '') === 'staged', 'admin service did not publish immutable stage');
    adminUpdateAssert($transport->downloadCalls === [$packageUrl], 'admin stage downloaded unexpected package URL');
    adminUpdateAssert(is_dir((string) ($staged['stage_dir'] ?? '')), 'admin immutable stage directory missing');
    adminUpdateAssert(
        is_file((string) $staged['stage_dir'] . '/' . $packageName),
        'admin staged signed package missing'
    );

    $controllerSource = (string) file_get_contents($root . '/modules/admin/controllers/UpdateController.php');
    $serviceSource = (string) file_get_contents($root . '/modules/admin/services/AdminUpdateService.php');
    $viewSource = (string) file_get_contents($root . '/modules/admin/views/updates.php');
    $routerSource = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');

    adminUpdateAssert(!str_contains($controllerSource, "'stage_dir' =>"), 'admin controller persists/displays absolute stage path');
    adminUpdateAssert(str_contains($controllerSource, 'STAGE_BINDING_SESSION_KEY'), 'admin controller lost server-side reviewed release binding');
    adminUpdateAssert(
        str_contains($controllerSource, '$request->unsetSession(self::STAGE_BINDING_SESSION_KEY)'),
        'admin reviewed release binding is not one-shot'
    );
    adminUpdateAssert(str_contains($serviceSource, 'UpdateProcessRunner'), 'admin apply does not reuse bounded argv process runner');
    adminUpdateAssert(!str_contains($serviceSource, 'UpdateApplyCommand'), 'admin service bypasses the operator flow and reaches UpdateApplyCommand directly');
    adminUpdateAssert(!str_contains($serviceSource, 'UpdateLiveApplier'), 'admin service bypasses the operator flow and reaches live code applier directly');
    adminUpdateAssert(!str_contains($serviceSource, 'UpdateBackupManager'), 'admin service bypasses the operator flow and reaches backup mutation directly');
    adminUpdateAssert(str_contains($serviceSource, 'expectedTargetVersionCode'), 'admin service lost reviewed target version binding');
    adminUpdateAssert(str_contains($serviceSource, 'expectedPackageSha256'), 'admin service lost reviewed package hash binding');
    adminUpdateAssert(str_contains($serviceSource, 'public function applyLatest'), 'admin service lost one-click update entrypoint');
    adminUpdateAssert(str_contains($controllerSource, 'public function status'), 'admin controller lost background update status');
    adminUpdateAssert(str_contains($controllerSource, 'public function applyLatest'), 'admin controller lost one-click update action');
    adminUpdateAssert(str_contains($viewSource, "route('admin_updates_check')"), 'admin update check action is missing');
    adminUpdateAssert(str_contains($viewSource, "route('admin_updates_stage')"), 'admin update stage action is missing');
    adminUpdateAssert(str_contains($viewSource, '$view->csrfInput()'), 'admin update forms lost CSRF token');
    adminUpdateAssert(str_contains($viewSource, "route('admin_updates_apply')"), 'admin update view does not expose reviewed web apply action');
    adminUpdateAssert(str_contains($viewSource, 'Установить обновление'), 'admin update view lost install action copy');
    adminUpdateAssert(str_contains($viewSource, 'bin/update_run.php --recover'), 'admin update view lost operator recovery guidance');
    adminUpdateAssert(str_contains($viewSource, '$operatorReady'), 'admin update view lost operator readiness state');
    adminUpdateAssert(
        str_contains($viewSource, 'admin-update-operator__body'),
        'admin update view lost compact operator body'
    );
    adminUpdateAssert(
        str_contains($viewSource, 'admin-update-command-grid'),
        'admin update view lost compact CLI command grid'
    );
    adminUpdateAssert(
        str_contains($viewSource, 'admin-update-safety'),
        'admin update view lost compact safety section'
    );
    adminUpdateAssert(!str_contains($viewSource, 'stage_dir'), 'admin update view exposes absolute stage path');
    adminUpdateAssert(str_contains($routerSource, "->add('GET', '/updates/status'"), 'background update status route must remain GET/read-only');
    adminUpdateAssert(str_contains($routerSource, "->add('GET', '/updates/check'"), 'admin signed-feed check must remain GET/read-only');
    adminUpdateAssert(str_contains($routerSource, "->add('POST', '/updates/apply-latest'"), 'one-click apply route must be POST');
    adminUpdateAssert(
        str_contains($routerSource, "[LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_apply_latest'"),
        'one-click apply route lost login/settings/CSRF middleware chain'
    );
    adminUpdateAssert(str_contains($routerSource, "->add('POST', '/updates/stage'"), 'admin stage route must remain POST');
    adminUpdateAssert(
        str_contains($routerSource, "[LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_stage'"),
        'admin stage route lost login/settings/CSRF middleware chain'
    );
    adminUpdateAssert(str_contains($routerSource, "->add('POST', '/updates/apply'"), 'admin apply route must be POST');
    adminUpdateAssert(
        str_contains($routerSource, "[LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_apply'"),
        'admin apply route lost login/settings/CSRF middleware chain'
    );
    adminUpdateAssert(str_contains($controllerSource, 'APPLY_BINDING_SESSION_KEY'), 'admin apply lost server-side reviewed release binding');
    adminUpdateAssert(
        str_contains($controllerSource, '$request->unsetSession(self::APPLY_BINDING_SESSION_KEY)'),
        'admin apply binding is not one-shot'
    );

    $operatorSource = (string) file_get_contents($root . '/bin/update_run.php');
    adminUpdateAssert(str_contains($operatorSource, "'expected-version-code:'"), 'operator flow lost reviewed version argument');
    adminUpdateAssert(str_contains($operatorSource, "'expected-package-sha256:'"), 'operator flow lost reviewed SHA-256 argument');
    adminUpdateAssert(
        str_contains($operatorSource, 'Подписанный канал изменился после подтверждения обновления'),
        'operator flow lost pre-maintenance review binding check'
    );

    sodium_memzero($secret);
    sodium_memzero($pair);
    echo "[OK] admin signed-update UI contract\n";
} finally {
    adminUpdateRemoveTree($temp);
    foreach ([
        'UPDATE_FEED_URL' => $previousFeed,
        'UPDATE_CHANNEL' => $previousChannel,
        'UPDATE_ACCESS_MODE' => $previousAccessMode,
        'UPDATE_STAGING_PATH' => $previousStage,
        'PRIVATE_STORAGE_PATH' => $previousPrivate,
        'UPDATE_STATE_PATH' => $previousState,
        'UPDATE_BACKUP_PATH' => $previousBackup,
        'UPDATE_RELEASE_PATH' => $previousRelease,
        'DBUSER' => $previousDbUser,
        'DBNAME' => $previousDbName,
    ] as $name => $value) {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }
}
