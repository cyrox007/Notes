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
            $set_surname = $_POST['setSurname'];
            $set_name = $_POST['setName'];
            $set_patronymic = $_POST['setPatronymic'];
            $set_avatar = $_FILES['setAvatar']['tmp_name'];
            $set_user_phone = $_POST['setUserPhone'];
            $set_user_position = $_POST['setUserPosition'];
            $set_department = $_POST['setDepartment'];

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
                'department' => $set_department
            ];

            $pack_second = array_diff($pack_second, array('', null, 0));
            $this->model->update_user_profile($user_info['id'], $pack_second);
            header('Location: /Profile');
        }
        $data = [
            'styles' => [
                $this->config->base_url().'templates/style/'.'plugins/fontawesome-free/css/all.min.css',
                $this->config->base_url().'templates/style/'.'dist/css/adminlte.min.css'
            ],
            'scripts' => [
                $this->config->base_url().'templates/script/'.'plugins/jquery/jquery.min.js',
                $this->config->base_url().'templates/script/'.'plugins/bootstrap/js/bootstrap.bundle.min.js',
                $this->config->base_url().'templates/script/'.'dist/js/adminlte.min.js',
                $this->config->base_url().'templates/script/'.'/dist/js/demo.js'
            ],
            'tpl_images' => [
                'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
            ],
            
            'title' => $user_info['first_name']. " " .$user_info['surname'],
            
            'user' => $user,
            'username' => $user_info['first_name']. " " .$user_info['surname'],

            'user_position' => $user_info['user_position'],
            'user_phone' => $user_info['user_phone'],
            'department' => $user_info['department'],
            'office_phone' => $user_info['office_phone'],
            'userphoto' => $user_info['user_photo'],
            'personal_notes' => $this->model->getPersonalNotes($user_info['id'])
        ];

        $this->view->render_template('profile_page/index_view.php', 'core/template_view.php', $data);
    }
}