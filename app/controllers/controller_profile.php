<?php
class Controller_Profile extends Controller {
    public function __construct() {
        $this->config = new Config();
        $this->model = new Model_Profile();
        $this->images = new Images();
        $this->view = new View();
    }
    function action_index() {
        if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

        $user = $_SESSION['auth_login'];
        $user_info = $this->model->getUser_data($user);

        if ($user_info['role'] >= $this->config->user_role_inactive) {
            header('Location: /Error/accessDenied');
        }


        
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
        $data = [
            'style' =>  $this->config->base_url().'templates/css/style.css',
            'font-awesome' => $this->config->base_url().'templates/img/icons/css/font-awesome.css',
            'styles' => [
                'main-style' => $this->config->base_url().'templates/css/style.css',
                'font-awesome' => $this->config->base_url().'templates/img/icons/css/font-awesome.css',
            ],
            'script' => $this->config->base_url().'templates/js/script.js',
            'profile-script' => $this->config->base_url().'templates/js/profile_script.js',
            'tpl_images' => [
                'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
            ],
            'site' => $this->config->site,
            'title' => $user_info['first_name']. " " .$user_info['surname'],
            
            'user' => $user,
            'user-name' => $user_info['first_name'],
            'user-surname' => $user_info['surname'],
            'user-patronymic' => $user_info['patronymic'],
            'user-photo' => $user_info['user_photo'],
            'is-admin' => $this->config->isAdmin($user_info['role'], $this->config->user_role_admin),
            'user-position' => $user_info['user_position'],
            'user-phone' => $user_info['user_phone'],
            'department' => $user_info['department'],
            'office-phone' => $user_info['office_phone'],
            'personal_notes' => $this->model->getPersonalNotes($user_info['id'])
        ];

        $this->view->render_template('profile_page/index_view.php', 'core/template_view.php', $data);
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