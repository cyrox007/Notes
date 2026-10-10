<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/Environment.php';

use App\Services\MaintenanceModeService;
use Throwable;

require_once __DIR__ . '/UpdateWebContinuation.php';

/**
 * Ранний HTTP-мост web-updater во время maintenance.
 *
 * Обычный Router ещё не загружается: это позволяет завершить миграции после
 * переключения кода, когда схема БД временно не соответствует новой версии.
 */
final class UpdateWebHttpBridge
{
    private const STEP_SUFFIX = '/admin/updates/web-step';

    /**
     * @param array<string,mixed> $maintenanceState
     */
    public static function canHandle(
        MaintenanceModeService $maintenance,
        array $maintenanceState
    ): bool {
        if (PHP_SAPI === 'cli'
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
            || empty($maintenanceState['active'])
            || empty($maintenanceState['valid'])) {
            return false;
        }

        $path = (string) parse_url(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        );
        if ($path === '' || !str_ends_with(rtrim($path, '/'), self::STEP_SUFFIX)) {
            return false;
        }

        if (!self::sameOrigin()) {
            return false;
        }

        $transactionId = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TRANSACTION'] ?? ''));
        $token = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TOKEN'] ?? ''));
        $owner = trim((string) ($maintenanceState['transaction_id'] ?? ''));

        if ($transactionId === ''
            || $token === ''
            || $owner === ''
            || !hash_equals($owner, $transactionId)) {
            return false;
        }

        $stateRoot = $maintenance->configuredStateRoot();
        if (!is_string($stateRoot) || $stateRoot === '') {
            return false;
        }

        try {
            return (new UpdateWebContinuation($stateRoot))
                ->verify($transactionId, $token);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $maintenanceState
     */
    public static function handle(
        string $appRoot,
        MaintenanceModeService $maintenance,
        array $maintenanceState
    ): never {
        $transactionId = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TRANSACTION'] ?? ''));
        $token = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TOKEN'] ?? ''));

        if (!self::canHandle($maintenance, $maintenanceState)) {
            self::respond(403, [
                'success' => false,
                'error' => 'update_continuation_forbidden',
                'message' => 'Продолжение обновления не прошло проверку.',
            ]);
        }

        try {
            require_once __DIR__ . '/UpdateWebTransaction.php';
            $result = (new UpdateWebTransaction($appRoot))->step(
                $transactionId,
                $token
            );

            self::respond(200, [
                'success' => true,
                'result' => $result,
            ]);
        } catch (UpdateWebTransactionException $e) {
            $httpCode = in_array($e->getCode(), [403, 409], true)
                ? $e->getCode()
                : 500;
            self::respond($httpCode, [
                'success' => false,
                'error' => $e->safeCode,
                'message' => $e->getMessage(),
                'retryable' => $e->safeCode === 'operation_busy',
            ]);
        } catch (Throwable $e) {
            error_log(
                'Web-updater step failed [transaction='
                . $transactionId
                . ']: '
                . $e->getMessage()
            );
            self::respond(500, [
                'success' => false,
                'error' => 'update_step_failed',
                'message' => 'Шаг обновления завершился ошибкой. Система автоматически продолжит восстановление.',
                'retryable' => true,
            ]);
        }
    }

    private static function sameOrigin(): bool
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }

        $siteUrl = trim((string) (Environment::get('SITEURL') ?: ''));
        $originParts = parse_url($origin);
        $siteParts = parse_url($siteUrl);
        if (!is_array($originParts) || !is_array($siteParts)) {
            return false;
        }

        $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
        $siteScheme = strtolower((string) ($siteParts['scheme'] ?? ''));
        $originHost = strtolower((string) ($originParts['host'] ?? ''));
        $siteHost = strtolower((string) ($siteParts['host'] ?? ''));
        $originPort = (int) ($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
        $sitePort = (int) ($siteParts['port'] ?? ($siteScheme === 'https' ? 443 : 80));

        return $originScheme !== ''
            && hash_equals($siteScheme, $originScheme)
            && hash_equals($siteHost, $originHost)
            && $sitePort === $originPort;
    }

    /** @param array<string,mixed> $payload */
    private static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit;
    }
}
