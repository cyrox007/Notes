<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\FileShareService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class FileShareController extends Controller
{
    public function index(Request $request): void
    {
        $userId = $this->userId($request);
        $user = UserModel::select()->where('id', '=', $userId)->first();
        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        $shares = (new FileShareService())->listOwnerShares($userId);
        foreach ($shares as &$share) {
            $share['share_url'] = $this->viewContext->route('files_shared', ['token' => (string) $share['share_token']]);
        }
        unset($share);

        $this->render_template('@files/shares', [
            'user' => $user,
            'shares' => $shares,
        ]);
    }

    public function create(Request $request, string $uid): void
    {
        try {
            $expiresAt = trim((string) $request->post('expires_at', ''));
            if ($expiresAt === '') {
                $expiresHours = (int) $request->post('expires_hours', 0);
                if ($expiresHours < 0 || $expiresHours > 8784) {
                    throw new InvalidArgumentException('Некорректный срок действия ссылки');
                }
                $expiresAt = $expiresHours > 0
                    ? date('Y-m-d H:i:s', time() + ($expiresHours * 3600))
                    : '';
            }

            $share = (new FileShareService())->create($this->userId($request), $uid, $expiresAt);
            $url = $this->viewContext->route('files_shared', ['token' => $share['token']]);
            $this->responseJson([
                'success' => true,
                'share_id' => $share['id'],
                'share_url' => $url,
                'expires_at' => $share['expires_at'],
                'item' => $share['item'],
                'file' => $share['item'],
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->domainStatus($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('File share create failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать публичную ссылку', 500);
        }
    }

    public function revoke(Request $request, string $uid): void
    {
        try {
            (new FileShareService())->revokeByUid($this->userId($request), $uid);
            $this->responseJson(['success' => true]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->domainStatus($e, 403));
        } catch (Throwable $e) {
            error_log('File share revoke failed: ' . $e->getMessage());
            $this->jsonError('Не удалось отключить ссылку', 500);
        }
    }

    public function revokeShare(Request $request, int $shareId): void
    {
        try {
            (new FileShareService())->revokeById($this->userId($request), $shareId);
            Router::getInstance()->redirect('files_shares');
        } catch (Throwable $e) {
            error_log('File share revoke by id failed: ' . $e->getMessage());
            http_response_code($e instanceof DomainException ? $this->domainStatus($e, 400) : 500);
            echo 'Не удалось отозвать публичную ссылку';
        }
    }

    public function updateExpiry(Request $request, int $shareId): void
    {
        try {
            $expiresAt = trim((string) $request->post('expires_at', ''));
            (new FileShareService())->updateExpiry(
                $this->userId($request),
                $shareId,
                $expiresAt === '' ? null : $expiresAt
            );
            Router::getInstance()->redirect('files_shares');
        } catch (Throwable $e) {
            error_log('File share expiry update failed: ' . $e->getMessage());
            http_response_code($e instanceof DomainException ? $this->domainStatus($e, 400) : 422);
            echo htmlspecialchars($e->getMessage() ?: 'Не удалось изменить срок действия', ENT_QUOTES, 'UTF-8');
        }
    }

    public function open(Request $request, string $token): void
    {
        try {
            $service = new FileShareService();
            $root = $service->resolveRoot($token);
            if ((string) $root['type'] === 'folder') {
                $this->renderSharedFolder($service->folderView($token), $token);
                return;
            }
            $this->streamFile($root);
        } catch (Throwable $e) {
            $this->publicNotFound();
        }
    }

    public function folder(Request $request, string $token, string $uid): void
    {
        try {
            $this->renderSharedFolder((new FileShareService())->folderView($token, $uid), $token);
        } catch (Throwable) {
            $this->publicNotFound();
        }
    }

    public function sharedFile(Request $request, string $token, string $uid): void
    {
        try {
            $this->streamFile((new FileShareService())->sharedFile($token, $uid));
        } catch (Throwable) {
            $this->publicNotFound();
        }
    }

    /** @param array<string,mixed> $viewData */
    private function renderSharedFolder(array $viewData, string $token): void
    {
        $this->render_template('@files/shared-folder', [
            'share_root' => $viewData['root'],
            'current_folder' => $viewData['current'],
            'files' => $viewData['items'],
            'breadcrumb' => $viewData['breadcrumb'],
            'share_token' => $token,
        ]);
    }

    /** @param array<string,mixed> $file */
    private function streamFile(array $file): void
    {
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new DomainException('Файл недоступен', 404);
        }

        $safeBase = str_replace(["\r", "\n", '"', '\\'], '_', (string) ($file['name'] ?? 'file'));
        if ($safeBase === '') {
            $safeBase = 'file';
        }
        $extension = preg_replace('/[^a-z0-9]+/i', '', (string) ($file['extension'] ?? '')) ?: '';
        $downloadName = $safeBase . ($extension !== '' ? '.' . $extension : '');
        $inline = in_array((string) ($file['type'] ?? 'file'), ['image', 'audio', 'video'], true);

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: ' . (string) ($file['mime_type'] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header(
            'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $downloadName . '"'
            . "; filename*=UTF-8''" . rawurlencode($downloadName)
        );
        readfile($path);
        exit;
    }

    private function publicNotFound(): void
    {
        http_response_code(404);
        header('Cache-Control: no-store, max-age=0');
        echo 'Ссылка недействительна, истекла или объект больше недоступен';
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
