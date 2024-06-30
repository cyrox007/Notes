<?php
namespace App\Controller;

use App\Models\NoteModel;
use App\Models\UserModel;
use Core\Controller;
use Core\Request;

class ProfileController extends Controller {
    public function index(Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();
        
        $noteModel = new NoteModel();
        $notes = $noteModel->select('notes')->where('user_id', '=', $user['id'])->get();

        $data = [
            'user' => $user,
            'notes' => $notes
        ];

        $this->render_template('profile_page/index', $data);
    }

    function update() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $set_name = $_POST['set-user-name'];
            $set_patronymic = $_POST['set-user-patronymic'];
            $set_surname = $_POST['set-user-surname'];
            $set_user_phone = $_POST['set-user-phone'];
            $set_avatar = null;
            $set_user_position = $_POST['set-user-position'];
            $set_department = $_POST['set-user-deportament'];
            $set_office_phone = $_POST['set-office-phone'];

            if ($_FILES['set-user-avatar']['type'] == 'image/jpeg' 
                || $_FILES['set-user-avatar']['type'] == 'image/png'
                || $_FILES['set-user-avatar']['tmp_name'] != null) {
                $set_avatar = $this->images->checkAvatar_save(
                    $_FILES['set-user-avatar']['tmp_name'], 
                    $_FILES['set-user-avatar']['name']
                );
            }

            $pack_second = [
                'first_name' => $set_name,
                'patronymic' => $set_patronymic,
                'surname' => $set_surname,
                'user_phone' => $set_user_phone,
                'user_photo' => "$set_avatar",
                'user_position' => $set_user_position,
                'department' => $set_department,
                'office_phone' => $set_office_phone
            ];

            $pack_second = array_diff($pack_second, array('', null, 0));
            $this->model->update_user_profile($user_info['id'], $pack_second);
            header('Location: /Profile');            
        }
    }
    function action_changePass() {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $key = $this->config->hash_key;
            $method = $this->config->hash_method;
            $user = $_SESSION['auth_login'];
            $user_info = $this->model->getUser_data($user);

            if (isset($_POST['old-password'])) {
                $encrypted_password = openssl_encrypt($_POST['old-password'], $method, $key);
                $user_password = $this->model->get_data_password($user);

                if ($encrypted_password == $user_password) {
                    $new_passord = openssl_encrypt($_POST['new-password'], $method, $key);
                    var_dump($_POST['new-password']);
                    var_dump($new_passord);

                    $this->model->update_user_password($user_info['id'], $new_passord);
                    
                    unset($_SESSION['auth_login']);
                    header('Location: /Profile');
                }
            }
        }
    }

    function action_deleteUser () {
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $user = $_SESSION['auth_login'];
            $user_info = $this->model->getUser_data($user);

            $this->model->update_user_status($user_info['id']);
            unset($_SESSION['auth_login']);
            header('Location: /Profile');
        }
    }
}