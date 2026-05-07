<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\NoteController;
use App\Controllers\TaskController;
use App\Controllers\ProfileController;
use App\Controllers\FileController;
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
    $addRoute->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration');
    $addRoute->add('POST', '/registration', [AuthController::class, 'registration'], [], 'register_submit');
});

$router->group('/notes', function (Router $addRoute) {
    $addRoute->add("GET", '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes');
    $addRoute->add("POST", '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create');
    $addRoute->add("GET", '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page');
    $addRoute->add("POST", '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note');
    $addRoute->add("GET", '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note');
});

$router->group('/tasks', function (Router $addRoute) {
    $addRoute->add("GET", '/', [TaskController::class, 'index'], [LoginRequared::class], 'tasks');
    $addRoute->add("POST", '/', [TaskController::class, 'create'], [LoginRequared::class], 'task_create');
    $addRoute->add("POST", '/{str:uid}/update', [TaskController::class, 'update'], [], 'update_task');
    $addRoute->add("GET", '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class], 'delete_task');
    $addRoute->add("POST", '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class], 'add_subtask');
    $addRoute->add("POST", '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class], 'toggle_subtask');
    $addRoute->add("POST", '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class], 'delete_subtask');
    $addRoute->add("POST", '/category', [TaskController::class, 'createCategory'], [LoginRequared::class], 'create_category');
    $addRoute->add("POST", '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class], 'attach_category');
    $addRoute->add("DELETE", '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class], 'detach_category');
});

$router->group('/profile', function (Router $addRoute) {
   $addRoute->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class], 'profile');
   $addRoute->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class], 'profile-set');
   $addRoute->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class], 'profile-password-set');
   $addRoute->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class], 'profile-delete');
});

$router->group('/files', function (Router $addRoute) {
    // Главная страница файлового менеджера
    $addRoute->add('GET', '/', [FileController::class, 'index'], [LoginRequared::class], 'files');
    
    // Просмотр папки
    $addRoute->add('GET', '/folder/{int:folderId}/', [FileController::class, 'folder'], [LoginRequared::class], 'files_folder');
    
    // Создание папки
    $addRoute->add('POST', '/create-folder/', [FileController::class, 'createFolder'], [LoginRequared::class], 'files_create_folder');
    
    // Загрузка файла
    $addRoute->add('POST', '/upload/', [FileController::class, 'uploadFile'], [LoginRequared::class], 'files_upload');
    
    // Удаление файла/папки
    $addRoute->add('POST', '/delete/', [FileController::class, 'delete'], [LoginRequared::class], 'files_delete');
    
    // Переименование файла/папки
    $addRoute->add('POST', '/rename/', [FileController::class, 'rename'], [LoginRequared::class], 'files_rename');
    
    // Получение файла
    $addRoute->add('GET', '/get/{int:fileId}/', [FileController::class, 'getFile'], [LoginRequared::class], 'files_get');
});

$router->group('/messenger', function (Router $addRoute) {
    $addRoute->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class], 'messenger');
    $addRoute->add('POST', '/send_files', [MessagerController::class, 'uploadFile'], [LoginRequared::class]);
});

$router->group('/admin', function (Router $addRoute) {
   $addRoute->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel'); 
   $addRoute->add('POST', '/', [AdminController::class, 'saveCustomFields'], [LoginRequared::class, IsAdmin::class], 'save_custom_fields');
   $addRoute->add('POST', '/users/toggle-status', [AdminController::class, 'toggleUserStatus'], [LoginRequared::class, IsAdmin::class], 'admin_toggle_user');
   $addRoute->add('POST', '/users/delete', [AdminController::class, 'deleteUser'], [LoginRequared::class, IsAdmin::class], 'admin_delete_user');
});

$router->dispatch(); 