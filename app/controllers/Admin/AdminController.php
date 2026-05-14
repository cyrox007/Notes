<?php 
namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\FieldModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

class AdminController extends Controller {
    public function index(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        $fields = FieldModel::select()->get();
        
        // Получаем список всех пользователей для админки
        $users = UserModel::select()->get();
        
        $data['user'] = $user;
        $data['customFields'] = $fields;
        $data['users'] = $users;
        return $this->render_template('admin-page/index', $data);
    }

    public function saveCustomFields(Request $request) {
        $incomingFields = $request->post('fields');

        // Fetch existing fields
        $existingFieldsArray = [];
        $existingFields = FieldModel::select()->get(true);
        if ($existingFields) {
            foreach ($existingFields as $field) {
                $existingFieldsArray[$field->id] = $field;
            }
        }
        
        $dbManager = DatabaseManager::getInstance();
        foreach ($incomingFields as $fieldId => $fieldData) {
            if (strpos($fieldId, 'new_') === 0) {
                $newField = new FieldModel();
                $newField->field_name = $fieldData['field_name'];
                $newField->field_label = $fieldData['field_label'];
                $newField->field_type = $fieldData['field_type'];
                $newField->is_required = ($fieldData['is_required'] == 'on') ? 1 : 0;
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
                    $existingField->is_required = ($fieldData['is_required'] == 'on') ? 1 : 0;
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
        
        foreach ($existingFieldsArray as $fieldId => $field) { // нужно использовать оставшиеся данные
            $dbManager->queueDelete('fields', $field->id);
        }

        $dbManager->commit();
        return Router::getInstance()->redirect('adminpanel');
    }
    
    /**
     * Управление пользователями: блокировка/разблокировка
     */
    public function toggleUserStatus(Request $request) {
        $targetUserId = $request->post('user_id');
        $newStatus = $request->post('new_status'); // 'active' или 'blocked'
        
        if (!$targetUserId) {
            return $this->responseJson(['success' => false, 'message' => 'Не указан пользователь']);
        }
        
        $user = UserModel::select()->where('id', '=', $targetUserId)->first();
        if (!$user) {
            return $this->responseJson(['success' => false, 'message' => 'Пользователь не найден']);
        }
        
        // Определяем новую роль
        $config = new \Core\Config();
        $newRole = ($newStatus === 'blocked') ? 999 : $config->user_role_activate;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate(['role' => $newRole], 'users', $user->id);
        $result = $dbManager->commit();
        
        if ($result !== false) {
            return $this->responseJson(['success' => true, 'message' => 'Статус пользователя изменен']);
        }
        
        return $this->responseJson(['success' => false, 'message' => 'Ошибка при обновлении статуса']);
    }
    
    /**
     * Удаление пользователя
     */
    public function deleteUser(Request $request) {
        $targetUserId = $request->post('user_id');
        
        if (!$targetUserId) {
            return $this->responseJson(['success' => false, 'message' => 'Не указан пользователь']);
        }
        
        $user = UserModel::select()->where('id', '=', $targetUserId)->first();
        if (!$user) {
            return $this->responseJson(['success' => false, 'message' => 'Пользователь не найден']);
        }
        
        // Нельзя удалить самого себя
        $currentUser = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        if ($currentUser->id == $targetUserId) {
            return $this->responseJson(['success' => false, 'message' => 'Нельзя удалить самого себя']);
        }
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueDelete('users', $user->id);
        $result = $dbManager->commit();
        
        if ($result !== false) {
            return $this->responseJson(['success' => true, 'message' => 'Пользователь удален']);
        }
        
        return $this->responseJson(['success' => false, 'message' => 'Ошибка при удалении пользователя']);
    }
}
