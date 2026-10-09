<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdatePhpCli.php';
require_once __DIR__ . '/UpdateProcessRunner.php';
require_once __DIR__ . '/UpdateInProcessRunner.php';
require_once __DIR__ . '/UpdateApplyCommand.php';
require_once __DIR__ . '/UpdateCoordinatorLock.php';
require_once __DIR__ . '/UpdateWebContinuation.php';
require_once __DIR__ . '/UpdateTransactionJournal.php';
require_once __DIR__ . '/UpdateExternalRuntime.php';
require_once __DIR__ . '/ServiceLog.php';

/**
 * Автоматически продолжает восстановление оборванной updater-транзакции.
 *
 * Класс вызывается только при активном валидном maintenance-marker. Конкурентное
 * живое обновление защищено UpdateApplyOperationLock внутри update_apply.php:
 * такая попытка возвращает operation_busy и ничего не меняет.
 */
final class UpdateAutomaticRecovery
{
    private const WEB_HANDOFF_GRACE_SECONDS = 90;

    private string $appRoot;

    /** @var (\Closure(list<string>,string,int):array{code:int,stdout:string,stderr:string})|null */
    private ?\Closure $processInvoker;

    public function __construct(?string $appRoot = null, ?\Closure $processInvoker = null)
    {
        $root = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($root) || !is_dir($root) || is_link($root)) {
            throw new RuntimeException('Не удалось безопасно определить корень приложения для восстановления');
        }

        $this->appRoot = rtrim($root, '/\\');
        $this->processInvoker = $processInvoker;
    }

    /**
     * @return array{status:string,transaction_id:?string,code:?string,message:string}
     */
    public function attempt(MaintenanceModeService $maintenance): array
    {
        try {
            $state = $maintenance->state();
        } catch (Throwable $e) {
            return $this->result('failed', null, 'maintenance_state_failed', $e->getMessage());
        }

        if (!$state['active']) {
            return $this->result('not_required', null, null, 'Восстановление не требуется');
        }

        $transactionId = is_string($state['transaction_id'] ?? null)
            ? trim((string) $state['transaction_id'])
            : '';

        if (!$state['valid'] || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            return $this->result(
                'blocked',
                null,
                'invalid_maintenance_state',
                'Повреждённое состояние обслуживания нельзя восстанавливать автоматически'
            );
        }

        $stateRoot = $maintenance->configuredStateRoot();
        if (!is_string($stateRoot) || trim($stateRoot) === '') {
            return $this->result(
                'failed',
                $transactionId,
                'state_root_missing',
                'Не удалось определить внешний каталог состояния updater'
            );
        }

        try {
            // Сначала проверяем владение живой операцией. Это не позволяет
            // recovery читать ещё не созданный журнал поверх выполняющегося updater.
            $coordinatorLock = new UpdateCoordinatorLock($stateRoot, $transactionId);
        } catch (UpdateCoordinatorBusyException) {
            return $this->result(
                'in_progress',
                $transactionId,
                'operation_busy',
                'Исходная операция обновления ещё выполняется'
            );
        } catch (Throwable $e) {
            return $this->result('failed', $transactionId, 'coordinator_lock_failed', $e->getMessage());
        }

        try {
            $journalState = (new UpdateTransactionJournal($stateRoot, $this->appRoot))
                ->load($transactionId);
            $guard = $this->webRecoveryGuard($stateRoot, $transactionId, $journalState);
            if ($guard !== null) {
                return $guard;
            }
        } catch (Throwable $e) {
            return $this->result(
                'failed',
                $transactionId,
                'web_continuation_state_failed',
                $e->getMessage()
            );
        }

        try {
            $journalState = (new UpdateTransactionJournal($stateRoot, $this->appRoot))
                ->load($transactionId);

            if ($this->processInvoker === null && !UpdateProcessRunner::available()) {
                $backupDir = is_array($journalState['backups'] ?? null)
                    ? trim((string) ($journalState['backups']['backup_dir'] ?? ''))
                    : '';

                $options = [
                    'transaction' => $transactionId,
                    'recover' => true,
                    'single-step' => true,
                    'state-root' => $stateRoot,
                ];
                if ($backupDir !== '') {
                    $options['backup-root'] = dirname($backupDir);
                }

                $payload = (new UpdateApplyCommand(
                    $this->appRoot,
                    true,
                    new UpdateInProcessRunner()
                ))->execute($options);

                $status = (string) ($payload['status'] ?? '');
                if ($status === 'in_progress') return $this->result(
                    'in_progress', $transactionId, 'recovery_step_saved',
                    'Шаг восстановления сохранён. Следующий запрос продолжит откат.'
                );
                if (!in_array($status, [
                    'rolled_back',
                    'rollback_recovery_verified',
                    'committed_recovery_verified',
                    'recovered_without_live_mutation',
                ], true)) {
                    return $this->result(
                        'failed',
                        $transactionId,
                        'unexpected_recovery_result',
                        'Восстановление вернуло неподдерживаемое состояние'
                    );
                }

                $after = $maintenance->state();
                if ($after['active']) {
                    return $this->result(
                        'failed',
                        $transactionId,
                        'maintenance_still_active',
                        'Восстановление завершилось, но режим обслуживания остался активным'
                    );
                }

                $this->revokeWebContinuation($stateRoot, $transactionId);

                return $this->result(
                    'recovered',
                    $transactionId,
                    $status,
                    'Оборванное обновление автоматически восстановлено в web-режиме'
                );
            }

            $recordedRuntime = $journalState['external_runtime'] ?? null;
            $runtime = null;
            if (is_array($recordedRuntime)) {
                $runtime = (new UpdateExternalRuntime($this->appRoot))->verifyRecorded(
                    $recordedRuntime,
                    (int) ($journalState['installed_version_code'] ?? 0)
                );
            }

            $command = [
                UpdatePhpCli::resolve(),
                $runtime !== null
                    ? (string) $runtime['entrypoint']
                    : $this->appRoot . '/bin/update_apply.php',
            ];
            if ($runtime !== null) {
                $command[] = '--app-root=' . $this->appRoot;
            }
            $command[] = '--transaction=' . $transactionId;
            $command[] = '--recover';
            $command[] = '--json';
            $command[] = '--state-root=' . $stateRoot;

            $backupDir = is_array($journalState['backups'] ?? null)
                ? trim((string) ($journalState['backups']['backup_dir'] ?? ''))
                : '';
            if ($backupDir !== '') {
                $command[] = '--backup-root=' . dirname($backupDir);
            }

            $process = $this->runProcess(
                $command,
                1200,
                $runtime !== null ? (string) $runtime['runtime_root'] : $this->appRoot
            );
            $payload = $this->decodePayload($process['stdout']);

            if ($process['code'] !== 0) {
                $code = is_string($payload['code'] ?? null) ? (string) $payload['code'] : 'recovery_failed';
                $message = is_string($payload['message'] ?? null) && trim((string) $payload['message']) !== ''
                    ? trim((string) $payload['message'])
                    : $this->failureDetails($process);

                if ($code === 'operation_busy') {
                    return $this->result(
                        'in_progress',
                        $transactionId,
                        $code,
                        'Обновление или восстановление уже выполняется'
                    );
                }

                return $this->result('failed', $transactionId, $code, $message);
            }

            $status = (string) ($payload['status'] ?? '');
            if (!in_array($status, [
                'rolled_back',
                'rollback_recovery_verified',
                'committed_recovery_verified',
                'recovered_without_live_mutation',
            ], true)) {
                return $this->result(
                    'failed',
                    $transactionId,
                    'unexpected_recovery_result',
                    'Восстановление вернуло неподдерживаемое состояние'
                );
            }

            $after = $maintenance->state();
            if ($after['active']) {
                return $this->result(
                    'failed',
                    $transactionId,
                    'maintenance_still_active',
                    'Восстановление завершилось, но режим обслуживания остался активным'
                );
            }

            $this->revokeWebContinuation($stateRoot, $transactionId);

            return $this->result(
                'recovered',
                $transactionId,
                $status,
                'Оборванное обновление автоматически восстановлено'
            );
        } catch (Throwable $e) {
            return $this->result('failed', $transactionId, 'recovery_exception', $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $journalState
     * @return array{status:string,transaction_id:?string,code:?string,message:string}|null
     */
    private function webRecoveryGuard(
        string $stateRoot,
        string $transactionId,
        array $journalState
    ): ?array {
        $journalStatus = (string) ($journalState['state'] ?? '');
        if (in_array($journalStatus, [
            'rollback_started',
            'code_restored',
            'database_restored',
            'rollback_verified',
            'rollback_failed',
            'committed',
        ], true)) {
            return null;
        }

        $leaseStatus = (new UpdateWebContinuation($stateRoot))->leaseStatus($transactionId);
        if ($leaseStatus === 'active') {
            return $this->result(
                'in_progress',
                $transactionId,
                'web_update_in_progress',
                'Пошаговое web-обновление ещё выполняется'
            );
        }

        $handoffState = in_array($journalStatus, [
            'code_switched',
            'migrations_applied',
            'postcheck_verified',
        ], true);
        if (!$handoffState) {
            return null;
        }

        if ($leaseStatus === 'invalid') {
            return $this->result(
                'failed',
                $transactionId,
                'web_continuation_state_invalid',
                'Состояние web-продолжения повреждено; автоматический rollback заблокирован'
            );
        }

        if ($leaseStatus === 'missing' && $this->journalRecentlyUpdated($journalState)) {
            return $this->result(
                'in_progress',
                $transactionId,
                'web_continuation_handoff',
                'Web-updater переключает runtime; автоматический rollback временно отложен'
            );
        }

        return null;
    }

    /** @param array<string,mixed> $journalState */
    private function journalRecentlyUpdated(array $journalState): bool
    {
        $updatedAt = $journalState['updated_at'] ?? null;
        if (!is_int($updatedAt) || $updatedAt <= 0) {
            return false;
        }

        $now = time();
        return $updatedAt <= $now + 5
            && $updatedAt >= $now - self::WEB_HANDOFF_GRACE_SECONDS;
    }

    private function revokeWebContinuation(string $stateRoot, string $transactionId): void
    {
        try {
            (new UpdateWebContinuation($stateRoot))->revoke($transactionId);
        } catch (Throwable $e) {
            error_log(
                'Не удалось удалить завершённое web-продолжение updater'
                . ' [transaction=' . $transactionId . ']: ' . $e->getMessage()
            );
        }
    }

    /** @param list<string> $command @return array{code:int,stdout:string,stderr:string} */
    private function runProcess(array $command, int $timeoutSeconds, ?string $cwd = null): array
    {
        $cwd = $cwd !== null && trim($cwd) !== '' ? $cwd : $this->appRoot;

        if ($this->processInvoker !== null) {
            return ($this->processInvoker)($command, $cwd, $timeoutSeconds);
        }

        return (new UpdateProcessRunner())->run($command, $cwd, $timeoutSeconds);
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $stdout): array
    {
        try {
            $payload = json_decode(trim($stdout), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) && !array_is_list($payload) ? $payload : [];
    }

    /** @param array{code:int,stdout:string,stderr:string} $process */
    private function failureDetails(array $process): string
    {
        $details = trim($process['stderr']);
        if ($details === '') {
            $details = trim($process['stdout']);
        }
        return $details !== '' ? $details : 'Автоматическое восстановление завершилось ошибкой';
    }

    /**
     * @return array{status:string,transaction_id:?string,code:?string,message:string}
     */
    private function result(string $status, ?string $transactionId, ?string $code, string $message): array
    {
        if ($status !== 'not_required') {
            $level = match ($status) {
                'failed' => 'error',
                'blocked' => 'warning',
                'recovered' => 'warning',
                default => 'info',
            };

            ServiceLog::emit(
                'updater.recovery_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($status)),
                $level,
                'updater',
                [
                    'transaction_id' => $transactionId,
                    'code' => $code,
                    'message' => $message,
                ]
            );
        }

        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'code' => $code,
            'message' => $message,
        ];
    }
}
