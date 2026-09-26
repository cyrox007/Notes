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
        $message = $status === 'in_progress'
            ? 'Обновление или восстановление уже выполняется.'
            : 'Автоматическое восстановление будет повторено следующим запросом.';

        if ($status === 'failed') {
            error_log(
                'Раннее автоматическое восстановление обновления не завершено: '
                . (string) ($recovery['message'] ?? 'неизвестная ошибка')
            );
        }

        self::reject($message);
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

    private static function reject(string $reason): never
    {
        http_response_code(503);
        if (!headers_sent()) {
            header('Cache-Control: no-store');
            header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
        }

        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Восстановление Workspace Organizer</title></head><body>'
            . '<h1>Завершается безопасное восстановление</h1>'
            . '<p>' . $safeReason . '</p>'
            . '<p>Ручные команды не требуются. Повторите запрос через минуту.</p>'
            . '</body></html>';
        exit;
    }
}
