<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\ModuleRuntimeLoader;
use Core\Request;
use Core\WorkspaceFileProvider;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class FileShareController extends Controller
{
    public function create(Request $request, string $uid): void
    {
        try {
            $userId = $this->userId($request);
            $expiresHours = (int) $request->post('expires_hours', 0);
            if ($expiresHours < 0 || $expiresHours > 8760) {
                throw new InvalidArgumentException('Некорректный срок действия ссылки');
            }

            $share = $this->provider()->createWorkspaceFileShare($userId, $uid, $expiresHours);
            $this->responseJson([
                'success' => true,
                'share_url' => $this->viewContext->route('files_shared', ['token' => $share['token']]),
                'expires_at' => $share['expires_at'],
                'file' => $share['file'],
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->domainStatus($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('File share create failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать ссылку на файл', 500);
        }
    }

    public function revoke(Request $request, string $uid): void
    {
        try {
            $this->provider()->revokeWorkspaceFileShare($this->userId($request), $uid);
            $this->responseJson(['success' => true]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->domainStatus($e, 403));
        } catch (Throwable $e) {
            error_log('File share revoke failed: ' . $e->getMessage());
            $this->jsonError('Не удалось отключить ссылку', 500);
        }
    }

    public function download(Request $request, string $token): void
    {
        try {
            $share = $this->provider()->resolveWorkspaceFileShare($token);
        } catch (Throwable) {
            http_response_code(404);
            echo 'Файл не найден или ссылка недействительна';
            return;
        }

        $file = $share['file'];
        $path = (string) $file['path'];
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo 'Файл недоступен';
            return;
        }

        $safeBase = str_replace(["\r", "\n", '"', '\\'], '_', (string) $file['name']);
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $extension = preg_replace('/[^a-z0-9]+/i', '', (string) $file['extension']) ?: '';
        $downloadName = $safeBase . ($extension !== '' ? '.' . $extension : '');
        $inline = in_array((string) $file['type'], ['image', 'audio', 'video'], true);

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . (string) $file['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $downloadName . '"'
            . "; filename*=UTF-8''" . rawurlencode($downloadName)
        );
        readfile($path);
        exit;
    }

    private function provider(): WorkspaceFileProvider
    {
        $registry = ModuleRuntimeLoader::getInstance()->capabilities();
        /** @var WorkspaceFileProvider $provider */
        $provider = $registry->require('workspace.files', WorkspaceFileProvider::class);
        return $provider;
    }

    private function userId(Request $request): int
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $userId;
    }

    private function domainStatus(DomainException $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        $this->responseJson(['success' => false, 'message' => $message]);
    }
}
