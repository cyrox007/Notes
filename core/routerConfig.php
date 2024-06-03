<?php

use App\Controller\Main;
use App\Controller\Auth;

$router = new Route();

$router->add('GET', '/', [Main::class, 'index']);
$router->add('GET', '/Auth/login', [Auth::class, 'login']);
$router->add('POST', '/Auth/login', [Auth::class, 'sigin']);
$router->add('GET', '/test/{int:id}', [Auth::class, 'test']);

$router->dispatch(); 