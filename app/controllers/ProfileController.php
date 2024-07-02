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
use Route;

class ProfileController extends Controller {
    public function index(Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $noteModel = new NoteModel();
        $notes = $noteModel->select()->where('user_id', '=', $user['id'])->get();

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
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first(true);

        $postData = $request->post();
        $updateData = $this->gatherUserData($postData, $user);

        if ($newAvatarPath = $this->handleAvatarUpload($user)) {
            $updateData['user_image'] = $newAvatarPath;
        }

        $customFields = $this->getCustomFields($postData['custom']);
        $updateData['property'] = json_encode($customFields, JSON_UNESCAPED_UNICODE);

        $this->updateUserData($user, $updateData);

        $dbManager = new DatabaseManager();
        $dbManager->queueUpdate($user);
        $dbManager->commit();

        return Route::getInstance()->redirect('profile');
    }

    private function gatherUserData(array $postData, object $user): array {
        return [
            'firstname' => $postData['set-user-name'],
            'patronymic' => $postData['set-user-patronymic'],
            'surname' => $postData['set-user-surname'],
            'phone' => $postData['set-user-phone'],
            'email' => $postData['set-user-email'],
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
        $relativePath = '/'.$uploadDir . '/' . $user->uid . '/avatars/' . $filename;
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
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first(true);
        $data['user'] = get_object_vars($user);


        if (!CryptMethods::verifyPassword($request->post('old-password'), $user->password)) {
            $data['errors'] = [
                "CODE" => 'login_error',
                "MESSAGE" => "Password error"
            ];
            return $this->render_template('profile_page/index', $data);
        }

        $user->password = CryptMethods::createHashFromPassword($request->post('new-password'));

        $dbManager = new DatabaseManager();
        $dbManager->queueUpdate($user);
        $dbManager->commit();
        
        $request->unsetSession("auth");
        $request->unsetSession('user_uid');
        return Route::getInstance()->redirect('login');
    }

    public function deleteUser (Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first(true);

        $dbManager = new DatabaseManager();
        $dbManager->queueDelete($user);
        $dbManager->commit();
        
        $request->unsetSession("auth");
        $request->unsetSession('user_uid');
        return Route::getInstance()->redirect('login');
    }
}