<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use App\Services\MessengerEventJournal;
use App\Services\MessengerMediaService;
use App\Services\PermissionService;
use App\Sockets\BufferedSocketConnection;
use App\Sockets\MessengerActionDispatcher;
use Core\Controller;
use Core\ModuleRuntimeLoader;
use Core\Request;
use Core\WebSocketEndpoint;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

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

        $socketUrl = '';
        try {
            $socketUrl = WebSocketEndpoint::browserUrl();
        } catch (\Throwable $e) {
            error_log('WebSocket public endpoint is invalid: ' . $e->getMessage());
        }

        $transportCursor = 0;
        try {
            $transportCursor = (new MessengerEventJournal())->cursorForUserId((int) $user->id);
        } catch (\Throwable $e) {
            error_log('Messenger transport cursor is unavailable: ' . $e->getMessage());
        }

        $workspaceActions = ['notes' => false, 'tasks' => false, 'files' => false];
        try {
            $permissions = new PermissionService();
            $capabilities = ModuleRuntimeLoader::getInstance()->capabilities();
            $workspaceActions = [
                'notes' => $capabilities->has('workspace.notes')
                    && $permissions->hasPermission((int) $user->id, 'notes.use'),
                'tasks' => $capabilities->has('workspace.tasks')
                    && $permissions->hasPermission((int) $user->id, 'tasks.use'),
                'files' => $capabilities->has('workspace.files')
                    && $permissions->hasPermission((int) $user->id, 'files.use'),
            ];
        } catch (\Throwable $e) {
            error_log('Messenger workspace actions are unavailable: ' . $e->getMessage());
        }

        $this->render_template('@messenger/index', [
            'user' => get_object_vars($user),
            'contacts' => $contacts,
            'workspace_actions' => $workspaceActions,
            'socket_ticket' => $socketTicket,
            'socket_url' => $socketUrl,
            'transport_cursor' => $transportCursor,
        ]);
    }

    /**
     * Issue a short-lived ticket from the authenticated HTTP session.
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
     * Cursor-based HTTP compatibility transport. The request intentionally
     * releases the PHP session lock before waiting so other Messenger actions
     * from the same browser are never serialized behind the poll.
     */
    public function transportPoll(Request $request): void
    {
        $user = $this->transportUser($request);
        if ($user === null) {
            return;
        }

        $cursorRaw = trim((string) $request->get('cursor', '0'));
        $cursor = ctype_digit($cursorRaw) ? (int) $cursorRaw : 0;
        $timeout = $this->longPollTimeoutSeconds();
        $deadline = microtime(true) + $timeout;
        $journal = new MessengerEventJournal();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            do {
                $batch = $journal->readSince((int) $user->id, $cursor, 100);
                if ($batch['events'] !== []) {
                    $this->responseJson([
                        'status' => 'ok',
                        'transport' => 'long_poll',
                        'cursor' => (int) $batch['cursor'],
                        'events' => $batch['events'],
                    ]);
                    return;
                }

                if (connection_aborted()) {
                    return;
                }
                usleep(250000);
            } while (microtime(true) < $deadline);

            $this->responseJson([
                'status' => 'ok',
                'transport' => 'long_poll',
                'cursor' => $cursor,
                'events' => [],
            ]);
        } catch (\Throwable $e) {
            error_log('Messenger long-poll read failed: ' . $e->getMessage());
            http_response_code(503);
            $this->responseJson([
                'status' => 'error',
                'transport' => 'long_poll',
                'message' => 'Совместимый транспорт Messenger временно недоступен',
            ]);
        }
    }

    /**
     * Execute a normal Messenger socket action over authenticated HTTP.
     *
     * State-changing requests are protected by CSRFMiddleware. The shared
     * MessengerActionDispatcher retains the exact WebSocket allowlist,
     * anti-impersonation stripping, RBAC, maintenance and license boundaries.
     */
    public function transportSend(Request $request): void
    {
        $user = $this->transportUser($request);
        if ($user === null) {
            return;
        }

        $action = trim((string) $request->rawPost('action', ''));
        $payloadJson = (string) $request->rawPost('payload', '{}');
        $cursorRaw = trim((string) $request->rawPost('cursor', '0'));
        $cursor = ctype_digit($cursorRaw) ? (int) $cursorRaw : 0;

        if ($action === '' || strlen($action) > 128) {
            $this->jsonError('Некорректное действие Messenger', 422);
            return;
        }

        try {
            $payload = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->jsonError('Некорректные данные Messenger', 400);
            return;
        }
        if (!is_array($payload)) {
            $this->jsonError('Данные Messenger должны быть объектом', 400);
            return;
        }

        $connection = new BufferedSocketConnection();
        $connection->authenticated = true;
        $connection->userId = (int) $user->id;
        $connection->uid = (string) $user->uid;
        $connection->transportCursor = max(0, $cursor);

        try {
            $ok = (new MessengerActionDispatcher())->dispatch(
                [],
                $connection,
                $action,
                $payload,
                'long_poll'
            );

            // Direct request/response messages are not journaled. Push events are
            // journaled, so read the caller's own stream from the supplied cursor
            // before advancing it. This prevents a concurrent event from being
            // skipped merely because this POST created a later event id.
            $directEvents = $connection->drain();
            $batch = (new MessengerEventJournal())->readSince(
                (int) $user->id,
                $cursor,
                100
            );

            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            $this->responseJson([
                'status' => $ok ? 'ok' : 'error',
                'transport' => 'long_poll',
                'cursor' => (int) $batch['cursor'],
                'events' => array_merge($directEvents, $batch['events']),
            ]);
        } catch (\Throwable $e) {
            error_log('Messenger long-poll send failed: ' . $e->getMessage());
            http_response_code(503);
            $this->responseJson([
                'status' => 'error',
                'transport' => 'long_poll',
                'message' => 'Не удалось выполнить действие Messenger',
            ]);
        }
    }

    /**
     * Upload binary contents over HTTP into private storage.
     * Creating/broadcasting the chat message is a separate WebSocket action.
     */
    public function uploadFile(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $dialogUid = trim((string) $request->post('dialog_uid', ''));
        $file = $request->file('file');

        if ($userId <= 0) {
            $this->jsonError('Требуется авторизация', 401);
            return;
        }
        if ($dialogUid === '' || !is_array($file)) {
            $this->jsonError('Не выбран диалог или файл', 422);
            return;
        }

        try {
            $attachment = (new MessengerMediaService())->upload(
                $userId,
                $dialogUid,
                $file,
                filter_var($request->post('voice', false), FILTER_VALIDATE_BOOLEAN)
            );
            $this->responseJson([
                'success' => true,
                'attachment' => $attachment,
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            error_log('Messenger upload failure: ' . $e->getMessage());
            $this->jsonError('Не удалось сохранить вложение', 500);
        } catch (\Throwable $e) {
            error_log('Unexpected messenger upload failure: ' . $e->getMessage());
            $this->jsonError('Ошибка загрузки вложения', 500);
        }
    }

    /**
     * Stream an attachment only to an authenticated current dialog member.
     * Supports a single HTTP byte range so audio/video seeking works normally.
     */
    public function media(Request $request, string $uid): void
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            http_response_code(401);
            return;
        }

        try {
            $attachment = (new MessengerMediaService())->download($userId, $uid);
        } catch (DomainException) {
            http_response_code(404);
            return;
        } catch (\Throwable $e) {
            error_log('Messenger media access failure: ' . $e->getMessage());
            http_response_code(404);
            return;
        }

        $path = (string) $attachment['path'];
        $size = (int) filesize($path);
        if ($size <= 0) {
            http_response_code(404);
            return;
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) === 1) {
            $startPart = $matches[1];
            $endPart = $matches[2];

            if ($startPart === '' && $endPart !== '') {
                $suffixLength = (int) $endPart;
                if ($suffixLength <= 0) {
                    $this->rangeNotSatisfiable($size);
                    return;
                }
                $start = max(0, $size - $suffixLength);
            } elseif ($startPart !== '') {
                $start = (int) $startPart;
            } else {
                $this->rangeNotSatisfiable($size);
                return;
            }

            if ($endPart !== '' && $startPart !== '') {
                $end = min((int) $endPart, $size - 1);
            }

            if ($start < 0 || $start >= $size || $end < $start) {
                $this->rangeNotSatisfiable($size);
                return;
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $inline = in_array((string) $attachment['media_kind'], ['image', 'audio', 'video', 'voice'], true);
        $originalName = (string) $attachment['original_name'];
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $originalName) ?: 'attachment';

        http_response_code($status);
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . (string) $attachment['mime_type']);
        header('Content-Length: ' . $length);
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $fallbackName . '"'
            . "; filename*=UTF-8''" . rawurlencode($originalName)
        );
        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            return;
        }

        try {
            if ($start > 0) {
                fseek($handle, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
        exit;
    }

    private function transportUser(Request $request): ?object
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            $this->jsonError('Требуется авторизация', 401);
            return null;
        }

        $user = UserModel::select('id', 'uid', 'is_active', 'account_status')
            ->where('id', '=', $userId)
            ->first();
        if (
            !$user
            || (int) $user->is_active !== 1
            || (string) ($user->account_status ?? '') !== 'active'
        ) {
            $this->jsonError('Пользователь недоступен', 403);
            return null;
        }

        return $user;
    }

    private function longPollTimeoutSeconds(): int
    {
        $raw = trim((string) (getenv('MESSENGER_LONG_POLL_TIMEOUT_SECONDS') ?: ''));
        $timeout = ctype_digit($raw) ? (int) $raw : 20;
        return max(2, min(25, $timeout));
    }

    private function rangeNotSatisfiable(int $size): void
    {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        $this->responseJson([
            'success' => false,
            'message' => $message,
        ]);
    }
}