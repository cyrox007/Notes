<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessengerGroupAvatarService;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class MessengerGroupController extends Controller
{
    public function uploadAvatar(Request $request, string $uid): void
    {
        $userId = (int) $request->session('user_id', 0);
        $file = $request->file('avatar');
        if ($userId <= 0) {
            $this->jsonError('Требуется авторизация', 401);
            return;
        }
        if (!is_array($file)) {
            $this->jsonError('Изображение не выбрано', 422);
            return;
        }

        try {
            $avatar = (new MessengerGroupAvatarService())->upload($userId, $uid, $file);
            $this->responseJson(['success' => true, 'avatar' => $avatar]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            error_log('Group avatar upload failure: ' . $e->getMessage());
            $this->jsonError('Не удалось сохранить аватар группы', 500);
        } catch (\Throwable $e) {
            error_log('Unexpected group avatar upload failure: ' . $e->getMessage());
            $this->jsonError('Ошибка загрузки аватара группы', 500);
        }
    }

    public function avatar(Request $request, string $uid): void
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            http_response_code(401);
            return;
        }

        try {
            $avatar = (new MessengerGroupAvatarService())->download($userId, $uid);
        } catch (DomainException) {
            http_response_code(404);
            return;
        } catch (\Throwable $e) {
            error_log('Group avatar access failure: ' . $e->getMessage());
            http_response_code(404);
            return;
        }

        $path = (string) $avatar['path'];
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) {
            http_response_code(404);
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . (string) $avatar['mime_type']);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="group-avatar"');
        readfile($path);
        exit;
    }

    public function removeAvatar(Request $request, string $uid): void
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            $this->jsonError('Требуется авторизация', 401);
            return;
        }

        try {
            $avatar = (new MessengerGroupAvatarService())->remove($userId, $uid);
            $this->responseJson(['success' => true, 'avatar' => $avatar]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (\Throwable $e) {
            error_log('Group avatar remove failure: ' . $e->getMessage());
            $this->jsonError('Не удалось удалить аватар группы', 500);
        }
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
