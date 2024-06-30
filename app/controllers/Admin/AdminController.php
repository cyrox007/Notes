<?php 
namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Models\FieldModel;
use Core\Controller;
use Core\Request;

class AdminController extends Controller {
    public function index(Request $request) {
        $userModel = new UserModel();

        $fieldsModel = new FieldModel();
        /* $field = $fieldsModel-> */

        $user = $userModel->select()->where('uid', '=', $request->session('user_uid'))->first();

        $data['user'] = $user;
        /* $data['fields'] =  */

        return $this->render_template('admin-page/index', $data);
    }
}
