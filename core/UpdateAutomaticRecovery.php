<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdatePhpCli.php';
require_once __DIR__ . '/UpdateProcessRunner.php';
require_once __DIR__ . '/UpdateCoordinatorLock.php';
require_once __DIR__ . '/UpdateTransactionJournal.php';
require_once __DIR__ . '/UpdateExternalRuntime.php';

/**
 * Автоматически продолжает восстановление оборванной updater-транзакции.
 *
 * Класс вызывается только при активном валидном maintenance-marker. Конкурентное
 * живое обновление защищено UpdateApplyOperationLock внутри update_apply.php:
 * такая попытка возвращает operation_busy и ничего не меняет.
 */
final class UpdateAutomaticRecovery
{
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
        return [
            'status' => $status,
            'transaction_id' => $transactionId,
            'code' => $code,
            'message' => $message,
        ];
    }
}
