<?php
namespace App\Controllers;

use App\Models\FieldModel;
use App\Models\NoteModel;
use App\Models\UserModel;
use App\Helpers\CryptMethods;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Images;

use Exception;
use Core\Router;

class ProfileController extends Controller {
    public function index(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        
        $notes = NoteModel::select()->where('user_id', '=', $user->id)->get();

        $fieldsModel = new FieldModel();
        $fields = $fieldsModel->select()->get();

        $data = [
            'user' => $user,
            'notes' => $notes,
            'fields' => $fields
        ];

        $this->render_template('profile_page/index', $data);
    }

    public function update(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        $postData = $request->post();
        $updateData = $this->gatherUserData($postData, $user);

        if ($newAvatarPath = $this->handleAvatarUpload($user)) {
            $updateData['user_image'] = $newAvatarPath;
        }

        $customFields = $this->getCustomFields($postData['custom']);
        $updateData['property'] = json_encode($customFields, JSON_UNESCAPED_UNICODE);

        $this->updateUserData($user, $updateData);

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate((array)$user, $user->_tablename ?? 'users', $user->id);
        $dbManager->commit();

        return Router::getInstance()->redirect('profile');
    }

    private function gatherUserData(array $postData, object $user): array {
        // Валидация email
        $email = filter_var($postData['set-user-email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new \Exception('Некорректный формат email адреса');
        }
        
        // Валидация телефона (если указан)
        $phone = preg_replace('/[^0-9+]/', '', $postData['set-user-phone']);
        if (!empty($postData['set-user-phone']) && strlen($phone) < 10) {
            throw new \Exception('Некорректный формат телефона');
        }
        
        return [
            'firstname' => trim($postData['set-user-name']),
            'patronymic' => trim($postData['set-user-patronymic']),
            'lastname' => trim($postData['set-user-surname']),
            'phone' => $phone,
            'email' => $email,
            'user_image' => $user->user_image
        ];
    }

    private function getCustomFields(array $customData): array {
        $customFields = [];
        foreach ($customData as $name => $data) {
            if (isset($data['label'], $data['value'])) {
                $customFields[] = [
                    'label' => $data['label'],
                    'name' => $name,
                    'value' => $data['value']
                ];
            }
        }
        return $customFields;
    }

    private function handleAvatarUpload(object $user): ?string {
        if (empty($_FILES['set-user-avatar']['tmp_name'])) {
            return null;
        }
    
        $fileType = $_FILES['set-user-avatar']['type'];
        $validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
    
        // Check for valid file type
        if (!in_array($fileType, $validTypes)) {
            error_log("Invalid file type: $fileType");
            return null;
        }
    
        try {
            $relativePath = $this->processAndSaveImage($user);
            return $relativePath;
        } catch (Exception $e) {
            error_log("Error processing image: " . $e->getMessage());
            return null;
        }
    }

    private function processAndSaveImage(object $user): string {
        // Load the image
        $imageHandler = Images::loadImage($_FILES['set-user-avatar']['tmp_name']);
        
        // Process the image to desired size (150x150)
        $imageHandler = $imageHandler->processImage(150, 150);
        
        // Generate unique filename
        $hash = md5(uniqid(rand(), true));
        $extension = pathinfo($_FILES['set-user-avatar']['name'], PATHINFO_EXTENSION);
        $filename = $hash . '.' . $extension;
    
        // Generate path for saving (not including SITEPATH)
        $uploadDir = getenv('UPLOAD_DIR') ?: 'default/path'; // Fallback to default if UPLOAD_DIR isn't set
        $relativePath = '/'.$uploadDir . '/' . $user->id . '/avatars/' . $filename;
        $savePath = SITEPATH . $relativePath;
    
        // Create directories if they do not exist
        $directory = dirname($savePath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }
        
        // Save the image
        $imageHandler->saveImage($savePath);
    
        return $relativePath;
    }

    private function updateUserData(object $user, array $updateData): void {
        foreach ($updateData as $key => $value) {
            if ($user->{$key} !== $value) {
                $user->{$key} = $value;
            }
        }
    }

    public function changeUserPass(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();
        $data['user'] = $user;

        $newPassword = $request->post('new-password');
        $repeatPassword = $request->post('repeat-new-password');
        $oldPassword = $request->post('old-password');

        // Проверка совпадения нового пароля с подтверждением
        if ($newPassword !== $repeatPassword) {
            $data['errors'][] = [
                "CODE" => 'password_mismatch',
                "MESSAGE" => "Новые пароли не совпадают"
            ];
            return $this->render_template('profile_page/index', $data);
        }

        // Проверка длины пароля
        if (strlen($newPassword) < 6) {
            $data['errors'][] = [
                "CODE" => 'password_too_short',
                "MESSAGE" => "Пароль должен быть не менее 6 символов"
            ];
            return $this->render_template('profile_page/index', $data);
        }

        if (!CryptMethods::verifyPassword($oldPassword, $user->password)) {
            $data['errors'][] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Неверный текущий пароль"
            ];
            return $this->render_template('profile_page/index', $data);
        }

        $user->password = CryptMethods::createHashFromPassword($newPassword);

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate(['password' => $user->password], 'users', $user->id);
        $dbManager->commit();
        
        $request->unsetSession("auth");
        $request->unsetSession('user_id');
        return Router::getInstance()->redirect('authpage');
    }

    public function deleteUser(Request $request) {
        $user = UserModel::select()->where('id', '=', $request->session('user_id'))->first();

        // Проверка подтверждения удаления (нужно передать confirmation параметр)
        $confirmation = $request->post('confirm_delete');
        if ($confirmation !== 'yes') {
            $data['user'] = $user;
            $data['errors'][] = [
                "CODE" => 'delete_not_confirmed',
                "MESSAGE" => "Удаление аккаунта не подтверждено"
            ];
            return $this->render_template('profile_page/index', $data);
        }

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueDelete('users', $user->id);
        $dbManager->commit();
        
        $request->unsetSession("auth");
        $request->unsetSession('user_id');
        return Router::getInstance()->redirect('authpage');
    }
}
