<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\MessengerLongPollService;
use App\Sockets\BufferedSocketConnection;
use App\Sockets\NativeMessengerServer;
use Core\Controller;
use Core\Request;
use JsonException;

final class MessengerRealtimeController extends Controller
{
    public function action(Request $request): void
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return;
        }

        $action = trim((string) $request->rawPost('action', ''));
        $rawData = (string) $request->rawPost('data', '{}');
        if ($action === '' || strlen($action) > 128 || substr_count($action, ':') !== 1) {
            $this->jsonFailure('Некорректное realtime-действие', 422);
            return;
        }

        try {
            $data = json_decode($rawData, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->jsonFailure('Некорректные данные realtime-действия', 422);
            return;
        }
        if (!is_array($data)) {
            $this->jsonFailure('Некорректные данные realtime-действия', 422);
            return;
        }

        [$server, $connection, $connections] = $this->transport($user);
        try {
            $message = json_encode(
                ['action' => $action, 'data' => $data],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $server->dispatchTransportMessage($connection, $message, $connections, 'long_poll');
        } catch (\Throwable $e) {
            error_log('Messenger long-poll action failed: ' . $e->getMessage());
            $this->jsonFailure('Не удалось выполнить действие мессенджера', 500);
            return;
        }

        $this->responseJson([
            'status' => 'ok',
            'transport' => 'long_poll',
            'events' => $connection->drainPayloads(),
        ]);
    }

    public function poll(Request $request): void
    {
        $user = $this->currentUser($request);
        if ($user === null) {
            return;
        }

        $cursor = trim((string) $request->get('cursor', ''));
        if ($cursor !== '' && preg_match('/^[a-f0-9]{64}$/D', $cursor) !== 1) {
            $cursor = '';
        }

        $dialogUid = trim((string) $request->get('dialog_uid', ''));
        if ($dialogUid !== '' && preg_match('/^[A-Za-z0-9._~-]{1,128}$/D', $dialogUid) !== 1) {
            $dialogUid = '';
        }

        // A long-running request must not hold the PHP session lock; otherwise
        // the same browser could not POST a fallback action until this poll ends.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Accel-Buffering: no');

        try {
            $wait = (new MessengerLongPollService())->waitForChange(
                (int) $user['id'],
                $cursor,
                static fn (): bool => connection_aborted() === 1
            );
        } catch (\Throwable $e) {
            error_log('Messenger long-poll wait failed: ' . $e->getMessage());
            $this->jsonFailure('Резервный realtime-канал временно недоступен', 503);
            return;
        }

        if (connection_aborted() === 1) {
            return;
        }

        if (!$wait['changed']) {
            $this->responseJson([
                'status' => 'ok',
                'transport' => 'long_poll',
                'changed' => false,
                'cursor' => $wait['cursor'],
                'events' => [],
            ]);
            return;
        }

        [$server, $connection, $connections] = $this->transport($user);
        $actions = [
            ['MessangerSocket:get_dialogs', []],
            ['DialogStateSocket:list', []],
        ];
        if ($dialogUid !== '') {
            $actions[] = ['MessangerSocket:load', ['dialog_uid' => $dialogUid]];
            $actions[] = ['ReceiptSocket:list', ['dialog_uid' => $dialogUid]];
        }

        try {
            foreach ($actions as [$action, $data]) {
                $message = json_encode(
                    ['action' => $action, 'data' => $data],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                $server->dispatchTransportMessage($connection, $message, $connections, 'long_poll');
            }
        } catch (\Throwable $e) {
            error_log('Messenger long-poll snapshot failed: ' . $e->getMessage());
            $this->jsonFailure('Не удалось синхронизировать мессенджер', 500);
            return;
        }

        $this->responseJson([
            'status' => 'ok',
            'transport' => 'long_poll',
            'changed' => true,
            'cursor' => $wait['cursor'],
            'events' => $connection->drainPayloads(),
        ]);
    }

    /** @return array{id:int,uid:string}|null */
    private function currentUser(Request $request): ?array
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            $this->jsonFailure('Требуется авторизация', 401);
            return null;
        }

        $user = UserModel::select('id', 'uid', 'is_active')
            ->where('id', '=', $userId)
            ->first();

        if (!$user || (int) $user->is_active !== 1 || trim((string) $user->uid) === '') {
            $this->jsonFailure('Пользователь недоступен', 403);
            return null;
        }

        return ['id' => (int) $user->id, 'uid' => (string) $user->uid];
    }

    /**
     * @param array{id:int,uid:string} $user
     * @return array{0:NativeMessengerServer,1:BufferedSocketConnection,2:array<string,array<int,BufferedSocketConnection>>}
     */
    private function transport(array $user): array
    {
        $connection = new BufferedSocketConnection();
        $connection->authenticated = true;
        $connection->userId = $user['id'];
        $connection->uid = $user['uid'];

        // No listener is opened here. NativeMessengerServer owns the canonical
        // action allow-list, maintenance/license checks and handler dispatch;
        // HTTP fallback only supplies a different SocketConnection transport.
        $server = new NativeMessengerServer('127.0.0.1', 1, []);
        $connections = [$user['uid'] => [0 => $connection]];

        return [$server, $connection, $connections];
    }

    private function jsonFailure(string $message, int $status): void
    {
        http_response_code($status);
        $this->responseJson([
            'status' => 'error',
            'message' => $message,
        ]);
    }
}
