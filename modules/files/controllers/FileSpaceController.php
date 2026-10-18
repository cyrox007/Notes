<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceFolderService;
use App\Services\Files\FileSpaceObjectService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Общий файловый каталог. Административные права не зависят от кнопок интерфейса.
 */
final class FileSpaceController extends Controller
{
    public function index(Request $request): void
    {
        try {
            $actorId = $this->actorId($request);
            $folderId = $this->folderId($request->get('folder', ''));
            $service = $this->service();
            $catalog = $service->browse($actorId, $folderId, (string) $request->get('cursor', ''));
            $rules = $catalog['can_manage'] ? $service->rules($actorId, $folderId) : [];
            $trash = (new FileSpaceObjectService(
                DatabaseManager::getInstance()->getPdo(),
                new FileSpaceAccessService(DatabaseManager::getInstance()->getPdo())
            ))->trash($actorId);
            $db = DatabaseManager::getInstance();
            $roles = $catalog['can_manage']
                ? $db->fetchAll('SELECT id,code,name FROM roles ORDER BY name,id') : [];
            $users = $catalog['can_manage']
                ? $db->fetchAll(
                    "SELECT id,username FROM users
                     WHERE is_active=1 AND account_status='active' ORDER BY username LIMIT 200"
                ) : [];
            $groups = $catalog['can_manage']
                ? $db->fetchAll('SELECT id,name FROM file_access_groups ORDER BY name LIMIT 200') : [];
            $user = UserModel::select()->where('id', '=', $actorId)->first();
            $this->render_template('@files/spaces', [
                'user' => $user,
                'catalog' => $catalog,
                'can_admin_files' => (new FileSpaceAccessService(DatabaseManager::getInstance()->getPdo()))
                    ->actor($actorId)['is_admin'],
                'acl_rules' => $rules,
                'trash_items' => $trash,
                'available_roles' => $roles,
                'available_groups' => $groups,
                'available_users' => $users,
            ]);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->errorResponse($error->getMessage(), $this->status($error, 404));
        } catch (Throwable $error) {
            error_log('Не удалось открыть файловое пространство: ' . $error->getMessage());
            $this->errorResponse('Не удалось открыть общие файлы', 500);
        }
    }

    public function createFolder(Request $request): void
    {
        try {
            $result = $this->service()->createFolder(
                $this->actorId($request),
                $this->folderId($request->post('parent_id', '')),
                (string) $request->post('name', ''),
                $this->expectedVersion($request),
                $this->commandId($request)
            );
            $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()], $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось создать общую папку: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось создать папку'], 500);
        }
    }

    public function setAcl(Request $request): void
    {
        try {
            $inherit = trim((string) $request->post('inherit_acl', ''));
            if (!in_array($inherit, ['', '0', '1'], true)) {
                throw new InvalidArgumentException('Некорректный режим наследования');
            }
            $result = $this->service()->setRule(
                $this->actorId($request),
                $this->folderId($request->post('folder_id', '')),
                trim((string) $request->post('subject_type', '')),
                $this->subjectId($request),
                trim((string) $request->post('permission', '')),
                trim((string) $request->post('effect', '')),
                $this->expectedVersion($request),
                $this->commandId($request),
                $inherit === '' ? null : $inherit === '1'
            );
            $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()], $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Не удалось изменить права общей папки: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось изменить права доступа'], 500);
        }
    }

    private function actorId(Request $request): int
    {
        $id = (int) $request->session('user_id', 0);
        if ($id < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $id;
    }

    private function subjectId(Request $request): int
    {
        $raw = trim((string) $request->post('subject_id', '0'));
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $raw)) {
            throw new InvalidArgumentException('Некорректный идентификатор субъекта');
        }
        return (int) $raw;
    }

    private function folderId(mixed $raw): ?int
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException('Некорректный номер папки');
        }
        return (int) $value;
    }

    private function commandId(Request $request): string
    {
        $id = trim((string) $request->post('command_id', ''));
        if ($id === '') {
            throw new InvalidArgumentException('Не указан идентификатор действия');
        }
        return $id;
    }

    private function expectedVersion(Request $request): int
    {
        $raw = trim((string) $request->post('expected_version', ''));
        if (!preg_match('/^[1-9][0-9]*$/D', $raw)) {
            throw new InvalidArgumentException('Нужна актуальная версия каталога');
        }
        return (int) $raw;
    }

    private function service(): FileSpaceFolderService
    {
        $pdo = DatabaseManager::getInstance()->getPdo();
        return new FileSpaceFolderService($pdo, new FileSpaceAccessService($pdo));
    }

    private function status(Throwable $error, int $fallback): int
    {
        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function errorResponse(string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
