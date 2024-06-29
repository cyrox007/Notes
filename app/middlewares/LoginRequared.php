<?php
namespace App\Middlewares;

use Core\Request;
use Route;

class LoginRequared {
    public function handle(Request $request) {
        $routeManager = Route::getInstance();
        if (!$request->session("auth")) {
            return $routeManager->redirect('authpage', 'name');
        } 
    }
}