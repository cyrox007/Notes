<?php

declare(strict_types=1);

namespace App\Middlewares;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use App\Services\RequestRateLimiter;
use Core\Request;
use Core\SecurityEventLog;
use Throwable;

final class TwoFactorRateLimit
{
    public function handle(Request $request): bool
    {
        $limit = max(1, (int) (getenv('MAX_2FA_ATTEMPTS') ?: 8));
        $window = max(30, (int) (getenv('TWO_FACTOR_RATE_LIMIT_WINDOW_SECONDS') ?: 300));

        try {
            $result = RequestRateLimiter::consume(
                'auth-2fa',
                RequestRateLimiter::clientSubject('auth-2fa'),
                $limit,
                $window
            );
        } catch (Throwable $e) {
            SecurityEventLog::emit(
                'auth.two_factor_rate_limiter_failed',
                'critical',
                'auth_rate_limit',
                'system',
                null,
                ['error_type' => $e::class]
            );
            error_log('Two-factor rate limiter failed closed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Сервис двухфакторной проверки временно недоступен';
            return false;
        }

        if (!$result['allowed']) {
            SecurityEventLog::emit(
                'auth.two_factor_rate_limited',
                'warning',
                'auth_rate_limit',
                'anonymous',
                null,
                [
                    'subject_hash' => substr(hash('sha256', RequestRateLimiter::clientSubject('auth-2fa')), 0, 24),
                    'retry_after' => (int) $result['retry_after'],
                ]
            );
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Слишком много попыток ввода кода. Повторите попытку позже.';
            return false;
        }

        return true;
    }
}
