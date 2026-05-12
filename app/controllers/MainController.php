<?php
namespace App\Controllers;

use App\Models\NoteModel;
use App\Models\UserModel;
use App\Models\UserNModel;
use Core\Controller;
use Core\Request;

class MainController extends Controller {
    public function __construct() {
        parent::__construct();
    }
    public function index(Request $request) {
        $user = UserModel::select()
        ->where('id', '=', $request->session('user_uid'))
        ->first();
        
        if (!$user) {
            return "Пользователь не загружен";
        }

        $data['user'] = get_object_vars($user); 
        return $this->render_template('main_page/index', $data);
    }
}