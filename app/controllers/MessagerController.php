<?php
namespace App\Controllers;

use App\Handlers\SocketTicket;
use App\Models\DialogModel;
use App\Models\UserModel;
use App\Models\UserToDialogsModel;
use Core\Controller;
use Core\Request;

class MessagerController extends Controller
{
    public function index(Request $request)
    {
        $userModel = new UserModel();
        $user = $userModel->select()->where('id', '=', $request->session('user_id'))->first(true);

        $userToDialogs = UserToDialogsModel::select(
            'dialogs.uid',
            'users.firstname',
            'users.surname'
        )
            ->innerJoin([DialogModel::class, 'dialogs'], 'user_to_dialogs.dialog_id', '=', 'dialogs.id')
            ->innerJoin([UserModel::class, 'users'], 'user_to_dialogs.user_id', '!=', 'users.id')
            ->where('user_to_dialogs.user_id', '=', $user->id)
            ->get();

        $allUsers = $userModel->select()->where('id', '!=', $user->id)->get();

        $socketTicket = '';
        try {
            $socketTicket = SocketTicket::issue((int) $user->id);
        } catch (\Throwable $e) {
            error_log('WebSocket ticket is unavailable: ' . $e->getMessage());
        }

        $socketUrl = trim((string) getenv('WS_PUBLIC_URL'));
        if ($socketUrl === '') {
            $siteUrl = (string) (getenv('SITEURL') ?: 'http://localhost');
            $socketScheme = strtolower((string) parse_url($siteUrl, PHP_URL_SCHEME)) === 'https' ? 'wss' : 'ws';
            $socketHost = (string) (parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost');
            $socketPort = (int) (getenv('WS_PORT') ?: 27800);
            $socketUrl = sprintf('%s://%s:%d', $socketScheme, $socketHost, $socketPort);
        }

        $this->render_template('messager_page/index', [
            'user' => get_object_vars($user),
            'userToDialogs' => $userToDialogs,
            'users' => $allUsers,
            'socket_ticket' => $socketTicket,
            'socket_url' => $socketUrl,
        ]);
    }

    public function uploadFile(Request $request)
    {
        error_log(json_encode($request->files('files')));
        $this->responseJson(['status' => 'ok']);
    }
}
