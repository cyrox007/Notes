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
     * Issue a short-lived ticket from the authenticated HTTP session.
     *
     * Reconnects must never reuse a ticket embedded in a page indefinitely.
     * This endpoint is POST-only, protected by LoginRequared + global CSRF,
     * and does not accept a user id from the browser.
     */
    public function socketTicket(Request $request): void
    {
        $userId = (int) $request->session('user_id');
        if ($userId <= 0) {
            http_response_code(401);
            $this->responseJson([
                'status' => 'error',
                'message' => 'Требуется авторизация',
            ]);
            return;
        }

        $user = UserModel::select('id', 'is_active')
            ->where('id', '=', $userId)
            ->first();

        if (!$user || (int) $user->is_active !== 1) {
            http_response_code(403);
            $this->responseJson([
                'status' => 'error',
                'message' => 'Пользователь недоступен',
            ]);
            return;
        }

        try {
            $this->responseJson([
                'status' => 'ok',
                'ticket' => SocketTicket::issue($userId),
                'expires_in' => 120,
            ]);
        } catch (\Throwable $e) {
            error_log('WebSocket ticket refresh failed: ' . $e->getMessage());
            http_response_code(503);
            $this->responseJson([
                'status' => 'error',
                'message' => 'WebSocket временно недоступен',
            ]);
        }
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
