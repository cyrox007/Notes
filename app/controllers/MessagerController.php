<?php
namespace App\Controllers;

use Core\Controller;

class MessagerController extends Controller {
    
    public $helper;

    function __construct() {
        $this->config = new Config();
        $this->model = new Model_Messager();
        $this->view = new View();
        $this->helper = new Helper();
    }

    function action_index () {
        $this->helper->login_requared($_SESSION['auth_login']); // проверим факт авторизованности

        $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
        $user_info = $this->model->getUser_data($user); // получаем информацию о нем

        if ($user_info['role'] >= $this->config->user_role_inactive) {
            header('Location: /Error/accessDenied');
        }

        $user_dialog = $this->model->getUserDialogues($user_info['id']);
        $users = $this->model->getUsers($user_info['id']);
        
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
            'user_id' => $user_info['id'],
            'user_token' => $user_info['id'],
            'user-name' => $user_info['first_name'],
            'user-surname' => $user_info['surname'],
            'user-photo' => $user_info['user_photo'],
            'dialogues' => $user_dialog,
            'users' => $users
        ];
        $this->view->render_template('messager_page/index_view.php', 'core/template_view.php', $data);
    }

    function action_getMsg() {
        $this->helper->login_requared($_SESSION['auth_login']); // проверим факт авторизованности

        $uri = explode('/', $_SERVER['REQUEST_URI']); // запрос
        $dialog_id = $uri[3];

        $messages = $this->model->get_messages($dialog_id);
        echo(json_encode($messages));
    }

    function action_createDialog() {
        $this->helper->login_requared($_SESSION['auth_login']); // проверим факт авторизованности

        $dialog_name = $_POST['dialog-name'];
        $interlocutor_ids = $_POST['contact'];

        $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
        $user_info = $this->model->getUser_data($user); // получаем информацию о нем

        $data = [
            'chat_name' => $dialog_name ? $dialog_name : null,
            'interlocutor_ids' => $interlocutor_ids,
            'user-id' => $user_info['id']
        ];

        $this->model->addDialog($data);

        header('Location: /Messager');
    }

}