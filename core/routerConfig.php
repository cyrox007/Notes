<?php

use App\Controller\MainController;
use App\Controller\AuthController;
use App\Controller\NoteController;
use App\Middlewares\LoginRequared;

$router = Route::getInstance();

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth', function ($addRoute) {
    $addRoute('GET', '/login', [AuthController::class, 'login'], [], "authpage");
    $addRoute('POST', '/login', [AuthController::class, 'sigin']);
    $addRoute('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout');
});

$router->group('/notes', function ($addRoute) {
    $addRoute("GET", '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');    
});

$router->dispatch(); 