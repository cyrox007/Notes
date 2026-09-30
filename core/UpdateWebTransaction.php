<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;
use mysqli;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/Version.php';
require_once __DIR__ . '/UpdateVerifiedStage.php';
require_once __DIR__ . '/UpdateTransactionJournal.php';
require_once __DIR__ . '/UpdateTransactionStateMachine.php';
require_once __DIR__ . '/UpdateBackupManager.php';
require_once __DIR__ . '/UpdateReleaseCandidate.php';
require_once __DIR__ . '/UpdateCoordinatorLock.php';
require_once __DIR__ . '/UpdateWebContinuation.php';
require_once __DIR__ . '/UpdateInProcessRunner.php';
require_once __DIR__ . '/UpdateApplyCommand.php';
require_once dirname(__DIR__) . '/app/services/MaintenanceModeService.php';

/**
 * Пошаговая web-оркестрация подписанного обновления.
 *
 * Класс не содержит отдельную destructive-логику: финальное применение и
 * rollback выполняет тот же UpdateApplyCommand, что и CLI-контур. Между
 * HTTP-запросами сохраняется только проверенное состояние транзакции.
 */
final class UpdateWebTransaction
{
    private string $appRoot;
    private string $stateRoot;
    private string $backupRoot;
    private string $releaseRoot;
    private UpdateManifestVerifier $verifier;

    public function __construct(
        ?string $appRoot = null,
        ?UpdateManifestVerifier $verifier = null
    ) {
        $resolved = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('Не удалось безопасно определить корень приложения updater');
        }

        $this->appRoot = rtrim($resolved, '/\\');
        $this->verifier = $verifier ?? new UpdateManifestVerifier();
        $privateRoot = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));

        $this->stateRoot = $this->externalRoot('UPDATE_STATE_PATH', $privateRoot, 'updates');
        $this->backupRoot = $this->externalRoot('UPDATE_BACKUP_PATH', $privateRoot, 'update-backups');
        $this->releaseRoot = $this->externalRoot('UPDATE_RELEASE_PATH', $privateRoot, 'update-releases');
    }

    /**
     * @param array<string,mixed> $staged
     * @return array<string,mixed>
     */
    public function begin(
        array $staged,
        int $expectedTargetVersionCode,
        string $expectedPackageSha256
    ): array {
        $stageDir = trim((string) ($staged['stage_dir'] ?? ''));
        $verified = (new UpdateVerifiedStage($this->appRoot, $this->verifier))->inspect(
            $stageDir,
            $expectedTargetVersionCode,
            $expectedPackageSha256
        );

        $transactionId = 'web-update-'
            . gmdate('Ymd-His')
            . '-'
            . bin2hex(random_bytes(5));

        $journal = new UpdateTransactionJournal($this->stateRoot, $this->appRoot);
        $journal->initialize([
            'transaction_id' => $transactionId,
            'installed_version' => Version::VERSION,
            'installed_version_code' => Version::VERSION_CODE,
            'target_version' => $verified['target_version'],
            'target_version_code' => $verified['target_version_code'],
            'package_sha256' => $verified['package_sha256'],
            'stage_dir' => $verified['stage_dir'],
        ]);

        $continuation = new UpdateWebContinuation($this->stateRoot);
        try {
            $token = $continuation->create($transactionId);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Не удалось подготовить безопасное продолжение web-обновления',
                0,
                $e
            );
        }

        return [
            'status' => 'in_progress',
            'phase' => 'backup',
            'progress' => 20,
            'message' => 'Пакет проверен. Создаётся резервная точка.',
            'transaction_id' => $transactionId,
            'continuation_token' => $token,
            'target_version' => $verified['target_version'],
            'target_version_code' => $verified['target_version_code'],
            'package_sha256' => $verified['package_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    public function step(string $transactionId, string $token): array
    {
        $continuation = new UpdateWebContinuation($this->stateRoot);
        if (!$continuation->verify($transactionId, $token)) {
            throw new UpdateWebTransactionException(
                'Срок безопасного продолжения обновления истёк или токен недействителен',
                'continuation_invalid',
                403
            );
        }

        try {
            $coordinator = new UpdateCoordinatorLock($this->stateRoot, $transactionId);
        } catch (UpdateCoordinatorBusyException $e) {
            throw new UpdateWebTransactionException(
                'Предыдущий шаг обновления ещё выполняется',
                'operation_busy',
                409,
                $e
            );
        }

        if (!$continuation->verifyAndRenew($transactionId, $token)) {
            $coordinator->release();
            throw new UpdateWebTransactionException(
                'Срок безопасного продолжения обновления истёк',
                'continuation_expired',
                403
            );
        }

        $maintenance = new MaintenanceModeService($this->stateRoot, $this->appRoot);
        $journal = new UpdateTransactionJournal($this->stateRoot, $this->appRoot);

        try {
            $state = $journal->load($transactionId);
            $current = (string) ($state['state'] ?? '');

            return match ($current) {
                'initialized' => $this->backupStep(
                    $transactionId,
                    $state,
                    $maintenance
                ),
                'backup_verified' => $this->candidateStep(
                    $transactionId,
                    $state,
                    $maintenance
                ),
                'candidate_verified',
                'preflight_verified',
                'code_switched',
                'migrations_applied',
                'postcheck_verified' => $this->applyStep(
                    $transactionId,
                    $state,
                    $maintenance,
                    $continuation
                ),
                'committed' => $this->finishCommitted(
                    $transactionId,
                    $state,
                    $maintenance,
                    $continuation
                ),
                'rollback_verified' => $this->finishRecovered(
                    $transactionId,
                    $state,
                    $maintenance,
                    $continuation
                ),
                'live_mutation_started',
                'rollback_started',
                'code_restored',
                'database_restored',
                'rollback_failed' => $this->recoverStep(
                    $transactionId,
                    $state,
                    $maintenance,
                    $continuation
                ),
                default => throw new UpdateWebTransactionException(
                    "Неподдерживаемое состояние web-обновления: {$current}",
                    'invalid_transaction_state',
                    409
                ),
            };
        } catch (Throwable $e) {
            $this->cleanupPreMutationFailure(
                $transactionId,
                $maintenance,
                $continuation
            );
            throw $e;
        } finally {
            $coordinator->release();
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function backupStep(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance
    ): array {
        $verified = $this->verifyJournalStage($state);
        $maintenance->enter($transactionId, 'Обновление Workspace Organizer');

        $db = $this->database();
        try {
            $manager = new UpdateBackupManager(
                $this->backupRoot,
                $this->appRoot,
                $this->excludedPaths()
            );
            $backups = $manager->create($transactionId, $db);
        } finally {
            $db->close();
        }

        (new UpdateTransactionJournal($this->stateRoot, $this->appRoot))
            ->recordBackups($transactionId, $backups);

        return [
            'status' => 'in_progress',
            'phase' => 'candidate',
            'progress' => 45,
            'message' => 'Резервная точка проверена. Подготавливается новая версия.',
            'transaction_id' => $transactionId,
            'target_version' => $verified['target_version'],
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function candidateStep(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance
    ): array {
        $this->assertMaintenanceOwner($maintenance, $transactionId);
        $verified = $this->verifyJournalStage($state);

        $candidate = (new UpdateReleaseCandidate($this->appRoot))->extract(
            $verified['package_path'],
            $this->releaseRoot,
            $verified['manifest']
        );

        (new UpdateTransactionStateMachine($this->stateRoot, $this->appRoot))
            ->recordCandidate($transactionId, [
                'candidate_dir' => $candidate['candidate_dir'],
                'tree_manifest' => $candidate['tree_manifest'],
                'tree_sha256' => $candidate['tree_sha256'],
                'target_version' => $verified['target_version'],
                'target_version_code' => $verified['target_version_code'],
                'files' => $candidate['files'],
                'total_bytes' => $candidate['total_bytes'],
            ]);

        return [
            'status' => 'in_progress',
            'phase' => 'apply',
            'progress' => 70,
            'message' => 'Кандидат релиза проверен. Применяются файлы и миграции.',
            'transaction_id' => $transactionId,
            'target_version' => $verified['target_version'],
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function applyStep(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance,
        UpdateWebContinuation $continuation
    ): array {
        $this->assertMaintenanceOwner($maintenance, $transactionId);

        $candidate = is_array($state['candidate'] ?? null) ? $state['candidate'] : [];
        $candidateDir = trim((string) ($candidate['candidate_dir'] ?? ''));
        if ($candidateDir === '') {
            throw new RuntimeException('Журнал updater не содержит проверенный candidate');
        }

        try {
            $result = (new UpdateApplyCommand(
                $this->appRoot,
                true,
                new UpdateInProcessRunner()
            ))->execute([
                'transaction' => $transactionId,
                'apply' => true,
                'candidate-dir' => $candidateDir,
                'state-root' => $this->stateRoot,
                'backup-root' => $this->backupRoot,
                'single-step' => true,
            ]);
        } catch (UpdateApplyException $e) {
            $after = $maintenance->state();
            if ($e->errorCode === 'apply_rolled_back' && !$after['active']) {
                $continuation->revoke($transactionId);
                return [
                    'status' => 'recovered',
                    'phase' => 'done',
                    'progress' => 100,
                    'message' => 'Обновление не установлено. Предыдущая рабочая версия автоматически восстановлена.',
                    'transaction_id' => $transactionId,
                    'installed_version' => (string) ($state['installed_version'] ?? ''),
                    'diagnostic_code' => $this->safeDiagnosticCode(
                        $e->details['apply_error_code'] ?? null
                    ),
                ];
            }

            if (!$after['active']) {
                $continuation->revoke($transactionId);
            }

            throw new UpdateWebTransactionException(
                $e->getMessage(),
                $e->errorCode,
                500,
                $e
            );
        }

        if (($result['status'] ?? '') === 'in_progress') {
            return [
                'status' => 'in_progress',
                'phase' => (string) ($result['phase'] ?? 'apply'),
                'progress' => (int) ($result['progress'] ?? 75),
                'message' => (string) ($result['message'] ?? 'Обновление продолжается.'),
                'transaction_id' => $transactionId,
                'target_version' => (string) ($state['target_version'] ?? ''),
            ];
        }

        $continuation->revoke($transactionId);

        return [
            'status' => 'committed',
            'phase' => 'done',
            'progress' => 100,
            'message' => 'Обновление установлено и проверено.',
            'transaction_id' => $transactionId,
            'target_version' => (string) ($state['target_version'] ?? ''),
            'target_version_code' => (int) ($state['target_version_code'] ?? 0),
            'package_sha256' => (string) ($state['package_sha256'] ?? ''),
            'installed_version' => (string) ($result['installed_version'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function recoverStep(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance,
        UpdateWebContinuation $continuation
    ): array {
        $this->assertMaintenanceOwner($maintenance, $transactionId);

        $result = (new UpdateApplyCommand(
            $this->appRoot,
            true,
            new UpdateInProcessRunner()
        ))->execute([
            'transaction' => $transactionId,
            'recover' => true,
            'state-root' => $this->stateRoot,
            'backup-root' => $this->backupRoot,
        ]);

        $continuation->revoke($transactionId);
        $status = (string) ($result['status'] ?? '');

        if ($status === 'committed_recovery_verified') {
            return [
                'status' => 'committed',
                'phase' => 'done',
                'progress' => 100,
                'message' => 'Обновление установлено и автоматически доведено до рабочего состояния.',
                'transaction_id' => $transactionId,
                'target_version' => (string) ($state['target_version'] ?? ''),
                'target_version_code' => (int) ($state['target_version_code'] ?? 0),
                'package_sha256' => (string) ($state['package_sha256'] ?? ''),
                'installed_version' => (string) ($state['target_version'] ?? ''),
            ];
        }

        return [
            'status' => 'recovered',
            'phase' => 'done',
            'progress' => 100,
            'message' => 'Обновление не завершилось. Предыдущая рабочая версия автоматически восстановлена.',
            'transaction_id' => $transactionId,
            'installed_version' => (string) ($state['installed_version'] ?? ''),
            'diagnostic_code' => $this->diagnosticCodeFromJournal($state),
        ];
    }

    /** @param array<string,mixed> $state */
    private function diagnosticCodeFromJournal(array $state): ?string
    {
        $rollback = is_array($state['rollback'] ?? null) ? $state['rollback'] : [];
        $rollbackFailure = is_array($state['rollback_failure'] ?? null)
            ? $state['rollback_failure']
            : [];

        return $this->safeDiagnosticCode(
            $rollback['failure_code']
                ?? $rollbackFailure['apply_error_code']
                ?? null
        );
    }

    private function safeDiagnosticCode(mixed $value): ?string
    {
        $code = is_string($value) ? strtolower(trim($value)) : '';
        return preg_match('/^[a-z0-9_]{1,64}$/D', $code) === 1
            ? $code
            : null;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function finishCommitted(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance,
        UpdateWebContinuation $continuation
    ): array {
        if ($maintenance->state()['active']) {
            $maintenance->leave($transactionId);
        }
        $continuation->revoke($transactionId);

        return [
            'status' => 'committed',
            'phase' => 'done',
            'progress' => 100,
            'message' => 'Обновление уже установлено и проверено.',
            'transaction_id' => $transactionId,
            'target_version' => (string) ($state['target_version'] ?? ''),
            'target_version_code' => (int) ($state['target_version_code'] ?? 0),
            'package_sha256' => (string) ($state['package_sha256'] ?? ''),
            'installed_version' => (string) ($state['target_version'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function finishRecovered(
        string $transactionId,
        array $state,
        MaintenanceModeService $maintenance,
        UpdateWebContinuation $continuation
    ): array {
        if ($maintenance->state()['active']) {
            $maintenance->leave($transactionId);
        }
        $continuation->revoke($transactionId);

        return [
            'status' => 'recovered',
            'phase' => 'done',
            'progress' => 100,
            'message' => 'Предыдущая рабочая версия восстановлена.',
            'transaction_id' => $transactionId,
            'installed_version' => (string) ($state['installed_version'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function verifyJournalStage(array $state): array
    {
        return (new UpdateVerifiedStage($this->appRoot, $this->verifier))->inspect(
            (string) ($state['stage_dir'] ?? ''),
            (int) ($state['target_version_code'] ?? 0),
            (string) ($state['package_sha256'] ?? '')
        );
    }

    private function cleanupPreMutationFailure(
        string $transactionId,
        MaintenanceModeService $maintenance,
        UpdateWebContinuation $continuation
    ): void {
        try {
            $state = (new UpdateTransactionJournal($this->stateRoot, $this->appRoot))
                ->load($transactionId);
        } catch (Throwable) {
            return;
        }

        if (($state['live_mutation_started'] ?? true) === true) {
            return;
        }

        try {
            $maintenanceState = $maintenance->state();
            if ($maintenanceState['active']
                && $maintenanceState['valid']
                && hash_equals(
                    $transactionId,
                    (string) ($maintenanceState['transaction_id'] ?? '')
                )) {
                $maintenance->leave($transactionId);
            }
        } catch (Throwable) {
            return;
        }

        try {
            $continuation->revoke($transactionId);
        } catch (Throwable) {
        }
    }

    private function assertMaintenanceOwner(
        MaintenanceModeService $maintenance,
        string $transactionId
    ): void {
        $state = $maintenance->state();
        if (!$state['active'] || !$state['valid']) {
            throw new RuntimeException('Web-updater требует активный валидный режим обслуживания');
        }
        if (!hash_equals($transactionId, (string) ($state['transaction_id'] ?? ''))) {
            throw new RuntimeException('Режим обслуживания принадлежит другой updater-транзакции');
        }
    }

    /** @return list<string> */
    private function excludedPaths(): array
    {
        $paths = [];
        foreach ([
            'PRIVATE_STORAGE_PATH',
            'UPLOAD_DIR',
            'NOTES_UPLOAD_DIR',
            'MESSENGER_UPLOAD_DIR',
            'RATE_LIMIT_STORAGE_PATH',
            'UPDATE_STAGING_PATH',
            'UPDATE_STATE_PATH',
            'UPDATE_BACKUP_PATH',
            'UPDATE_RELEASE_PATH',
        ] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                $paths[] = trim($value);
            }
        }

        return array_values(array_unique($paths));
    }

    private function database(): mysqli
    {
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('Для резервной копии updater требуется mysqli');
        }

        $user = trim((string) (getenv('DBUSER') ?: ''));
        $database = trim((string) (getenv('DBNAME') ?: ''));
        if ($user === '' || $database === '') {
            throw new RuntimeException('Не заданы DBUSER/DBNAME для резервной копии updater');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new mysqli(
            (string) (getenv('DBHOST') ?: 'localhost'),
            $user,
            (string) (getenv('DBPASS') ?: ''),
            $database,
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');
        return $db;
    }

    private function externalRoot(
        string $envName,
        string $privateRoot,
        string $fallbackSuffix
    ): string {
        $configured = trim((string) (getenv($envName) ?: ''));
        $path = $configured !== ''
            ? $configured
            : ($privateRoot !== ''
                ? rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . $fallbackSuffix
                : '');

        if ($path === '') {
            throw new RuntimeException(
                "{$envName} не настроен и PRIVATE_STORAGE_PATH недоступен"
            );
        }
        if (!$this->isAbsolute($path) || is_link($path)) {
            throw new RuntimeException("{$envName} должен указывать на безопасный абсолютный каталог");
        }

        if (!file_exists($path)) {
            $oldUmask = umask(0077);
            $created = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($path)) {
                throw new RuntimeException("Не удалось создать каталог {$envName}");
            }
        }

        $real = realpath($path);
        $app = realpath($this->appRoot);
        if (!is_string($real) || !is_dir($real) || !is_writable($real) || !is_string($app)) {
            throw new RuntimeException("Каталог {$envName} недоступен на запись");
        }

        $normalized = rtrim(str_replace('\\', '/', $real), '/');
        $appNormalized = rtrim(str_replace('\\', '/', $app), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $normalized = strtolower($normalized);
            $appNormalized = strtolower($appNormalized);
        }
        if ($normalized === $appNormalized
            || str_starts_with($normalized . '/', $appNormalized . '/')) {
            throw new RuntimeException("Каталог {$envName} должен находиться вне дерева приложения");
        }

        @chmod($real, 0700);
        return $real;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

final class UpdateWebTransactionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $safeCode,
        int $httpCode = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
    }
}
