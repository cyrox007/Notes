<?php
namespace App\Controllers;

use App\Models\DialogModel;
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
		$user = $userModel->select()->where('id', '=', $request->session('user_id'))->first(true);

		$userToDialogs = UserToDialogsModel::select(
            'dialogs.uid',
            'users.firstname',
            'users.surname'
        )
        ->innerJoin([DialogModel::class, 'dialogs'], 'user_to_dialogs.dialog_id', '=', 'dialogs.id')  // Получаем данные из таблицы dialogs
        ->innerJoin([UserModel::class, 'users'], 'user_to_dialogs.user_id', '!=', 'users.id')  // вытаскиваем данные о пользователе
        ->where('user_to_dialogs.user_id', '=', $user->id)
        ->get();

		$allUsers = $userModel->select()->where('id', '!=', $user->id)->get();

		$data = [
			'user' => get_object_vars($user),
			'userToDialogs' => $userToDialogs,
			'users' => $allUsers
		];
		$this->render_template('messager_page/index', $data);
        return;
	}

    public function uploadFile(Request $request) {
        $files = $request->files('files');

        error_log(json_encode($request->files('files')));
        
        return $this->response_json(['status' => 'ok']);
    }

    /* function action_createDialog() {
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
    } */

}