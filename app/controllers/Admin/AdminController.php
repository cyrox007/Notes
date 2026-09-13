<?php 
namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\FieldModel;
use Core\Config;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

class AdminController extends Controller {
    public function index(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        $fields = FieldModel::select()->get();
        $users = UserModel::select()->get();
        
        $data['user'] = $user;
        $data['customFields'] = $fields;
        $data['users'] = $users;
        return $this->render_template('admin-page/index', $data);
    }

    public function saveCustomFields(Request $request) {
        $incomingFields = $request->post('fields') ?? [];

        $existingFieldsArray = [];
        $existingFields = FieldModel::select()->get(true);
        if ($existingFields) {
            foreach ($existingFields as $field) {
                $existingFieldsArray[$field->id] = $field;
            }
        }
        
        $dbManager = DatabaseManager::getInstance();
        foreach ($incomingFields as $fieldId => $fieldData) {
            if (strpos((string)$fieldId, 'new_') === 0) {
                $newField = new FieldModel();
                $newField->field_name = $fieldData['field_name'];
                $newField->field_label = $fieldData['field_label'];
                $newField->field_type = $fieldData['field_type'];
                $newField->is_required = ($fieldData['is_required'] ?? null) === 'on' ? 1 : 0;
                $dbManager->queueInsert([
                    'field_name' => $newField->field_name,
                    'field_label' => $newField->field_label,
                    'field_type' => $newField->field_type,
                    'is_required' => $newField->is_required
                ], 'fields');
            } else {
                if (isset($existingFieldsArray[$fieldId])) {
                    $existingField = $existingFieldsArray[$fieldId];
                    $existingField->field_name = $fieldData['field_name'];
                    $existingField->field_label = $fieldData['field_label'];
                    $existingField->field_type = $fieldData['field_type'];
                    $existingField->is_required = ($fieldData['is_required'] ?? null) === 'on' ? 1 : 0;
                    $dbManager->queueUpdate([
                        'field_name' => $existingField->field_name,
                        'field_label' => $existingField->field_label,
                        'field_type' => $existingField->field_type,
                        'is_required' => $existingField->is_required
                    ], 'fields', $existingField->id);
                    unset($existingFieldsArray[$fieldId]);
                }
            }
        }
        
        foreach ($existingFieldsArray as $field) {
            $dbManager->queueDelete('fields', $field->id);
        }

        $dbManager->commit();
        return Router::getInstance()->redirect('adminpanel');
    }
    
    /**
     * Управление пользователями: блокировка/разблокировка.
     * До выделения отдельного status-поля административные роли не переключаются
     * этим методом, чтобы разблокировка не превращала администратора в обычного пользователя.
     */
    public function toggleUserStatus(Request $request) {
        $targetUserId = (int) $request->post('user_id', 0);
        $newStatus = (string) $request->post('new_status', '');
        
        if ($targetUserId <= 0 || !in_array($newStatus, ['active', 'blocked'], true)) {
            $this->responseJson(['success' => false, 'message' => 'Некорректные параметры']);
            return;
        }
        
        $user = UserModel::select()->where('id', '=', $targetUserId)->first();
        if (!$user) {
            $this->responseJson(['success' => false, 'message' => 'Пользователь не найден']);
            return;
        }

        $currentUserId = (int) $request->session('user_id', 0);
        if ($currentUserId === $targetUserId) {
            $this->responseJson(['success' => false, 'message' => 'Нельзя изменить собственный статус']);
            return;
        }

        if (Config::isAdminRole((int) $user->role)) {
            $this->responseJson([
                'success' => false,
                'message' => 'Статус администратора нельзя менять этой операцией'
            ]);
            return;
        }
        
        $newRole = $newStatus === 'blocked'
            ? Config::USER_ROLE_BLOCKED
            : Config::USER_ROLE_USER;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate(['role' => $newRole], 'users', $user->id);
        $result = $dbManager->commit();
        
        if ($result !== false) {
            $this->responseJson(['success' => true, 'message' => 'Статус пользователя изменен']);
            return;
        }
        
        $this->responseJson(['success' => false, 'message' => 'Ошибка при обновлении статуса']);
    }
    
    /**
     * Удаление пользователя
     */
    public function deleteUser(Request $request) {
        $targetUserId = (int) $request->post('user_id', 0);
        
        if ($targetUserId <= 0) {
            $this->responseJson(['success' => false, 'message' => 'Не указан пользователь']);
            return;
        }
        
        $user = UserModel::select()->where('id', '=', $targetUserId)->first();
        if (!$user) {
            $this->responseJson(['success' => false, 'message' => 'Пользователь не найден']);
            return;
        }
        
        $currentUser = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        if (!$currentUser) {
            http_response_code(401);
            $this->responseJson(['success' => false, 'message' => 'Требуется авторизация']);
            return;
        }

        if ((int)$currentUser->id === $targetUserId) {
            $this->responseJson(['success' => false, 'message' => 'Нельзя удалить самого себя']);
            return;
        }

        if ((int)$user->role === Config::USER_ROLE_SUPERADMIN) {
            $this->responseJson(['success' => false, 'message' => 'Суперадминистратора удалить нельзя']);
            return;
        }
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueDelete('users', $user->id);
        $result = $dbManager->commit();
        
        if ($result !== false) {
            $this->responseJson(['success' => true, 'message' => 'Пользователь удален']);
            return;
        }
        
        $this->responseJson(['success' => false, 'message' => 'Ошибка при удалении пользователя']);
    }
}
