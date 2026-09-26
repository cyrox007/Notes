<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;
use Throwable;

/**
 * Ранний HTTP-барьер восстановления updater до инициализации БД и модулей.
 *
 * Любой запрос может безопасно запустить recovery только для transaction_id из
 * валидного внешнего maintenance-marker. Живой updater защищён своим operation
 * lock, поэтому параллельный запрос не вмешивается в выполняющуюся транзакцию.
 */
final class UpdateBootRecoveryGate
{
    public static function enforce(string $appRoot): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        try {
            $maintenance = new MaintenanceModeService(null, $appRoot);
            $state = $maintenance->state();
        } catch (Throwable $e) {
            self::reject('Состояние обслуживания не удалось безопасно проверить.');
        }

        if (!$state['active'] || !$state['valid']) {
            return;
        }

        $transactionId = trim((string) ($state['transaction_id'] ?? ''));
        $stateRoot = $maintenance->configuredStateRoot();
        if (!self::hasRecoveryJournal($stateRoot, $transactionId)) {
            // Обычный maintenance без updater-журнала не является оборванным
            // обновлением. Его штатно обработает ранний maintenance-барьер index.php.
            return;
        }

        $recovery = (new UpdateAutomaticRecovery($appRoot))->attempt($maintenance);

        try {
            $afterRecovery = $maintenance->state();
        } catch (Throwable $e) {
            self::reject('Результат автоматического восстановления не удалось безопасно проверить.');
        }

        if (!$afterRecovery['active']) {
            return;
        }

        $status = (string) ($recovery['status'] ?? 'failed');
        if ($status === 'in_progress') {
            self::reject('Обновление или восстановление уже выполняется.');
        }

        if ($status === 'failed') {
            $code = self::safeDiagnosticCode((string) ($recovery['code'] ?? 'recovery_failed'));
            error_log(
                'Раннее автоматическое восстановление обновления не завершено'
                . ' [transaction=' . $transactionId . ', code=' . $code . ']: '
                . (string) ($recovery['message'] ?? 'неизвестная ошибка')
            );

            self::reject(
                self::diagnosticMessage($code),
                $transactionId,
                $code
            );
        }

        self::reject('Автоматическое восстановление будет повторено следующим запросом.');
    }

    private static function hasRecoveryJournal(?string $stateRoot, string $transactionId): bool
    {
        if (
            !is_string($stateRoot)
            || trim($stateRoot) === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1
        ) {
            return false;
        }

        $journal = rtrim($stateRoot, '/\\')
            . DIRECTORY_SEPARATOR . 'transactions'
            . DIRECTORY_SEPARATOR . $transactionId . '.json';

        return is_file($journal) && !is_link($journal);
    }

    private static function diagnosticMessage(string $code): string
    {
        return match ($code) {
            'rollback_failed' => 'Не удалось подтвердить автоматический откат рабочей версии.',
            'maintenance_still_active' => 'Восстановление завершилось, но режим обслуживания не был безопасно снят.',
            'unexpected_recovery_result' => 'Восстановление завершилось в неподдерживаемом состоянии.',
            'state_root_missing' => 'Не удалось определить внешний журнал состояния обновления.',
            'invalid_maintenance_state' => 'Состояние режима обслуживания повреждено и требует диагностики.',
            'coordinator_lock_failed' => 'Не удалось проверить блокировку операции обновления.',
            'recovery_exception', 'recovery_failed' => 'Автоматическое восстановление завершилось технической ошибкой.',
            default => 'Автоматическое восстановление не удалось безопасно завершить.',
        };
    }

    private static function safeDiagnosticCode(string $code): string
    {
        $code = strtolower(trim($code));
        return preg_match('/^[a-z0-9_]{1,64}$/D', $code) === 1
            ? $code
            : 'recovery_failed';
    }

    private static function reject(
        string $reason,
        ?string $transactionId = null,
        ?string $diagnosticCode = null
    ): never {
        http_response_code(503);

        $transactionId = is_string($transactionId)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) === 1
                ? $transactionId
                : null;
        $diagnosticCode = is_string($diagnosticCode)
            ? self::safeDiagnosticCode($diagnosticCode)
            : null;

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            if (!headers_sent()) {
                header('Cache-Control: no-store');
                header('Retry-After: 60');
                header('Content-Type: application/json; charset=utf-8');
            }

            $payload = [
                'success' => false,
                'error' => 'update_recovery_pending',
                'message' => $reason,
                'retry_after' => 60,
            ];
            if ($diagnosticCode !== null) {
                $payload['diagnostic_code'] = $diagnosticCode;
            }
            if ($transactionId !== null) {
                $payload['transaction_id'] = $transactionId;
            }

            echo json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            exit;
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
        }

        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $diagnostic = '';
        if ($diagnosticCode !== null && $transactionId !== null) {
            $safeCode = htmlspecialchars($diagnosticCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeTransaction = htmlspecialchars($transactionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $diagnostic = '<p><strong>Код диагностики:</strong> <code>' . $safeCode . '</code><br>'
                . '<strong>ID транзакции:</strong> <code>' . $safeTransaction . '</code></p>';
        }

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Восстановление Workspace Organizer</title></head><body>'
            . '<h1>Завершается безопасное восстановление</h1>'
            . '<p>' . $safeReason . '</p>'
            . $diagnostic
            . '<p>Ручные команды не требуются. Повторите запрос через минуту.</p>'
            . '</body></html>';
        exit;
    }
}
