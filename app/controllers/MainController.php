<?php
namespace App\Controllers;

use App\Models\UserModel;
use Core\Controller;
use Core\Request;

class MainController extends Controller {
    public function __construct() {
        parent::__construct();
    }
    public function index(Request $request) {
        $userModel = new UserModel();
        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();
        if (!$user) {
            return "Пользователь не загружен";
        }

        $data['user'] = $user;
        return $this->render_template('main_page/index', $data);
    }
}