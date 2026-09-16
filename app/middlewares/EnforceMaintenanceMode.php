<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\MaintenanceModeService;
use Core\Request;
use Throwable;

/**
 * Global HTTP maintenance gate.
 *
 * Static files remain the web server's responsibility. Every matched dynamic
 * application route returns 503 while an updater maintenance marker is active.
 * Recovery is intentionally CLI-driven so migrations do not depend on HTTP/DB.
 */
final class EnforceMaintenanceMode
{
    public function __construct(private ?MaintenanceModeService $maintenance = null)
    {
        $this->maintenance ??= new MaintenanceModeService();
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

        $reason = $state['valid']
            ? $state['reason']
            : 'Состояние обслуживания повреждено; требуется восстановление через CLI.';
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
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Техническое обслуживание</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:8vh auto;padding:24px">'
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
