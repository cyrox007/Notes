<?php
namespace App\Middlewares;

use Core\Helper;

class CSRFMiddleware {
    public function handle() {
        // Проверяем только POST-запросы
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
            
            if (!$token || !Helper::validateToken($token)) {
                // В случае ошибки CSRF-токена выполните перенаправление или выведите ошибку
                die('Ошибка CSRF: Недействительный CSRF-токен');
            }
        }
        
        // Если CSRF проверка прошла, продолжаем выполнение
        return true;
    }
}