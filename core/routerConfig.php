<?php

use App\Controller\Main;
use App\Controller\Auth;

$router = Route::getInstance();

$router->add('GET', '/', [Main::class, 'index']);
$router->add('GET', '/Auth/login', [Auth::class, 'login']);
$router->add('POST', '/Auth/login', [Auth::class, 'sigin']);
$router->add('GET', '/test', [Auth::class, 'test']);
$router->add('POST', '/test', [Auth::class, 'test_post'], [], 'main');

$router->dispatch(); 