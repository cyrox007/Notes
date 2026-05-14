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

$router->group('/auth')
    ->add('GET', '/login', [AuthController::class, 'login'], [], "authpage")
    ->add('POST', '/login', [AuthController::class, 'sigin'])
    ->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout')
    ->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration')
    ->add('POST', '/registration', [AuthController::class, 'registration'], [], 'register_submit')
    ->endGroup();

$router->group('/notes')
    ->add("GET", '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes')
    ->add("POST", '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create')
    ->add("GET", '/{str:uid}/edit', [NoteController::class, 'edit'], [], 'edit_page')
    ->add("POST", '/{str:uid}/edit', [NoteController::class, 'update'], [], 'update_note')
    ->add("GET", '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note')
    ->endGroup();

$router->group('/tasks')
    ->add("GET", '/', [TaskController::class, 'index'], [LoginRequared::class], 'tasks')
    ->add("POST", '/', [TaskController::class, 'create'], [LoginRequared::class], 'task_create')
    ->add("POST", '/{str:uid}/update', [TaskController::class, 'update'], [], 'update_task')
    ->add("GET", '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class], 'delete_task')
    ->add("POST", '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class], 'add_subtask')
    ->add("POST", '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class], 'toggle_subtask')
    ->add("POST", '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class], 'delete_subtask')
    ->add("POST", '/category', [TaskController::class, 'createCategory'], [LoginRequared::class], 'create_category')
    ->add("POST", '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class], 'attach_category')
    ->add("DELETE", '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class], 'detach_category')
    ->endGroup();

$router->group('/profile')
   ->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class], 'profile')
   ->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class], 'profile-set')
   ->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class], 'profile-password-set')
   ->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class], 'profile-delete')
   ->endGroup();

$router->group('/files')
    // Главная страница файлового менеджера
    ->add('GET', '/', [FileController::class, 'index'], [LoginRequared::class], 'files')

    // Просмотр папки
    ->add('GET', '/folder/{int:folderId}/', [FileController::class, 'folder'], [LoginRequared::class], 'files_folder')

    // Создание папки
    ->add('POST', '/create-folder/', [FileController::class, 'createFolder'], [LoginRequared::class], 'files_create_folder')

    // Загрузка файла
    ->add('POST', '/upload/', [FileController::class, 'uploadFile'], [LoginRequared::class], 'files_upload')

    // Удаление файла/папки
    ->add('POST', '/delete/', [FileController::class, 'delete'], [LoginRequared::class], 'files_delete')

    // Переименование файла/папки
    ->add('POST', '/rename/', [FileController::class, 'rename'], [LoginRequared::class], 'files_rename')

    // Получение файла
    ->add('GET', '/get/{int:fileId}/', [FileController::class, 'getFile'], [LoginRequared::class], 'files_get')
    ->endGroup();

$router->group('/messenger')
    ->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class], 'messenger')
    ->add('POST', '/send_files', [MessagerController::class, 'uploadFile'], [LoginRequared::class])
    ->endGroup();

$router->group('/admin')
   ->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, IsAdmin::class], 'adminpanel')
   ->add('POST', '/', [AdminController::class, 'saveCustomFields'], [LoginRequared::class, IsAdmin::class], 'save_custom_fields')
   ->add('POST', '/users/toggle-status', [AdminController::class, 'toggleUserStatus'], [LoginRequared::class, IsAdmin::class], 'admin_toggle_user')
   ->add('POST', '/users/delete', [AdminController::class, 'deleteUser'], [LoginRequared::class, IsAdmin::class], 'admin_delete_user')
   ->endGroup();

$router->dispatch(); 