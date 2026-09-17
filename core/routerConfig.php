<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Middlewares\LoginRequared;
use App\Middlewares\AuthRateLimit;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\EnforceLicenseMutation;
use Core\Router;

$router = Router::getInstance();
$router->addGlobalMiddleware(EnforceLicenseMutation::class);

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth')
    ->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage')
    ->add('POST', '/login', [AuthController::class, 'sigin'], [AuthRateLimit::class])
    ->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout')
    ->add('GET', '/registration', [AuthController::class, 'registration'], [], 'registration')
    ->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration_invite')
    ->add('POST', '/registration', [AuthController::class, 'registration'], [AuthRateLimit::class, CSRFMiddleware::class], 'register_submit')
    ->endGroup();
