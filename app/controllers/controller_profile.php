<?php
class Controller_Profile extends Controller {
    public function __construct() {
        $this->config = new Config();
        $this->model = new Model_Profile();
        $this->view = new View();
    }
    function action_index() {
        if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

        $user = $_SESSION['auth_login'];
        $user_info = $this->model->getUser_data($user);
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $set_name = $_POST['set-user-name'];
            $set_patronymic = $_POST['set-user-patronymic'];
            $set_surname = $_POST['set-user-surname'];
            $set_user_phone = $_POST['set-user-phone'];
            $set_avatar/*  = $_FILES['set-user-avatar']['tmp_name'] */;
            $set_user_position = $_POST['set-user-position'];
            $set_department = $_POST['set-user-deportament'];
            $set_office_phone = $_POST['set-office-phone'];

            if ($_FILES['setAvatar']['type'] == 'image/jpeg' || $_FILES['setAvatar']['tmp_name'] != NULL) {
                $set_avatar = $this->images->checkAvatar_save($_FILES['setAvatar']['tmp_name'], $_FILES['setAvatar']['name']);
            }

            $pack_main = [

            ];
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
            'script' => $this->config->base_url().'templates/js/script.js',
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

            'user-position' => $user_info['user_position'],
            'user-phone' => $user_info['user_phone'],
            'department' => $user_info['department'],
            'office-phone' => $user_info['office_phone'],
            'personal_notes' => $this->model->getPersonalNotes($user_info['id'])
        ];

        $this->view->render_template('profile_page/index_view.php', 'core/template_view.php', $data);
    }
}