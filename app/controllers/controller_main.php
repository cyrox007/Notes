<?php
class Controller_Main extends Controller {
    public function __construct() {
        $this->config = new Config();
        $this->model = new Model_Main();
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

        $data = [
            'styles' => [
                'main-style' => $this->config->base_url().'templates/css/style.css',
                'font-awesome' => $this->config->base_url().'templates/img/icons/css/font-awesome.css',
            ],
            'script' => $this->config->base_url().'templates/js/script.js',
            'tpl_images' => [
                'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
            ],
            'site' => $this->config->site,
            'title' => 'Главная',
            'user' => $user,
            'user_id' => $user_info['id'],
            'user_token' => $user_info['id'],
            'user-name' => $user_info['first_name'],
            'user-surname' => $user_info['surname'],
            'user-photo' => $user_info['user_photo'],
        ];
    
        $this->view->render_template('main_page/main_view.php', 'core/template_view.php', $data);
    }
}