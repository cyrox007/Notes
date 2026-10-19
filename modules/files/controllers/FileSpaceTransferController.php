<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileUploadLimitService;
use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceTransferService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Все общие файлы передаются только через этот контроллер с проверкой ACL. */
final class FileSpaceTransferController extends Controller
{
    public function upload(Request $request): void
    {
        try {
            $limits = new FileUploadLimitService();
            if ($limits->postLimitExceededByCurrentRequest()) {
                $this->json(['success' => false, 'message' => 'Превышен лимит HTTP-запроса'], 413);
                return;
            }
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                throw new InvalidArgumentException('Выберите файл для загрузки');
            }
            $result = $this->service()->upload(
                $this->actorId($request),
                $this->folderId($request->post('parent_id', '')),
                $file,
                $this->expectedVersion($request),
                $this->commandId($request),
                $limits->configuredLimitBytes()
            );
            $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()], $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Ошибка передачи общего файла: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось загрузить файл'], 500);
        }
    }

    public function download(Request $request, int $fileId): void
    {
        try {
            $file = $this->service()->download($this->actorId($request), $fileId);
            $name = str_replace(["\r", "\n", '"', '\\', '/'], '_', $file['name']);
            if ($name === '') {
                $name = 'file';
            }
            $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'file';
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
            header('Referrer-Policy: no-referrer');
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Disposition: attachment; filename="' . $ascii
                . '"; filename*=UTF-8' . "''" . rawurlencode($name));
            header('Content-Length: ' . $file['size']);
            readfile($file['path']);
        } catch (DomainException|InvalidArgumentException $error) {
            http_response_code($this->status($error, 404));
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Файл не найден или недоступен';
        } catch (Throwable $error) {
            error_log('Ошибка скачивания общего файла: ' . $error->getMessage());
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Файл временно недоступен';
        }
    }

    private function service(): FileSpaceTransferService
    {
        $db = DatabaseManager::getInstance();
        return new FileSpaceTransferService($db, new FileSpaceAccessService($db));
    }

    private function actorId(Request $request): int
    {
        $id = (int) $request->session('user_id', 0);
        if ($id < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $id;
    }

    private function folderId(mixed $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректная папка');
        }
        return (int) $value;
    }

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->post('expected_version', ''));
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Требуется актуальная версия каталога');
        }
        return (int) $value;
    }

    private function commandId(Request $request): string
    {
        $id = trim((string) $request->post('command_id', ''));
        if ($id === '') {
            throw new InvalidArgumentException('Требуется идентификатор команды');
        }
        return $id;
    }

    private function status(Throwable $error, int $fallback): int
    {
        $status = (int) $error->getCode();
        return $status >= 400 && $status <= 599 ? $status : $fallback;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
