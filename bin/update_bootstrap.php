<?php

declare(strict_types=1);

$runnerRoot = dirname(__DIR__);
require_once $runnerRoot . '/core/UpdateExternalCliSupport.php';
require_once $runnerRoot . '/core/UpdateBootstrapSupport.php';
require_once $runnerRoot . '/core/Environment.php';
require_once $runnerRoot . '/core/UpdateManifestVerifier.php';
require_once $runnerRoot . '/core/UpdatePackageStager.php';
require_once $runnerRoot . '/core/UpdateArchiveInspector.php';
require_once $runnerRoot . '/core/UpdateTransactionJournal.php';
require_once $runnerRoot . '/core/UpdateTransactionStateMachine.php';
require_once $runnerRoot . '/core/UpdateBackupManager.php';
require_once $runnerRoot . '/core/UpdateReleaseCandidate.php';
require_once $runnerRoot . '/core/UpdateLiveApplier.php';
require_once $runnerRoot . '/core/UpdateApplyOperationLock.php';
require_once $runnerRoot . '/core/UpdateRollbackCodeRestorer.php';
require_once $runnerRoot . '/app/services/MaintenanceModeService.php';
require_once $runnerRoot . '/core/UpdateApplyCommand.php';

use App\Services\MaintenanceModeService;
use Core\Environment;
use Core\UpdateApplyCommand;
use Core\UpdateApplyException;
use Core\UpdateArchiveInspector;
use Core\UpdateBackupManager;
use Core\UpdateBootstrapSupport;
use Core\UpdateExternalCliSupport;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateReleaseCandidate;
use Core\UpdateTransactionJournal;

UpdateExternalCliSupport::assertCli('Доверенный bootstrap-обновлятор доступен только из CLI.');

$options = getopt('', [
    'app-root:',
    'manifest:',
    'signature:',
    'package:',
    'transaction:',
    'stage-root:',
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'expected-source-version:',
    'expected-source-version-code:',
    'recover',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Доверенный внешний bootstrap-обновлятор Workspace Organizer\n\n";
    echo "Используйте его только для установленной версии, которая старше подписанного runtime обновлятора.\n";
    echo "Сам runner должен поставляться отдельным доверенным пакетом релиза и находиться вне live-tree.\n\n";
    echo "Обновление точно указанной старой установки:\n";
    echo "  php bin/update_bootstrap.php --app-root=/srv/workspace \\\n";
    echo "      --manifest=/secure/release/update.json --signature=/secure/release/update.sig \\\n";
    echo "      --package=/secure/release/workspace-organizer.zip \\\n";
    echo "      --transaction=update-beta4-to-1-0 \\\n";
    echo "      --expected-source-version=0.14.0-beta.4 --expected-source-version-code=1404 \\\n";
    echo "      --stage-root=/var/lib/notes/update-staging --state-root=/var/lib/notes/update-state \\\n";
    echo "      --backup-root=/var/lib/notes/update-backups --candidate-root=/var/lib/notes/update-releases\n\n";
    echo "Восстановление прерванной bootstrap-транзакции:\n";
    echo "  php bin/update_bootstrap.php --app-root=/srv/workspace \\\n";
    echo "      --transaction=update-beta4-to-1-0 --state-root=/var/lib/notes/update-state \\\n";
    echo "      --backup-root=/var/lib/notes/update-backups --recover\n\n";
    echo "Приватный ключ подписи команда не принимает: доверие определяется только реестром публичных ключей runner.\n";
    exit(0);
}

$json = isset($options['json']);
$cli = new UpdateExternalCliSupport($runnerRoot, $json);
$bootstrap = new UpdateBootstrapSupport();
$appRoot = $cli->applicationRoot(
    $options['app-root'] ?? '',
    'unsafe_bootstrap_layout',
    'invalid_app_root',
    'Bootstrap-runner'
);
$transactionId = $cli->transactionId($options['transaction'] ?? '');

try {
    Environment::load($appRoot . '/.env');
} catch (\Throwable $e) {
    $cli->fail(
        'Не удалось загрузить окружение live-установки: ' . $e->getMessage(),
        'environment_failed',
        2
    );
}

$stateRoot = $cli->configuredRoot(
    $options['state-root'] ?? '',
    'UPDATE_STATE_PATH',
    'updates'
);
$backupRoot = $cli->configuredRoot(
    $options['backup-root'] ?? '',
    'UPDATE_BACKUP_PATH',
    'update-backups'
);

if (isset($options['recover'])) {
    try {
        $result = (new UpdateApplyCommand($appRoot, $json))->execute([
            'transaction' => $transactionId,
            'state-root' => $stateRoot,
            'backup-root' => $backupRoot,
            'recover' => true,
        ]);
        $bootstrap->printResult($cli, $result + ['bootstrap_runner' => true]);
        exit(0);
    } catch (UpdateApplyException $e) {
        $cli->fail($e->getMessage(), $e->errorCode, $e->exitCode, $e->details);
    } catch (\Throwable $e) {
        $cli->fail($e->getMessage(), 'bootstrap_failed');
    }
}

$expectedSourceVersion = trim((string) ($options['expected-source-version'] ?? ''));
$expectedSourceCodeRaw = trim((string) ($options['expected-source-version-code'] ?? ''));
if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $expectedSourceVersion) !== 1) {
    $cli->fail(
        '--expected-source-version обязателен и должен содержать корректную версию',
        'invalid_source_version',
        2
    );
}
if (preg_match('/^[1-9][0-9]{0,9}$/', $expectedSourceCodeRaw) !== 1) {
    $cli->fail(
        '--expected-source-version-code обязателен и должен быть положительным числом',
        'invalid_source_version',
        2
    );
}
$expectedSourceCode = (int) $expectedSourceCodeRaw;

// Версия привязывается только к проверенной live-установке. Runner-версия до
// этого места не загружается, поэтому защита updater_version_mismatch остаётся
// связана с фактической исходной установкой.
$source = $bootstrap->bindSourceVersion(
    $appRoot,
    $expectedSourceVersion,
    $expectedSourceCode,
    $cli
);
$sourceVersion = $source['version'];
$sourceVersionCode = $source['version_code'];

$manifestPath = $cli->localFile(
    $options['manifest'] ?? '',
    'Манифест',
    UpdateManifestVerifier::MAX_MANIFEST_BYTES
);
$signaturePath = $cli->localFile($options['signature'] ?? '', 'Подпись', 2048);
$packagePath = $cli->localFile($options['package'] ?? '', 'Пакет');
$stageRoot = $cli->configuredRoot(
    $options['stage-root'] ?? '',
    'UPDATE_STAGING_PATH',
    'update-staging'
);
$candidateRoot = $cli->configuredRoot(
    $options['candidate-root'] ?? '',
    'UPDATE_RELEASE_PATH',
    'update-releases'
);

$manifestBytes = file_get_contents($manifestPath);
$signatureToken = file_get_contents($signaturePath);
if (!is_string($manifestBytes) || !is_string($signatureToken)) {
    $cli->fail('Не удалось прочитать манифест обновления или подпись', 'read_failed');
}
$signatureToken = trim($signatureToken);

$maintenance = null;
$journal = null;
$maintenanceOwned = false;

try {
    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        throw new \RuntimeException(
            'Во внешнем bootstrap-runner не настроены доверенные публичные ключи обновления'
        );
    }

    $verification = $verifier->verify($manifestBytes, $signatureToken);
    if (!($verification['valid'] ?? false) || !is_array($verification['manifest'] ?? null)) {
        throw new \RuntimeException(
            (string) ($verification['message'] ?? 'Проверка подписи манифеста обновления не пройдена')
        );
    }
    $manifest = $verification['manifest'];

    $stager = new UpdatePackageStager($appRoot);
    $stager->assertCompatibility($manifest, $sourceVersionCode, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);
    $archive = (new UpdateArchiveInspector())->inspect($packagePath);

    $staged = $stager->stage(
        $manifest,
        $manifestBytes,
        $signatureToken,
        $packagePath,
        $stageRoot,
        $sourceVersionCode,
        PHP_VERSION
    );

    $maintenance = new MaintenanceModeService($stateRoot, $appRoot);
    $maintenance->enter(
        $transactionId,
        'Bootstrap-обновление с ' . $sourceVersion . ' до ' . (string) $manifest['version']
    );
    $maintenanceOwned = true;

    $journal = new UpdateTransactionJournal($stateRoot, $appRoot);
    $journal->initialize([
        'transaction_id' => $transactionId,
        'installed_version' => $sourceVersion,
        'installed_version_code' => $sourceVersionCode,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'package_sha256' => $package['sha256'],
        'stage_dir' => $staged['stage_dir'],
    ]);

    $db = $bootstrap->database();
    try {
        $backupManager = new UpdateBackupManager(
            $backupRoot,
            $appRoot,
            $bootstrap->mutablePaths()
        );
        $backups = $backupManager->create($transactionId, $db);
    } finally {
        $db->close();
    }
    $journal->recordBackups($transactionId, $backups);

    // После создания rollback-backup ещё раз проверяем неизменяемый stage.
    // Bootstrap не должен создавать более слабый обход обычного подписанного
    // конвейера обновления.
    $stageManifestPath = $staged['stage_dir'] . '/manifest.json';
    $stageSignaturePath = $staged['stage_dir'] . '/manifest.sig';
    $stageManifestBytes = file_get_contents($stageManifestPath);
    $stageSignatureToken = file_get_contents($stageSignaturePath);
    if (!is_string($stageManifestBytes) || !is_string($stageSignatureToken)) {
        throw new \RuntimeException(
            'Проверенные метаданные stage стали недоступны после создания rollback-backup'
        );
    }

    $stageVerification = $verifier->verify($stageManifestBytes, trim($stageSignatureToken));
    if (!($stageVerification['valid'] ?? false) || !is_array($stageVerification['manifest'] ?? null)) {
        throw new \RuntimeException('Повторная проверка подписи stage не пройдена');
    }
    $stageManifest = $stageVerification['manifest'];
    if (
        (int) $stageManifest['version_code'] !== (int) $manifest['version_code']
        || !hash_equals((string) $stageManifest['package']['sha256'], $package['sha256'])
    ) {
        throw new \RuntimeException('Проверенный stage больше не соответствует подписанному релизу');
    }

    $stagedPackagePath = $staged['stage_dir'] . '/' . (string) $stageManifest['package']['filename'];
    $stager->assertCompatibility($stageManifest, $sourceVersionCode, PHP_VERSION);
    $stagedPackage = $stager->verifyPackage($stageManifest, $stagedPackagePath);
    (new UpdateArchiveInspector())->inspect($stagedPackagePath);
    if (!hash_equals($package['sha256'], $stagedPackage['sha256'])) {
        throw new \RuntimeException('Хеш stage-пакета изменился после создания rollback-backup');
    }

    $candidate = (new UpdateReleaseCandidate($appRoot))->extract(
        $stagedPackagePath,
        $candidateRoot,
        $stageManifest
    );

    $applyResult = (new UpdateApplyCommand($appRoot, $json))->execute([
        'transaction' => $transactionId,
        'candidate-dir' => $candidate['candidate_dir'],
        'state-root' => $stateRoot,
        'backup-root' => $backupRoot,
        'apply' => true,
    ]);
    $maintenanceOwned = false;

    $bootstrap->printResult($cli, [
        'status' => (string) ($applyResult['status'] ?? 'committed'),
        'bootstrap_runner' => true,
        'transaction_id' => $transactionId,
        'source_version' => $sourceVersion,
        'source_version_code' => $sourceVersionCode,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'key_id' => (string) ($verification['key_id'] ?? ''),
        'package_sha256' => $package['sha256'],
        'archive_entries' => (int) ($archive['entries'] ?? 0),
        'candidate_tree_sha256' => (string) ($candidate['tree_sha256'] ?? ''),
        'maintenance_active' => (bool) ($applyResult['maintenance_active'] ?? false),
    ]);
} catch (UpdateApplyException $e) {
    $maintenanceOwned = $bootstrap->releasePreLiveMaintenance(
        $maintenance,
        $journal,
        $transactionId,
        $maintenanceOwned
    );
    $cli->fail(
        $e->getMessage(),
        $e->errorCode,
        $e->exitCode,
        array_merge($e->details, ['maintenance_active' => $maintenanceOwned])
    );
} catch (\Throwable $e) {
    $maintenanceOwned = $bootstrap->releasePreLiveMaintenance(
        $maintenance,
        $journal,
        $transactionId,
        $maintenanceOwned
    );
    $cli->fail(
        $e->getMessage(),
        'bootstrap_failed',
        1,
        ['maintenance_active' => $maintenanceOwned]
    );
}
