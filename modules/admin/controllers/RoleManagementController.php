<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\RoleManagementService;
use App\Services\RolePolicyService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class RoleManagementController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = $actorId > 0 ? UserModel::select()->where('id', '=', $actorId)->first() : null;
        if (!$user) {
            http_response_code(401);
            return;
        }

        $loadError = null;
        try {
            $snapshot = (new RoleManagementService())->snapshot($actorId);
        } catch (DomainException $e) {
            http_response_code($this->exceptionStatus($e, 403));
            return;
        } catch (Throwable $e) {
            error_log('Загрузка управления ролями завершилась ошибкой: ' . $e->getMessage());
            http_response_code(503);
            $snapshot = [
                'roles' => [],
                'permissions' => [],
                'users' => [],
                'policy_definitions' => [],
            ];
            $loadError = 'Не удалось загрузить роли. Проверьте состояние миграций базы данных командой php bin/migrate.php --status и журнал PHP.';
        }

        $flash = $request->session('admin_roles_flash');
        $request->unsetSession('admin_roles_flash');
        $this->render_template('@admin/roles', [
            'user' => $user,
            'roles' => $snapshot['roles'],
            'permissions' => $snapshot['permissions'],
            'roleUsers' => $snapshot['users'],
            'policyDefinitions' => $snapshot['policy_definitions'],
            'admin_roles_flash' => is_array($flash) ? $flash : null,
            'admin_roles_load_error' => $loadError,
        ]);
    }

    public function create(Request $request): void
    {
        try {
            $roleId = (new RoleManagementService())->createRole(
                (int) $request->session('user_id', 0),
                (string) $request->post('code', ''),
                (string) $request->post('name', ''),
                (string) $request->post('description', '')
            );
            $this->respond($request, true, 'Роль создана', 200, ['role_id' => $roleId]);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respond($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Role creation failed: ' . $e->getMessage());
            $this->respond($request, false, 'Не удалось создать роль', 500);
        }
    }

    public function update(Request $request): void
    {
        try {
            $permissions = $request->post('permission_codes', []);
            if (!is_array($permissions)) {
                throw new InvalidArgumentException('Некорректный набор разрешений', 422);
            }
            (new RoleManagementService())->updateRole(
                (int) $request->session('user_id', 0),
                (int) $request->post('role_id', 0),
                (string) $request->post('name', ''),
                (string) $request->post('description', ''),
                array_values(array_map('strval', $permissions))
            );
            $this->respond($request, true, 'Роль и разрешения сохранены');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respond($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Role update failed: ' . $e->getMessage());
            $this->respond($request, false, 'Не удалось сохранить роль', 500);
        }
    }

    public function savePolicies(Request $request): void
    {
        try {
            $policies = $request->post('policies', []);
            if (!is_array($policies)) {
                throw new InvalidArgumentException('Некорректный набор политик', 422);
            }
            (new RolePolicyService())->replaceRolePolicies(
                (int) $request->session('user_id', 0),
                (int) $request->post('role_id', 0),
                $policies
            );
            $this->respond($request, true, 'Ограничения модулей сохранены');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respond($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Role policies update failed: ' . $e->getMessage());
            $this->respond($request, false, 'Не удалось сохранить ограничения роли', 500);
        }
    }

    public function assign(Request $request): void
    {
        try {
            $roleIds = $request->post('role_ids', []);
            if (!is_array($roleIds)) {
                throw new InvalidArgumentException('Некорректный список ролей', 422);
            }
            (new RoleManagementService())->assignRoles(
                (int) $request->session('user_id', 0),
                (int) $request->post('user_id', 0),
                array_values($roleIds)
            );
            $this->respond($request, true, 'Роли пользователя обновлены');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respond($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Role assignment failed: ' . $e->getMessage());
            $this->respond($request, false, 'Не удалось изменить роли пользователя', 500);
        }
    }

    public function delete(Request $request): void
    {
        try {
            (new RoleManagementService())->deleteRole(
                (int) $request->session('user_id', 0),
                (int) $request->post('role_id', 0)
            );
            $this->respond($request, true, 'Роль удалена');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respond($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Role deletion failed: ' . $e->getMessage());
            $this->respond($request, false, 'Не удалось удалить роль', 500);
        }
    }

    private function exceptionStatus(Throwable $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    /** @param array<string,mixed> $payload */
    private function respond(Request $request, bool $success, string $message, int $status = 200, array $payload = []): void
    {
        $isAjax = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code($status);
            $this->responseJson(['success' => $success, 'message' => $message] + $payload);
            return;
        }

        $request->setSession('admin_roles_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('admin_roles');
    }
}
