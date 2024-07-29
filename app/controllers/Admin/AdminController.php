<?php 
namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\FieldModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Route;

class AdminController extends Controller {
    public function index(Request $request) {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        $fields = FieldModel::select()->get();
        $data['user'] = $user;
        $data['customFields'] = $fields;
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
        
        $dbManager = new DatabaseManager();
        foreach ($incomingFields as $fieldId => $fieldData) {
            if (strpos($fieldId, 'new_') === 0) {
                $newField = new FieldModel();
                $newField->field_name = $fieldData['field_name'];
                $newField->field_label = $fieldData['field_label'];
                $newField->field_type = $fieldData['field_type'];
                $newField->is_required = ($fieldData['is_required'] == 'on') ? 1 : 0;
                $dbManager->queueInsert($newField);
            } else {
                if (isset($existingFieldsArray[$fieldId])) {
                    $existingField = $existingFieldsArray[$fieldId];
                    $existingField->field_name = $fieldData['field_name'];
                    $existingField->field_label = $fieldData['field_label'];
                    $existingField->field_type = $fieldData['field_type'];
                    $existingField->is_required = ($fieldData['is_required'] == 'on') ? 1 : 0;
                    $dbManager->queueUpdate($existingField);
                    unset($existingFieldsArray[$fieldId]);
                }
            }
        }
        
        foreach ($existingFieldsArray as $fieldId => $field) { // нужно использовать оставшиеся данные
            $dbManager->queueDelete($field);
        }

        $dbManager->commit();
        return Route::getInstance()->redirect('adminpanel');
    }
}
