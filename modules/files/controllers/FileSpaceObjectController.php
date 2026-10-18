<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileUploadLimitService;
use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceObjectService;
use App\Services\Files\FileSpaceTransferService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Серверная граница изменений файлов с проверкой прав и версий. */
final class FileSpaceObjectController extends Controller
{
    public function mutate(Request $request, int $nodeId): void
    {
        try {
            $action = (string) $request->post('action', '');
            $payload = [];
            if ($action === 'rename') {
                $payload['name'] = (string) $request->post('name', '');
            }
            if ($action === 'move') {
                $payload = [
                    'parent_id' => $this->optionalId($request->post('destination_id', '')),
                    'expected_destination_version' => $this->positiveInt(
                        $request->post('expected_destination_version', '')
                    ),
                ];
            }
            $result = $this->objectService()->mutate(
                $this->actorId($request),
                $nodeId,
                $action,
                $this->positiveInt($request->post('expected_version', '')),
                $this->positiveInt($request->post('expected_parent_version', '')),
                $this->commandId($request),
                $payload
            );
            $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()], $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось изменить общий файл: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось изменить объект'], 500);
        }
    }

    public function replace(Request $request, int $fileId): void
    {
        try {
            $limits = new FileUploadLimitService();
            if ($limits->postLimitExceededByCurrentRequest()) {
                $this->json(['success' => false, 'message' => 'Превышен размер HTTP-запроса'], 413);
                return;
            }
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                throw new InvalidArgumentException('Выберите новую версию файла');
            }
            $result = $this->transferService()->replace(
                $this->actorId($request), $fileId, $file,
                $this->positiveInt($request->post('expected_version', '')),
                $this->positiveInt($request->post('expected_parent_version', '')),
                $this->commandId($request), $limits->configuredLimitBytes()
            );
            $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()], $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось заменить содержимое общего файла: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось заменить файл'], 500);
        }
    }

    private function objectService(): FileSpaceObjectService
    {
        $pdo = DatabaseManager::getInstance()->getPdo();
        return new FileSpaceObjectService($pdo, new FileSpaceAccessService($pdo));
    }

    private function transferService(): FileSpaceTransferService
    {
        $pdo = DatabaseManager::getInstance()->getPdo();
        return new FileSpaceTransferService($pdo, new FileSpaceAccessService($pdo));
    }

    private function actorId(Request $request): int
    {
        $actor = (int) $request->session('user_id', 0);
        if ($actor < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $actor;
    }

    private function positiveInt(mixed $input): int
    {
        $value = trim((string) $input);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('Требуется положительная версия или идентификатор');
        }
        return (int) $value;
    }

    private function optionalId(mixed $input): ?int
    {
        $value = trim((string) $input);
        return $value === '' ? null : $this->positiveInt($value);
    }

    private function commandId(Request $request): string
    {
        $id = trim((string) $request->post('command_id', ''));
        if ($id === '') {
            throw new InvalidArgumentException('Не указан идентификатор команды');
        }
        return $id;
    }

    private function status(Throwable $error, int $fallback): int
    {
        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
