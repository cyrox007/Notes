<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Helper;
use Core\Request;

/**
 * Middleware для проверки CSRF токена.
 *
 * Все state-changing HTTP методы требуют токен. Заголовок X-Requested-With
 * используется только для формата ответа и больше не является обходом защиты.
 */
class CSRFMiddleware
{
    public function handle(?Request $request = null): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $token = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? null;

        if (!is_string($token) || $token === '' || !Helper::validateToken($token)) {
            http_response_code(403);

            $expectsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
                || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Недействительный CSRF-токен'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            header('Content-Type: text/plain; charset=utf-8');
            exit('Ошибка CSRF: недействительный CSRF-токен');
        }

        return true;
    }
}