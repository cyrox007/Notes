<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FileModel;
use App\Models\UserModel;
use Core\Controller;
use Core\Request;
use Core\Router;
use Core\DatabaseManager;
use Exception;

/**
 * Контроллер для управления файлами пользователей
 */
class FileController extends Controller
{

    /**
     * Главная страница файлового менеджера
     */
    public function index(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        // Получаем корневые файлы и папки пользователя
        $files = FileModel::select()
            ->where('user_id', '=', $user->id)
            ->where('parent_id', 'IS', null)
            ->where('is_deleted', '=', 0)
            ->orderBy('type', 'DESC') // Сначала папки
            ->orderBy('name', 'ASC')
            ->get();

        $data = [
            'user' => $user,
            'files' => $files,
            'current_folder' => null,
            'breadcrumb' => [['name' => 'Главная', 'id' => 0]]
        ];

        $this->render_template('file_manager/index', $data);
    }

    /**
     * Просмотр содержимого папки
     */
    public function folder(Request $request, int $folderId): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        // Получаем текущую папку
        $folder = FileModel::select()
            ->where('id', '=', $folderId)
            ->where('user_id', '=', $user->id)
            ->where('type', '=', 'folder')
            ->where('is_deleted', '=', 0)
            ->first();

        if (!$folder) {
            Router::getInstance()->redirect('files');
            return;
        }

        // Получаем файлы в папке
        $files = FileModel::select()
            ->where('user_id', '=', $user->id)
            ->where('parent_id', '=', $folderId)
            ->where('is_deleted', '=', 0)
            ->orderBy('type', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        // Строим хлебные крошки
        $breadcrumb = $this->buildBreadcrumb($folderId, $user->id);

        $data = [
            'user' => $user,
            'files' => $files,
            'current_folder' => $folder,
            'breadcrumb' => $breadcrumb
        ];

        $this->render_template('file_manager/index', $data);
    }

    /**
     * Создание новой папки
     */
    public function createFolder(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $postData = $request->post();
        $folderName = trim($postData['name'] ?? '');
        // Получаем parent_id, но пока оставляем как есть для проверки
        $parentIdInput = $postData['parent_id'] ?? 0;
        $parentId = (int)$parentIdInput;

        if (empty($folderName)) {
            echo json_encode(['success' => false, 'message' => 'Имя папки не может быть пустым']);
            return;
        }

        // ЛОГИКА ИСПРАВЛЕНИЯ:
        // Если передан 0 или пустое значение, значит это корень -> ставим NULL
        if ($parentId == 0) {
            $parentId = null;
        } else {
            // Если передан конкретный ID, проверяем его существование
            $parent = FileModel::select()
                ->where('id', '=', $parentId)
                ->where('user_id', '=', $user->id)
                ->where('type', '=', 'folder')
                ->first();

            if (!$parent) {
                echo json_encode(['success' => false, 'message' => 'Родительская папка не найдена']);
                return;
            }
        }

        try {
            $dbManager = DatabaseManager::getInstance();

            $newFolder = [
                'uid' => bin2hex(random_bytes(32)),
                'user_id' => $user->id,
                'parent_id' => $parentId, // Теперь здесь точно NULL для корня
                'name' => $folderName,
                'type' => 'folder',
                'mime_type' => 'directory',
                'size' => 0,
                'path' => null,
                'extension' => null
            ];

            $dbManager->queueInsert($newFolder, 'user_files');
            $result = $dbManager->commit();

            // Получаем ID созданной папки
            if ($result && is_array($result) && !empty($result[0]['last_insert_id'])) {
                $newFolder['id'] = $result[0]['last_insert_id'];
            } else {
                // Если не удалось получить ID, пробуем найти папку по uid
                $createdFolder = FileModel::select()
                    ->where('uid', '=', $newFolder['uid'])
                    ->first();
                if ($createdFolder) {
                    $newFolder['id'] = $createdFolder->id;
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Папка создана успешно',
                'folder' => $newFolder
            ]);
        } catch (Exception $e) {
            error_log("Error creating folder: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Ошибка при создании папки: ' . $e->getMessage()]);
        }
    }

    /**
     * Загрузка файла
     */
    public function uploadFile(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        if (empty($_FILES['file']['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => 'Файл не выбран']);
            return;
        }

        $parentId = (int)($request->post('parent_id') ?? 0);
        $file = $_FILES['file'];

        // Проверка родительской папки
        if ($parentId > 0) {
            $parent = FileModel::select()
                ->where('id', '=', $parentId)
                ->where('user_id', '=', $user->id)
                ->where('type', '=', 'folder')
                ->first();

            if (!$parent) {
                echo json_encode(['success' => false, 'message' => 'Папка не найдена']);
                return;
            }
        } else {
            // Для корневой директории устанавливаем NULL вместо 0
            $parentId = null;
        }

        // Безопасное имя файла
        $fileName = basename($file['name']);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);

        // MIME тип
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        // Путь для сохранения
        $uploadDir = getenv('UPLOAD_DIR') ?: 'uploads';
        $userDir = SITEPATH . DIRECTORY_SEPARATOR . $uploadDir . DIRECTORY_SEPARATOR . $user->id . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;

        if (!is_dir($userDir)) {
            mkdir($userDir, 0755, true);
        }

        // Уникальное имя файла
        $uniqueName = uniqid() . '_' . $fileName;
        $filePath = $userDir . $uniqueName;
        $relativePath = DIRECTORY_SEPARATOR . $uploadDir . DIRECTORY_SEPARATOR . $user->id . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $uniqueName;

        try {
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception('Failed to move uploaded file');
            }

            $dbManager = DatabaseManager::getInstance();

            $newFile = [
                'uid' => bin2hex(random_bytes(32)),
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'name' => $nameWithoutExt,
                'type' => 'file',
                'mime_type' => $mimeType,
                'size' => $file['size'],
                'path' => $relativePath,
                'extension' => $extension
            ];

            $dbManager->queueInsert($newFile, 'user_files');
            $dbManager->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Файл загружен успешно',
                'file' => $newFile
            ]);
        } catch (Exception $e) {
            error_log("Error uploading file: " . $e->getMessage());
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            echo json_encode(['success' => false, 'message' => 'Ошибка при загрузке файла']);
        }
    }

    /**
     * Удаление файла или папки
     */
    public function delete(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $fileId = (int)($request->post('id') ?? 0);

        if ($fileId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Неверный ID']);
            return;
        }

        $file = FileModel::select()
            ->where('id', '=', $fileId)
            ->where('user_id', '=', $user->id)
            ->first();

        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'Файл не найден']);
            return;
        }

        try {
            $dbManager = DatabaseManager::getInstance();

            // Если это файл, удаляем физически
            if ($file->type === 'file' && !empty($file->path)) {
                $fullPath = SITEPATH . $file->path;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            // Safe delete - помечаем как удаленный
            $dbManager->queueUpdate(['is_deleted' => 1], 'user_files', $fileId);
            $dbManager->commit();

            echo json_encode(['success' => true, 'message' => 'Удалено успешно']);
        } catch (Exception $e) {
            error_log("Error deleting file: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Ошибка при удалении']);
        }
    }

    /**
     * Переименование файла или папки
     */
    public function rename(Request $request): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $fileId = (int)($request->post('id') ?? 0);
        $newName = trim($request->post('name') ?? '');

        if ($fileId <= 0 || empty($newName)) {
            echo json_encode(['success' => false, 'message' => 'Неверные данные']);
            return;
        }

        $file = FileModel::select()
            ->where('id', '=', $fileId)
            ->where('user_id', '=', $user->id)
            ->first();

        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'Файл не найден']);
            return;
        }

        try {
            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueUpdate(['name' => $newName], 'user_files', $fileId);
            $dbManager->commit();

            echo json_encode(['success' => true, 'message' => 'Переименовано успешно']);
        } catch (Exception $e) {
            error_log("Error renaming file: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Ошибка при переименовании']);
        }
    }

    /**
     * Получение файла для просмотра/скачивания
     */
    public function getFile(Request $request, int $fileId): void
    {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        if (!$user) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $file = FileModel::select()
            ->where('id', '=', $fileId)
            ->where('user_id', '=', $user->id)
            ->where('is_deleted', '=', 0)
            ->first();

        if (!$file || $file->type !== 'file') {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $fullPath = SITEPATH . $file->path;

        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo 'File not found on disk';
            return;
        }

        // Отдаем файл с правильными заголовками
        header('Content-Type: ' . $file->mime_type);
        header('Content-Length: ' . $file->size);
        header('Content-Disposition: inline; filename="' . $file->name . '.' . $file->extension . '"');

        readfile($fullPath);
        exit;
    }

    /**
     * Построение хлебных крошек
     */
    private function buildBreadcrumb(int $folderId, int $userId): array
    {
        $breadcrumb = [['name' => 'Главная', 'id' => 0]];

        if ($folderId === 0) {
            return $breadcrumb;
        }

        $folders = [];
        $currentId = $folderId;

        while ($currentId > 0) {
            $folder = FileModel::select()
                ->where('id', '=', $currentId)
                ->where('user_id', '=', $userId)
                ->first();

            if (!$folder) {
                break;
            }

            $folders[] = ['name' => $folder->name, 'id' => $folder->id];
            $currentId = $folder->parent_id;
        }

        $folders = array_reverse($folders);
        return array_merge($breadcrumb, $folders);
    }
}
