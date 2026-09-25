<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\MaintenanceModeService;
use Core\Request;
use Core\SecurityHeaders;
use Core\UpdateAutomaticRecovery;
use Throwable;

/**
 * Глобальный HTTP-барьер режима обслуживания.
 *
 * При валидном marker незавершённого обновления middleware сначала пытается
 * автоматически продолжить recovery. Живое обновление защищено отдельным
 * транзакционным lock и вернёт operation_busy без вмешательства в его работу.
 */
final class EnforceMaintenanceMode
{
    private MaintenanceModeService $maintenance;
    private UpdateAutomaticRecovery $automaticRecovery;

    public function __construct(
        ?MaintenanceModeService $maintenance = null,
        ?UpdateAutomaticRecovery $automaticRecovery = null
    ) {
        $this->maintenance = $maintenance ?? new MaintenanceModeService();
        $this->automaticRecovery = $automaticRecovery ?? new UpdateAutomaticRecovery();
    }

    public function handle(Request $request): bool
    {
        try {
            $state = $this->maintenance->state();
        } catch (Throwable $e) {
            error_log('Maintenance state evaluation failed: ' . $e->getMessage());
            $this->reject($request, 'Состояние обслуживания не удалось безопасно проверить.', null);
            return false;
        }

        if (!$state['active']) {
            return true;
        }

        if (!$state['valid']) {
            $this->reject(
                $request,
                'Состояние обслуживания повреждено; автоматическое восстановление заблокировано для защиты данных.',
                null
            );
            return false;
        }

        $recovery = $this->automaticRecovery->attempt($this->maintenance);
        if (($recovery['status'] ?? '') === 'recovered') {
            return true;
        }

        $status = (string) ($recovery['status'] ?? 'failed');
        if ($status === 'failed') {
            error_log(
                'Автоматическое восстановление обновления не завершено: '
                . (string) ($recovery['message'] ?? 'неизвестная ошибка')
            );
        }

        $reason = $status === 'in_progress'
            ? 'Обновление или автоматическое восстановление уже выполняется.'
            : 'Автоматическое восстановление не завершено. Следующий запрос повторит безопасную попытку.';
        $this->reject($request, $reason, $state['transaction_id']);
        return false;
    }

    private function reject(Request $request, string $reason, ?string $transactionId): void
    {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 60');

        if ($this->expectsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'maintenance_mode',
                'message' => 'Workspace Organizer временно недоступен из-за обслуживания.',
                'reason' => $reason,
                'transaction_id' => $transactionId,
                'retry_after' => 60,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = htmlspecialchars(SecurityHeaders::nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Техническое обслуживание</title>'
            . '<style nonce="' . $nonce . '">body{font-family:system-ui,sans-serif;max-width:720px;margin:8vh auto;padding:24px}</style>'
            . '</head>'
            . '<body>'
            . '<h1>Техническое обслуживание</h1>'
            . '<p>Workspace Organizer временно недоступен, пока завершается безопасное обновление.</p>'
            . '<p>' . $safeReason . '</p>'
            . '<p>Данные не удаляются. Повторите попытку через несколько минут.</p>'
            . '</body></html>';
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->server('HTTP_ACCEPT', ''));
        $contentType = strtolower((string) $request->server('CONTENT_TYPE', ''));
        $requestedWith = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $requestedWith === 'xmlhttprequest';
    }
}
