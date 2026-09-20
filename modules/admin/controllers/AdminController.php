<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\FieldModel;
use App\Models\UserModel;
use App\Services\AdminUserService;
use App\Services\ListQuery;
use App\Services\PermissionService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class AdminController extends Controller
{
    private const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];
    private const MAX_CUSTOM_FIELDS = 50;
    /** @var array<string,string> */
    private const USER_SORT_COLUMNS = [
        'id' => 'id',
        'username' => 'username',
        'email' => 'email',
        'created_at' => 'created_at',
        'role' => 'role',
    ];

    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $query = ListQuery::fromRequest($request, self::USER_SORT_COLUMNS, 'id');
        try {
            $result = (new AdminUserService())->searchUsers(
                $actorId,
                $query['q'],
                $query['sort'],
                $query['direction'],
                $query['limit'],
                $query['offset']
            );
            $permissionService = new PermissionService();
            $canManageRoles = $permissionService->hasPermission($actorId, 'admin.roles.manage');
            $canViewAudit = $permissionService->hasPermission($actorId, 'admin.audit.view');
        } catch (DomainException $e) {
            http_response_code($this->exceptionStatus($e, 403));
            return;
        }

        $flash = $request->session('admin_flash');
        $request->unsetSession('admin_flash');

        $this->render_template('@admin/index', [
            'user' => $user,
            'customFields' => FieldModel::select()->orderBy('id', 'ASC')->get(),
            'users' => $result['items'],
            'pagination' => ListQuery::pagination($query, (int) $result['total']),
            'canManageRoles' => $canManageRoles,
            'canViewAudit' => $canViewAudit,
            'admin_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function saveCustomFields(Request $request): void
    {
        try {
            $incoming = $request->post('fields', []);
            if (!is_array($incoming)) {
                throw new InvalidArgumentException('Некорректный формат пользовательских полей');
            }
            if (count($incoming) > self::MAX_CUSTOM_FIELDS) {
                throw new InvalidArgumentException('Можно создать не более ' . self::MAX_CUSTOM_FIELDS . ' пользовательских полей');
            }

            $validated = [];
            $seenNames = [];
            foreach ($incoming as $fieldKey => $fieldData) {
                if (!is_array($fieldData)) {
                    throw new InvalidArgumentException('Некорректное описание пользовательского поля');
                }

                $isNew = str_starts_with((string) $fieldKey, 'new_');
                if (!$isNew && preg_match('/^[1-9][0-9]*$/', (string) $fieldKey) !== 1) {
                    throw new InvalidArgumentException('Некорректный идентификатор пользовательского поля');
                }

                $name = strtolower(trim((string) ($fieldData['field_name'] ?? '')));
                $label = trim((string) ($fieldData['field_label'] ?? ''));
                $type = strtolower(trim((string) ($fieldData['field_type'] ?? '')));
                $required = ($fieldData['is_required'] ?? null) === 'on' ? 1 : 0;

                if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $name) !== 1) {
                    throw new InvalidArgumentException('Техническое имя поля: 1–50 символов, латиница, цифры и _, начиная с буквы');
                }
                if ($label === '' || mb_strlen($label) > 100) {
                    throw new InvalidArgumentException('Метка поля должна содержать от 1 до 100 символов');
                }
                if (!in_array($type, self::FIELD_TYPES, true)) {
                    throw new InvalidArgumentException('Недопустимый тип пользовательского поля');
                }
                if (isset($seenNames[$name])) {
                    throw new InvalidArgumentException('Технические имена пользовательских полей должны быть уникальными');
                }
                $seenNames[$name] = true;

                $validated[] = [
                    'id' => $isNew ? null : (int) $fieldKey,
                    'field_name' => $name,
                    'field_label' => $label,
                    'field_type' => $type,
                    'is_required' => $required,
                ];
            }

            $db = DatabaseManager::getInstance();
            $db->beginTransaction();
            try {
                $existingRows = $db->fetchAll('SELECT id FROM user_fields FOR UPDATE');
                $existingIds = [];
                foreach ($existingRows as $row) {
                    $existingIds[(int) $row['id']] = true;
                }

                $keptIds = [];
                foreach ($validated as $field) {
                    if ($field['id'] === null) {
                        $db->execute(
                            'INSERT INTO user_fields (field_name,field_type,field_label,is_required,created_at,updated_at) '
                            . 'VALUES (:field_name,:field_type,:field_label,:is_required,:created_at,:updated_at)',
                            [
                                ':field_name' => $field['field_name'],
                                ':field_type' => $field['field_type'],
                                ':field_label' => $field['field_label'],
                                ':is_required' => $field['is_required'],
                                ':created_at' => date('Y-m-d H:i:s'),
                                ':updated_at' => date('Y-m-d H:i:s'),
                            ]
                        );
                        continue;
                    }

                    $fieldId = (int) $field['id'];
                    if (!isset($existingIds[$fieldId])) {
                        throw new InvalidArgumentException('Одно из пользовательских полей больше не существует');
                    }
                    $keptIds[$fieldId] = true;
                    $db->execute(
                        'UPDATE user_fields '
                        . 'SET field_name = :field_name, field_type = :field_type, field_label = :field_label, '
                        . 'is_required = :is_required, updated_at = :updated_at WHERE id = :id',
                        [
                            ':field_name' => $field['field_name'],
                            ':field_type' => $field['field_type'],
                            ':field_label' => $field['field_label'],
                            ':is_required' => $field['is_required'],
                            ':updated_at' => date('Y-m-d H:i:s'),
                            ':id' => $fieldId,
                        ]
                    );
                }

                foreach (array_keys($existingIds) as $existingId) {
                    if (!isset($keptIds[$existingId])) {
                        $db->execute('DELETE FROM user_fields WHERE id = :id', [':id' => $existingId]);
                    }
                }

                $db->endTransaction(true);
            } catch (Throwable $e) {
                $db->endTransaction(false);
                throw $e;
            }

            $this->respondAdminAction($request, true, 'Пользовательские поля сохранены');
        } catch (InvalidArgumentException $e) {
            $this->respondAdminAction($request, false, $e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Admin custom fields update failed: ' . $e->getMessage());
            $this->respondAdminAction($request, false, 'Не удалось сохранить пользовательские поля', 500);
        }
    }

    public function toggleUserStatus(Request $request): void
    {
        try {
            $message = (new AdminUserService())->setStatus(
                (int) $request->session('user_id', 0),
                (int) $request->post('user_id', 0),
                (string) $request->post('new_status', '')
            );
            $this->respondAdminAction($request, true, $message);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respondAdminAction($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Admin status update failed: ' . $e->getMessage());
            $this->respondAdminAction($request, false, 'Не удалось изменить статус пользователя', 500);
        }
    }

    /**
     * Legacy endpoint name kept for route compatibility. The operation is a safe
     * account deactivation: related Notes/Tasks/Messenger data are preserved.
     */
    public function deleteUser(Request $request): void
    {
        try {
            $message = (new AdminUserService())->deactivate(
                (int) $request->session('user_id', 0),
                (int) $request->post('user_id', 0)
            );
            $this->respondAdminAction($request, true, $message);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->respondAdminAction($request, false, $e->getMessage(), $this->exceptionStatus($e, 422));
        } catch (Throwable $e) {
            error_log('Admin deactivation failed: ' . $e->getMessage());
            $this->respondAdminAction($request, false, 'Не удалось деактивировать пользователя', 500);
        }
    }

    private function exceptionStatus(Throwable $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function respondAdminAction(Request $request, bool $success, string $message, int $status = 200): void
    {
        $isAjax = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';
        if ($isAjax) {
            http_response_code($status);
            $this->responseJson(['success' => $success, 'message' => $message]);
            return;
        }

        $request->setSession('admin_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('adminpanel');
    }
}
