<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessengerVoiceService;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class MessengerVoiceController extends Controller
{
    public function upload(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $dialogUid = trim((string) $request->post('dialog_uid', ''));
        $file = $request->file('voice');

        if ($userId <= 0) {
            $this->jsonError('Требуется авторизация', 401);
            return;
        }
        if ($dialogUid === '' || !is_array($file)) {
            $this->jsonError('Не выбран диалог или отсутствует запись', 422);
            return;
        }

        try {
            $attachment = (new MessengerVoiceService())->upload($userId, $dialogUid, $file);
            $this->responseJson([
                'success' => true,
                'attachment' => $attachment,
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), 403);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            error_log('Messenger voice upload failure: ' . $e->getMessage());
            $this->jsonError('Не удалось сохранить голосовое сообщение', 500);
        } catch (\Throwable $e) {
            error_log('Unexpected messenger voice upload failure: ' . $e->getMessage());
            $this->jsonError('Ошибка загрузки голосового сообщения', 500);
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
