<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\MessagerController;
use App\Controllers\MessengerGroupController;
use App\Controllers\MessengerVoiceController;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireMessengerUse;
use App\Middlewares\AuthRateLimit;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\UploadRateLimit;
use App\Middlewares\StorageQuotaLimit;
use App\Middlewares\StorageMutationLock;
use App\Middlewares\EnforceMessengerUploadPolicy;
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

$router->group('/messenger')
    ->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class, RequireMessengerUse::class], 'messenger')
    ->add('POST', '/socket-ticket', [MessagerController::class, 'socketTicket'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_socket_ticket')
    ->add('POST', '/upload', [MessagerController::class, 'uploadFile'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_upload')
    ->add('POST', '/voice-upload', [MessengerVoiceController::class, 'upload'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_voice_upload')
    ->add('GET', '/media/{str:uid}', [MessagerController::class, 'media'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_media')
    ->add('GET', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'avatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar')
    ->add('POST', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'uploadAvatar'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class], 'messenger_group_avatar_upload')
    ->add('POST', '/group-avatar/{str:uid}/delete', [MessengerGroupController::class, 'removeAvatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar_delete')
    ->endGroup();
