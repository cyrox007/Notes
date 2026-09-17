<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Handlers\SocketTicket;
use App\Models\UserModel;
use App\Services\MessengerMediaService;
use Core\Controller;
use Core\Request;
use Core\WebSocketEndpoint;
use DomainException;
use InvalidArgumentException;
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

        $this->render_template('messager_page/index', [
            'user' => get_object_vars($user),
            'contacts' => $contacts,
            'socket_ticket' => $socketTicket,
            'socket_url' => $socketUrl,
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
