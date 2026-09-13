<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\FileModel;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use Exception;

/**
 * Контроллер файлового менеджера.
 * Пользовательские файлы сохраняются вне document root и выдаются только
 * через getFile() после ownership-проверки.
 */
class FileController extends Controller
{
    private const DEFAULT_MAX_UPLOAD_SIZE = 10 * 1024 * 1024;

    /** @var array<string, array<int, string>> */
    private const ALLOWED_UPLOADS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'avi' => ['video/x-msvideo', 'application/octet-stream'],
        'mov' => ['video/quicktime'],
        'mkv' => ['video/x-matroska', 'application/octet-stream'],
    ];

    public function index(Request $request): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        $files = FileModel::select()
            ->where('user_id', '=', $user->id)
            ->where('parent_id', 'IS', null)
            ->where('is_deleted', '=', 0)
            ->orderBy('type', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        $this->render_template('file_manager/index', [
            'user' => $user,
            'files' => $files,
            'current_folder' => null,
            'breadcrumb' => [['name' => 'Главная', 'id' => 0]],
        ]);
    }

    public function folder(Request $request, int $folderId): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

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

        $files = FileModel::select()
            ->where('user_id', '=', $user->id)
            ->where('parent_id', '=', $folderId)
            ->where('is_deleted', '=', 0)
            ->orderBy('type', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        $this->render_template('file_manager/index', [
            'user' => $user,
            'files' => $files,
            'current_folder' => $folder,
            'breadcrumb' => $this->buildBreadcrumb($folderId, (int) $user->id),
        ]);
    }

    public function createFolder(Request $request): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $folderName = trim((string) $request->post('name', ''));
        $parentId = (int) $request->post('parent_id', 0);

        if ($folderName === '' || mb_strlen($folderName) > 190) {
            $this->jsonError('Некорректное имя папки', 422);
            return;
        }

        if ($parentId === 0) {
            $parentId = null;
        } else {
            $parent = FileModel::select()
                ->where('id', '=', $parentId)
                ->where('user_id', '=', $user->id)
                ->where('type', '=', 'folder')
                ->where('is_deleted', '=', 0)
                ->first();
            if (!$parent) {
                $this->jsonError('Родительская папка не найдена', 404);
                return;
            }
        }

        try {
            $newFolder = [
                'uid' => bin2hex(random_bytes(32)),
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'name' => $folderName,
                'type' => 'folder',
                'mime_type' => 'directory',
                'size' => 0,
                'path' => null,
                'extension' => null,
            ];

            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueInsert($newFolder, 'user_files');
            $result = $dbManager->commit();

            if ($result && is_array($result) && !empty($result[0]['last_insert_id'])) {
                $newFolder['id'] = $result[0]['last_insert_id'];
            } else {
                $createdFolder = FileModel::select()
                    ->where('uid', '=', $newFolder['uid'])
                    ->where('user_id', '=', $user->id)
                    ->first();
                if ($createdFolder) {
                    $newFolder['id'] = $createdFolder->id;
                }
            }

            $this->jsonSuccess(['message' => 'Папка создана успешно', 'folder' => $newFolder]);
        } catch (Exception $e) {
            error_log('Error creating folder: ' . $e->getMessage());
            $this->jsonError('Ошибка при создании папки', 500);
        }
    }

    public function uploadFile(Request $request): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            $this->jsonError('Файл не выбран', 422);
            return;
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->jsonError($this->uploadErrorMessage($uploadError), 422);
            return;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->jsonError('Некорректная загрузка файла', 422);
            return;
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = $this->maxUploadSize();
        if ($size <= 0 || $size > $maxSize) {
            $this->jsonError('Размер файла превышает лимит ' . $this->formatBytes($maxSize), 413);
            return;
        }

        $parentId = (int) $request->post('parent_id', 0);
        if ($parentId > 0) {
            $parent = FileModel::select()
                ->where('id', '=', $parentId)
                ->where('user_id', '=', $user->id)
                ->where('type', '=', 'folder')
                ->where('is_deleted', '=', 0)
                ->first();
            if (!$parent) {
                $this->jsonError('Папка не найдена', 404);
                return;
            }
        } else {
            $parentId = null;
        }

        $originalName = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $nameWithoutExt = trim((string) pathinfo($originalName, PATHINFO_FILENAME));

        if ($extension === '' || !array_key_exists($extension, self::ALLOWED_UPLOADS)) {
            $this->jsonError('Файлы данного типа запрещены', 415);
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($tmpName);
        if ($mimeType === '' || !in_array($mimeType, self::ALLOWED_UPLOADS[$extension], true)) {
            $this->jsonError('Расширение файла не соответствует его содержимому', 415);
            return;
        }

        $storageRoot = $this->privateStorageRoot();
        $userDir = $storageRoot . DIRECTORY_SEPARATOR . 'file_manager' . DIRECTORY_SEPARATOR . (int) $user->id . DIRECTORY_SEPARATOR . 'files';
        if (!is_dir($userDir) && !mkdir($userDir, 0700, true) && !is_dir($userDir)) {
            $this->jsonError('Не удалось подготовить защищённое хранилище', 500);
            return;
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $extension;
        $filePath = $userDir . DIRECTORY_SEPARATOR . $storedName;

        try {
            if (!move_uploaded_file($tmpName, $filePath)) {
                throw new Exception('Failed to move uploaded file');
            }
            @chmod($filePath, 0600);

            $newFile = [
                'uid' => bin2hex(random_bytes(32)),
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'name' => $nameWithoutExt !== '' ? mb_substr($nameWithoutExt, 0, 190) : 'file',
                'type' => $this->detectFileType($extension, $mimeType),
                'mime_type' => $mimeType,
                'size' => $size,
                'path' => $filePath,
                'extension' => $extension,
            ];

            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueInsert($newFile, 'user_files');
            $dbManager->commit();
            $this->jsonSuccess(['message' => 'Файл загружен успешно', 'file' => $newFile]);
        } catch (Exception $e) {
            error_log('Error uploading file: ' . $e->getMessage());
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            $this->jsonError('Ошибка при загрузке файла', 500);
        }
    }

    public function delete(Request $request): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $fileId = (int) $request->post('id', 0);
        if ($fileId <= 0) {
            $this->jsonError('Неверный ID', 422);
            return;
        }

        $file = FileModel::select()->where('id', '=', $fileId)->where('user_id', '=', $user->id)->first();
        if (!$file) {
            $this->jsonError('Файл не найден', 404);
            return;
        }

        try {
            if ($file->type !== 'folder' && !empty($file->path)) {
                $fullPath = $this->resolveStoredPath((string) $file->path);
                if ($fullPath !== null && is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }

            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueUpdate(['is_deleted' => 1], 'user_files', $fileId);
            $dbManager->commit();
            $this->jsonSuccess(['message' => 'Удалено успешно']);
        } catch (Exception $e) {
            error_log('Error deleting file: ' . $e->getMessage());
            $this->jsonError('Ошибка при удалении', 500);
        }
    }

    public function rename(Request $request): void
    {
        $user = $this->currentUser($request);
        if (!$user) {
            $this->jsonError('Unauthorized', 401);
            return;
        }

        $fileId = (int) $request->post('id', 0);
        $newName = trim((string) $request->post('name', ''));
        if ($fileId <= 0 || $newName === '' || mb_strlen($newName) > 190) {
            $this->jsonError('Неверные данные', 422);
            return;
        }

        $file = FileModel::select()->where('id', '=', $fileId)->where('user_id', '=', $user->id)->first();
        if (!$file) {
            $this->jsonError('Файл не найден', 404);
            return;
        }

        try {
            $dbManager = DatabaseManager::getInstance();
            $dbManager->queueUpdate(['name' => $newName], 'user_files', $fileId);
            $dbManager->commit();
            $this->jsonSuccess(['message' => 'Переименовано успешно']);
        } catch (Exception $e) {
            error_log('Error renaming file: ' . $e->getMessage());
            $this->jsonError('Ошибка при переименовании', 500);
        }
    }

    public function getFile(Request $request, int $fileId): void
    {
        $user = $this->currentUser($request);
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

        if (!$file || $file->type === 'folder' || empty($file->path)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $fullPath = $this->resolveStoredPath((string) $file->path);
        if ($fullPath === null || !is_file($fullPath)) {
            http_response_code(404);
            echo 'File not found on disk';
            return;
        }

        $safeName = preg_replace('/[\r\n"\\]+/', '_', (string) $file->name) ?: 'file';
        $extension = preg_replace('/[^a-z0-9]+/i', '', (string) $file->extension);
        $downloadName = $safeName . ($extension !== '' ? '.' . $extension : '');
        $disposition = in_array((string) $file->type, ['image', 'audio', 'video'], true) ? 'inline' : 'attachment';

        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . (string) $file->mime_type);
        header('Content-Length: ' . (string) filesize($fullPath));
        header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
        header('Cache-Control: private, no-store, max-age=0');
        readfile($fullPath);
        exit;
    }

    private function currentUser(Request $request): ?object
    {
        $userId = (int) $request->session('user_id', 0);
        return $userId > 0 ? UserModel::select()->where('id', '=', $userId)->first() : null;
    }

    private function privateStorageRoot(): string
    {
        $configured = getenv('PRIVATE_STORAGE_PATH');
        $root = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function resolveStoredPath(string $storedPath): ?string
    {
        $candidate = $this->isAbsolutePath($storedPath)
            ? $storedPath
            : rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');

        $realPath = realpath($candidate);
        if ($realPath === false) {
            return null;
        }

        $allowedRoots = [];
        $privateRoot = realpath($this->privateStorageRoot());
        if ($privateRoot !== false) {
            $allowedRoots[] = rtrim($privateRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }
        $legacyRoot = realpath(rtrim(SITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager');
        if ($legacyRoot !== false) {
            $allowedRoots[] = rtrim($legacyRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        foreach ($allowedRoots as $root) {
            if (str_starts_with(rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $root)) {
                return $realPath;
            }
        }

        error_log('Blocked file path outside storage roots: ' . $storedPath);
        return null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function detectFileType(string $extension, string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) return 'image';
        if (str_starts_with($mimeType, 'audio/')) return 'audio';
        if (str_starts_with($mimeType, 'video/')) return 'video';
        if ($extension === 'pdf' || in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'], true)) return 'document';
        return 'file';
    }

    private function maxUploadSize(): int
    {
        $configured = getenv('MAX_UPLOAD_SIZE');
        return is_string($configured) && ctype_digit($configured) && (int) $configured > 0
            ? (int) $configured
            : self::DEFAULT_MAX_UPLOAD_SIZE;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер',
            UPLOAD_ERR_PARTIAL => 'Файл загружен не полностью',
            UPLOAD_ERR_NO_FILE => 'Файл не выбран',
            UPLOAD_ERR_NO_TMP_DIR => 'На сервере отсутствует временная директория',
            UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл',
            UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением PHP',
            default => 'Ошибка загрузки файла',
        };
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / (1024 * 1024), 1) . ' МБ' : round($bytes / 1024, 1) . ' КБ';
    }

    /** @param array<string, mixed> $payload */
    private function jsonSuccess(array $payload = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }

    private function buildBreadcrumb(int $folderId, int $userId): array
    {
        $breadcrumb = [['name' => 'Главная', 'id' => 0]];
        $folders = [];
        $currentId = $folderId;
        $visited = [];

        while ($currentId > 0 && !isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $folder = FileModel::select()
                ->where('id', '=', $currentId)
                ->where('user_id', '=', $userId)
                ->where('type', '=', 'folder')
                ->where('is_deleted', '=', 0)
                ->first();
            if (!$folder) break;
            $folders[] = ['name' => $folder->name, 'id' => $folder->id];
            $currentId = (int) ($folder->parent_id ?? 0);
        }

        return array_merge($breadcrumb, array_reverse($folders));
    }
}
