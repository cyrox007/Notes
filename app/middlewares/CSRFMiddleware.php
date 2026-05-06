<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Helper;
use Core\Request;

/**
 * Middleware для проверки CSRF токена
 * 
 * Проверяет наличие и валидность CSRF токена во всех POST-запросах.
 * Используется для защиты от межсайтовой подделки запросов.
 */
class CSRFMiddleware
{
    /**
     * Обрабатывает запрос и проверяет CSRF токен
     * 
     * @param Request|null $request Объект запроса (опционально)
     * @return bool Возвращает true если проверка пройдена или это не POST-запрос
     */
    public function handle(?Request $request = null): bool
    {
        // Проверяем только POST-запросы
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        
        $token = $_POST['csrf_token'] ?? null;
        
        if ($token === null || !Helper::validateToken($token)) {
            http_response_code(403);
            die('Ошибка CSRF: Недействительный CSRF-токен');
        }
        
        return true;
    }
}