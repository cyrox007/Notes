<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\NoteController;
use App\Controllers\ProfileController;
use App\Controllers\Admin\AdminController;
use App\Controllers\MessagerController;
use App\Middlewares\LoginRequared;
use App\Middlewares\IsAdmin;
use Core\Router;

$router = Router::getInstance();

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth', function (Router $addRoute) {
    $addRoute->add('GET', '/login', [AuthController::class, 'login'], [], "authpage");
    $addRoute->add('POST', '/login', [AuthController::class, 'sigin']);
    $addRoute->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout');
});

$router->group('/notes', function (Router $addRoute) {
    $addRoute->add("GET", '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');
    $addRoute->add("POST", '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create');
    $addRoute->add("GET", '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page');
    $addRoute->add("POST", '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note');
    $addRoute->add("GET", '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note');
});

$router->group('/profile', function (Router $addRoute) {
   $addRoute->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class], 'profile');
   $addRoute->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class], 'profile-set');
   $addRoute->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class], 'profile-password-set');
   $addRoute->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class], 'profile-delete');
});

$router->group('/messenger', function (Router $addRoute) {
    $addRoute->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class], 'messenger');
    $addRoute->add('POST', '/send_files', [MessagerController::class, 'uploadFile'], [LoginRequared::class]);
});

$router->group('/admin', function (Router $addRoute) {
   $addRoute->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel'); 
   $addRoute->add('POST', '/', [AdminController::class, 'saveCustomFields'], [LoginRequared::class, IsAdmin::class], 'save_custom_fields'); 
});

$router->dispatch(); 