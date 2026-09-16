<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\AdminUpdateService;
use Core\Controller;
use Core\Request;
use Core\Router;
use InvalidArgumentException;

final class UpdateController extends Controller
{
    private const STAGE_BINDING_SESSION_KEY = 'updates_stage_binding';

    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $flash = $request->session('updates_flash');
        $result = $request->session('updates_result');
        $request->unsetSession('updates_flash');
        $request->unsetSession('updates_result');

        // A reviewed staging binding is useful only for the immediately rendered
        // signed check result. A later direct/reloaded page must not leave an old
        // release silently armed in the session.
        if (!is_array($result)
            || ($result['kind'] ?? '') !== 'check'
            || ($result['status'] ?? '') !== 'update_available'
            || empty($result['update_available'])) {
            $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
        }

        try {
            $snapshot = (new AdminUpdateService())->snapshot($actorId);
        } catch (\Throwable $e) {
            $snapshot = [
                'installed_version' => '',
                'installed_version_code' => 0,
                'feed_configured' => false,
                'feed_label' => '',
                'channel' => '',
                'channel_valid' => false,
                'trust_configured' => false,
                'trusted_key_ids' => [],
                'openssl_available' => extension_loaded('openssl'),
                'stage_configured' => false,
                'can_check' => false,
                'can_stage' => false,
                'can_manage_stage' => false,
                'issues' => [$e->getMessage() ?: 'Не удалось определить состояние updater'],
            ];
        }

        $this->render_template('admin-page/updates', [
            'user' => $user,
            'update_state' => $snapshot,
            'update_result' => is_array($result) ? $result : null,
            'updates_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function check(Request $request): void
    {
        try {
            $result = (new AdminUpdateService())->check((int) $request->session('user_id', 0));
            $safe = $this->safeCheckResult($result);
            $request->setSession('updates_result', $safe);

            if (($safe['status'] ?? '') === 'update_available'
                && !empty($safe['update_available'])
                && (int) ($safe['target_version_code'] ?? 0) > 0
                && preg_match('/^[0-9a-f]{64}$/', (string) ($safe['package_sha256'] ?? '')) === 1) {
                $request->setSession(self::STAGE_BINDING_SESSION_KEY, [
                    'target_version_code' => (int) $safe['target_version_code'],
                    'package_sha256' => (string) $safe['package_sha256'],
                ]);
            } else {
                $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
            }

            $this->redirectWithFlash($request, true, $this->checkMessage($result));
        } catch (\Throwable $e) {
            $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось проверить обновления');
        }
    }

    public function stage(Request $request): void
    {
        $binding = $request->session(self::STAGE_BINDING_SESSION_KEY);
        // One-shot by design: every retry must follow a fresh signed check.
        $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);

        try {
            if (!is_array($binding)) {
                throw new InvalidArgumentException('Сначала повторно проверьте подписанное обновление');
            }
            $targetVersionCode = (int) ($binding['target_version_code'] ?? 0);
            $packageSha256 = strtolower(trim((string) ($binding['package_sha256'] ?? '')));
            if ($targetVersionCode <= 0 || preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1) {
                throw new InvalidArgumentException('Сначала повторно проверьте подписанное обновление');
            }

            $result = (new AdminUpdateService())->stage(
                (int) $request->session('user_id', 0),
                $targetVersionCode,
                $packageSha256
            );
            $request->setSession('updates_result', $this->safeStageResult($result));
            $this->redirectWithFlash(
                $request,
                true,
                'Обновление проверено и помещено во внешний staging. Рабочая версия не изменена.'
            );
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось подготовить обновление');
        }
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function safeCheckResult(array $result): array
    {
        return [
            'kind' => 'check',
            'status' => (string) ($result['status'] ?? 'unknown'),
            'update_available' => (bool) ($result['update_available'] ?? false),
            'channel' => (string) ($result['channel'] ?? ''),
            'target_version' => (string) ($result['target_version'] ?? ''),
            'target_version_code' => (int) ($result['target_version_code'] ?? 0),
            'source_commit' => (string) ($result['source_commit'] ?? ''),
            'requires_php' => (string) ($result['requires_php'] ?? ''),
            'min_source_version_code' => (int) ($result['min_source_version_code'] ?? 0),
            'package_filename' => (string) ($result['package_filename'] ?? ''),
            'package_size' => (int) ($result['package_size'] ?? 0),
            'package_sha256' => strtolower((string) ($result['package_sha256'] ?? '')),
            'key_id' => (string) ($result['key_id'] ?? ''),
            'notes' => isset($result['notes']) && is_string($result['notes']) ? $result['notes'] : null,
            'compatibility_message' => isset($result['compatibility_message']) && is_string($result['compatibility_message'])
                ? $result['compatibility_message']
                : null,
            'package_downloaded' => (bool) ($result['package_downloaded'] ?? false),
            'live_files_changed' => (bool) ($result['live_files_changed'] ?? false),
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function safeStageResult(array $result): array
    {
        $archive = is_array($result['archive'] ?? null) ? $result['archive'] : [];
        return [
            'kind' => 'stage',
            'status' => (string) ($result['status'] ?? 'unknown'),
            'channel' => (string) ($result['channel'] ?? ''),
            'target_version' => (string) ($result['target_version'] ?? ''),
            'target_version_code' => (int) ($result['target_version_code'] ?? 0),
            'source_commit' => (string) ($result['source_commit'] ?? ''),
            'key_id' => (string) ($result['key_id'] ?? ''),
            'package_sha256' => (string) ($result['package_sha256'] ?? ''),
            'archive_files' => (int) ($archive['files'] ?? 0),
            'archive_entries' => (int) ($archive['entries'] ?? 0),
            'live_files_changed' => (bool) ($result['live_files_changed'] ?? false),
        ];
    }

    /** @param array<string,mixed> $result */
    private function checkMessage(array $result): string
    {
        return match ((string) ($result['status'] ?? '')) {
            'update_available' => 'Доступно подписанное обновление ' . (string) ($result['target_version'] ?? ''),
            'up_to_date' => 'Установлена актуальная версия для выбранного канала',
            'ahead_of_feed' => 'Установленная версия новее версии в подписанном feed',
            'update_incompatible' => 'Найдено новое подписанное обновление, но эта установка ему не соответствует',
            default => 'Проверка подписанного feed завершена',
        };
    }

    private function redirectWithFlash(Request $request, bool $success, string $message): void
    {
        $request->setSession('updates_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('admin_updates');
    }
}
