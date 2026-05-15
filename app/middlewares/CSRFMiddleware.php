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

        
        // Разрешаем AJAX-запросы с заголовком X-Requested-With
        // Это стандартный заголовок который добавляют большинство JS библиотек
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
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