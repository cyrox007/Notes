<?php

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\NoteController;
use App\Controllers\ProfileController;
use App\Controllers\Admin\AdminController;

use App\Middlewares\LoginRequared;
use App\Middlewares\IsAdmin;

$router = Route::getInstance();

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth', function ($addRoute) {
    $addRoute('GET', '/login', [AuthController::class, 'login'], [], "authpage");
    $addRoute('POST', '/login', [AuthController::class, 'sigin']);
    $addRoute('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout');
});

$router->group('/notes', function ($addRoute) {
    $addRoute("GET", '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');
    $addRoute("GET", '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page');
    $addRoute("POST", '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note');
    $addRoute("GET", '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note');
});

$router->group('/profile', function ($addRoute) {
   $addRoute('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class], 'profile');
   $addRoute('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class], 'profile-set');
   $addRoute('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class], 'profile-password-set');
   $addRoute('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class], 'profile-delete');
});

$router->group('/admin', function ($addRoute) {
   $addRoute('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel'); 
   $addRoute('POST', '/', [AdminController::class, 'saveCustomFields'], [LoginRequared::class, IsAdmin::class], 'save_custom_fields'); 
});

$router->dispatch(); 