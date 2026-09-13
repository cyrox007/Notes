<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RequestRateLimiter;
use Core\Request;
use Throwable;

final class AuthRateLimit
{
    public function handle(Request $request): bool
    {
        $limit = max(1, (int) (getenv('MAX_LOGIN_ATTEMPTS') ?: 5));
        $window = max(30, (int) (getenv('AUTH_RATE_LIMIT_WINDOW_SECONDS') ?: 300));

        try {
            $result = RequestRateLimiter::consume(
                'auth',
                RequestRateLimiter::clientSubject('auth'),
                $limit,
                $window
            );
        } catch (Throwable $e) {
            error_log('Auth rate limiter failed closed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Сервис авторизации временно недоступен';
            return false;
        }

        if (!$result['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Слишком много запросов. Повторите попытку позже.';
            return false;
        }

        return true;
    }
}
