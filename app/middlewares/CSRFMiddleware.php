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
            $this->logRejectedRequest($method, $token);
            http_response_code(403);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

            $expectsJson = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
                || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

            if ($expectsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Недействительный CSRF-токен. Обновите страницу и повторите действие.'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // A restored/cached login form may outlive the anonymous PHP session
            // that issued its token. Do not leave the user on a raw 403 page:
            // discard the stale form token and issue a fresh login page. The
            // credentials are intentionally not replayed automatically.
            if ($this->isLoginPath()) {
                unset($_SESSION['_csrf_token']);
                $location = $this->loginPath();
                header('Location: ' . $location, true, 303);
                exit;
            }

            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Сессия формы устарела</title></head><body>';
            echo '<main style="max-width:640px;margin:48px auto;padding:24px;font-family:system-ui,sans-serif">';
            echo '<h1 style="font-size:22px">Сессия формы устарела</h1>';
            echo '<p>Защитный токен страницы больше не действует. Обновите страницу и повторите действие.</p>';
            echo '<p><button type="button" onclick="location.reload()">Обновить страницу</button></p>';
            echo '</main></body></html>';
            exit;
        }

        return true;
    }

    private function isLoginPath(): bool
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return preg_match('~/auth/login/?$~', $path) === 1;
    }

    private function loginPath(): string
    {
        $basePath = trim((string) (getenv('BASE_PATH') ?: '/'));
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') {
            $basePath = '';
        }

        return $basePath . '/auth/login/';
    }

    private function logRejectedRequest(string $method, mixed $token): void
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $sessionFingerprint = session_id() !== ''
            ? substr(hash('sha256', session_id()), 0, 12)
            : 'none';
        $sessionTokenPresent = isset($_SESSION['_csrf_token']) && is_string($_SESSION['_csrf_token']);

        error_log(sprintf(
            'CSRF rejected: method=%s path=%s request_token=%s session_token=%s session=%s',
            $method,
            is_string($path) ? $path : '[invalid]',
            is_string($token) && $token !== '' ? 'present' : 'missing',
            $sessionTokenPresent ? 'present' : 'missing',
            $sessionFingerprint
        ));
    }
}
