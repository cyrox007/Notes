<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use Core\Controller;
use Core\Request;

final class MessagerController extends Controller
{
    public function index(Request $request): void
    {
        $user = UserModel::select()
            ->where('id', '=', (int) $request->session('user_id'))
            ->first();

        if (!$user) {
            http_response_code(401);
            return;
        }

        $contacts = UserModel::select(
            'uid',
            'username',
            'firstname',
            'lastname',
            'avatar'
        )
            ->where('id', '!=', (int) $user->id)
            ->where('is_active', '=', 1)
            ->orderBy('firstname', 'ASC')
            ->get();

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
            'contacts' => $contacts,
            'socket_ticket' => $socketTicket,
            'socket_url' => $socketUrl,
        ]);
    }

    /**
     * Attachment transport is intentionally disabled until it is moved to the
     * same private-storage policy as FileController. The UI does not advertise
     * a fake working upload action.
     */
    public function uploadFile(Request $request): void
    {
        http_response_code(501);
        $this->responseJson([
            'status' => 'error',
            'message' => 'Вложения будут подключены после private-storage migration',
        ]);
    }
}
