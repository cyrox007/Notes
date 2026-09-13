<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\RequestRateLimiter;
use Core\Request;
use Throwable;

final class UploadRateLimit
{
    public function handle(Request $request): bool
    {
        $limit = max(1, (int) (getenv('UPLOAD_RATE_LIMIT_ATTEMPTS') ?: 60));
        $window = max(10, (int) (getenv('UPLOAD_RATE_LIMIT_WINDOW_SECONDS') ?: 60));

        try {
            $result = RequestRateLimiter::consume(
                'upload',
                RequestRateLimiter::clientSubject('upload'),
                $limit,
                $window
            );
        } catch (Throwable $e) {
            error_log('Upload rate limiter failed closed: ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Загрузка временно недоступна';
            return false;
        }

        if (!$result['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $result['retry_after']);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Слишком много загрузок. Повторите попытку позже.';
            return false;
        }

        return true;
    }
}
