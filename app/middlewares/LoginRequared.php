<?php

declare(strict_types=1);

namespace App\Middlewares;

use Core\Request;
use Core\Router;

class LoginRequared
{
    public function handle(Request $request): bool
    {
        $routeManager = Router::getInstance();
        
        if (!$request->session('auth')) {
            return $routeManager->redirect('authpage', 'name');
        }

        return true;
    }
}