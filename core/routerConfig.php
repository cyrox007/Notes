<?php

use App\Controller\Main;
use App\Controller\Auth;

use App\Middlewares\LoginRequared;

$router = Route::getInstance();

$router->add('GET', '/', [Main::class, 'index'], [LoginRequared::class]);
$router->add('GET', '/Auth/login', [Auth::class, 'login'], [], "authpage");
$router->add('POST', '/Auth/login', [Auth::class, 'sigin']);
$router->add('GET', '/test', [Auth::class, 'test']);
$router->add('POST', '/test', [Auth::class, 'test_post'], [], 'main');

$router->dispatch(); 