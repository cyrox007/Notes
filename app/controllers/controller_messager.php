<?php
class Controller_Messager extends Controller {
    function __construct() {
        $this->config = new Config();
        $this->model = new Model_Messager();
        $this->view = new View();
    }

    function action_index () {
        if ($_SESSION['auth_login'] == null) // проверим факт авторизованности
                header("Location: /Auth/login");

        $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
        $user_info = $this->model->getUser_data($user); // получаем информацию о нем

        if ($user_info['role'] >= $this->config->user_role_inactive) {
            header('Location: /Error/accessDenied');
        }
        $dialogues = $this->model->getUserDialogues($user_info['id']);
        var_dump($dialogues);
        
        $users = $this->model->getAllUsers($user_info['id']);
        
        $data = [
            'styles' => [
                'main-style' => $this->config->base_url().'templates/css/style.css',
                'font-awesome' => $this->config->base_url().'templates/img/icons/css/font-awesome.css',
            ],
            'script' => $this->config->base_url().'templates/js/script.js',
            'msg-script' => $this->config->base_url().'templates/js/msg_script.js',
            'tpl_images' => [
                'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
            ],
            'site' => $this->config->site,
            'base-url' => $this->config->base_url(),
            'title' => 'Мессенджер',
            'user' => $user,
            'user-name' => $user_info['first_name'],
            'user-surname' => $user_info['surname'],
            'user-photo' => $user_info['user_photo'],
            'dialogues' => $dialogues,
            'all-users' => $users,
        ];
        $this->view->render_template('messager_page/index_view.php', 'core/template_view.php', $data);
    }

    function action_startDialog() {
        if ($_SESSION['auth_login'] == null)
            header("Location: /Auth/login");

        $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
        $interlocutor_id = $uri[3];

        if (!$interlocutor_id)
            header('Location: /Error/404');

        $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
        $user_info = $this->model->getUser_data($user); // получаем информацию о нем

        if ($user_info['role'] >= $this->config->user_role_inactive) {
            header('Location: /Error/accessDenied');
        }

        
        $file_dialog_name_hash = substr(md5(microtime() . rand(0, 9999)), 0, 20).'.txt';
        $file_dialog_path = $this->config->dir_messages.$file_dialog_name_hash;
        $file = fopen($file_dialog_path, "w"); // создаем файл 
        fclose($file); // закрываем файл

        $this->model->addDialog($user_info['id'], $interlocutor_id, $file_dialog_path);
        header('Location: /Messager');
    }
}