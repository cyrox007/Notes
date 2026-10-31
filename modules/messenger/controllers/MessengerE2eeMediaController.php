<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessengerE2eeMediaService;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Загрузчик только непрозрачных AES-GCM-байтов. */
final class MessengerE2eeMediaController extends Controller
{
    public function upload(Request $request): void
    {
        $userId = (int)$request->session('user_id', 0);
        if ($userId <= 0) {
            $this->error('Требуется авторизация', 401);
            return;
        }
        try {
            $file = $request->file('file');
            if (!is_array($file)) {
                throw new InvalidArgumentException('Не выбран зашифрованный файл');
            }
            $uploaded = (new MessengerE2eeMediaService())->upload(
                $userId,
                (string)$request->post('dialog_uid', ''),
                (string)$request->post('attachment_uid', ''),
                $file
            );
            header('Cache-Control: no-store');
            $this->responseJson(['status'=>'ok','attachment'=>$uploaded]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (DomainException $e) {
            $this->error('Нет доступа к загрузке', 403);
        } catch (Throwable $e) {
            error_log('E2EE: отказ загрузки шифротекста: ' . get_class($e));
            $this->error('Не удалось сохранить зашифрованное вложение', 503);
        }
    }

    public function download(Request $request, string $uid): void
    {
        $userId = (int)$request->session('user_id', 0);
        if ($userId <= 0) {
            http_response_code(401);
            return;
        }
        try {
            $file = (new MessengerE2eeMediaService())->download($userId, $uid);
        } catch (Throwable $e) {
            http_response_code(404);
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="encrypted.bin"');
        header('Content-Length: ' . $file['size']);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        readfile($file['path']);
    }

    private function error(string $message, int $code): void
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        $this->responseJson(['status'=>'error','message'=>$message]);
    }
}
