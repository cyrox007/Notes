<?php
namespace App\Controllers;

use App\Models\MessageModel;
use App\Models\UserModel;
use App\Models\UserToDialogsModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use UUID;

class MessagerController extends Controller {
	public function index(Request $request) {
		$userModel = new UserModel();
		$user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first(true);

		$userToDialogsModel = new UserToDialogsModel();
		$userToDialogs = $userToDialogsModel->select(['dialog_id', 'user_id'], 'utd')  // 'utd' — алиас для user_to_dialogs
		    ->innerJoin('dialogs', 'dialog_id', 'id', ['uid'], 'd')  // 'd' — алиас для dialogs
			->innerJoin('users', 'user_id', 'id', ['firstname', 'surname', 'uid'], 'u', '!=')  // 'u' — алиас для users
			->where('utd.user_id', '=', $user->id)
			->get();

		$allUsers = $userModel->select()->where('id', '!=', $user->id)->get();

		$data = [
			'user' => get_object_vars($user),
			'dialogues' => $userToDialogs,
			'users' => $allUsers
		];
		$this->render_template('messager_page/index', $data);
        return;
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