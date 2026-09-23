<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\LicenseRecoveryController;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireLicenseManage;
use App\Middlewares\AuthRateLimit;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\TwoFactorRateLimit;
use App\Middlewares\EnforceLicenseMutation;
use App\Middlewares\EnforceTwoFactorPolicy;
use Core\Router;

$router = Router::getInstance();
$router->addGlobalMiddleware(EnforceLicenseMutation::class);
$router->addGlobalMiddleware(EnforceTwoFactorPolicy::class);

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/system')
    ->add('GET', '/license', [LicenseRecoveryController::class, 'index'], [LoginRequared::class, RequireLicenseManage::class], 'system_license')
    ->add('POST', '/license/activate', [LicenseRecoveryController::class, 'activate'], [LoginRequared::class, RequireLicenseManage::class, CSRFMiddleware::class], 'system_license_activate')
    ->add('POST', '/license/clear', [LicenseRecoveryController::class, 'clear'], [LoginRequared::class, RequireLicenseManage::class, CSRFMiddleware::class], 'system_license_clear')
    ->endGroup();

$router->group('/auth')
    ->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage')
    ->add('POST', '/login', [AuthController::class, 'sigin'], [AuthRateLimit::class])
    ->add('GET', '/two-factor', [AuthController::class, 'twoFactor'], [], 'auth_two_factor')
    ->add('POST', '/two-factor', [AuthController::class, 'verifyTwoFactor'], [TwoFactorRateLimit::class, CSRFMiddleware::class], 'auth_two_factor_verify')
    ->add('GET', '/two-factor/setup', [AuthController::class, 'requiredTwoFactorSetup'], [], 'auth_two_factor_setup')
    ->add('POST', '/two-factor/setup', [AuthController::class, 'confirmRequiredTwoFactorSetup'], [TwoFactorRateLimit::class, CSRFMiddleware::class], 'auth_two_factor_setup_confirm')
    ->add('GET', '/two-factor/recovery-codes', [AuthController::class, 'recoveryCodes'], [LoginRequared::class], 'auth_two_factor_recovery')
    ->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout')
    ->add('GET', '/registration', [AuthController::class, 'registration'], [], 'registration')
    ->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration_invite')
    ->add('POST', '/registration', [AuthController::class, 'registration'], [AuthRateLimit::class, CSRFMiddleware::class], 'register_submit')
    ->endGroup();
