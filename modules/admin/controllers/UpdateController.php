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
    private const APPLY_BINDING_SESSION_KEY = 'updates_apply_binding';

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

        // Привязки действуют только для результата, который прямо сейчас показан
        // администратору. Перезагрузка или другой результат не должны оставлять
        // старый релиз неявно подготовленным к действию.
        $checkReady = is_array($result)
            && ($result['kind'] ?? '') === 'check'
            && ($result['status'] ?? '') === 'update_available'
            && !empty($result['update_available']);
        $stageReady = is_array($result)
            && ($result['kind'] ?? '') === 'stage'
            && ($result['status'] ?? '') === 'staged';

        if (!$checkReady) {
            $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
        }
        if (!$checkReady && !$stageReady) {
            $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
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

        $this->render_template('@admin/updates', [
            'user' => $user,
            'update_state' => $snapshot,
            'update_result' => is_array($result) ? $result : null,
            'updates_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function status(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        try {
            $actorId = (int) $request->session('user_id', 0);
            $service = new AdminUpdateService();
            $result = $service->check($actorId);
            $snapshot = $service->snapshot($actorId);

            echo json_encode([
                'status' => 'ok',
                'update_available' => (bool) ($result['update_available'] ?? false),
                'state' => (string) ($result['status'] ?? 'unknown'),
                'target_version' => (string) ($result['target_version'] ?? ''),
                'target_version_code' => (int) ($result['target_version_code'] ?? 0),
                'can_apply' => !empty($snapshot['can_apply']),
                'message' => $this->checkMessage($result),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        } catch (\Throwable) {
            // Фоновая проверка не должна ломать интерфейс при временной
            // недоступности сервера обновлений. Ручная проверка в Admin
            // по-прежнему покажет оператору подробную диагностику.
            echo json_encode([
                'status' => 'unavailable',
                'update_available' => false,
                'can_apply' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        }
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
                $binding = [
                    'target_version_code' => (int) $safe['target_version_code'],
                    'package_sha256' => (string) $safe['package_sha256'],
                ];
                $request->setSession(self::STAGE_BINDING_SESSION_KEY, $binding);
                $request->setSession(self::APPLY_BINDING_SESSION_KEY, $binding);
            } else {
                $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
                $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
            }

            $this->redirectWithFlash($request, true, $this->checkMessage($result));
        } catch (\Throwable $e) {
            $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);
            $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось проверить обновления');
        }
    }

    public function stage(Request $request): void
    {
        $binding = $request->session(self::STAGE_BINDING_SESSION_KEY);
        // Одноразовая привязка: каждая повторная попытка требует новой проверки подписи.
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
            $safe = $this->safeStageResult($result);
            $request->setSession('updates_result', $safe);
            $request->setSession(self::APPLY_BINDING_SESSION_KEY, [
                'target_version_code' => (int) ($safe['target_version_code'] ?? 0),
                'package_sha256' => (string) ($safe['package_sha256'] ?? ''),
            ]);
            $this->redirectWithFlash(
                $request,
                true,
                'Обновление проверено и помещено во внешний staging. Рабочая версия не изменена.'
            );
        } catch (\Throwable $e) {
            $this->redirectWithFlash($request, false, $e->getMessage() ?: 'Не удалось подготовить обновление');
        }
    }

    public function webStart(Request $request): void
    {
        $binding = $request->session(self::APPLY_BINDING_SESSION_KEY);
        $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
        $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);

        try {
            [$targetVersionCode, $packageSha256] = $this->validatedBinding($binding);
            $result = (new AdminUpdateService())->beginWebApply(
                (int) $request->session('user_id', 0),
                $targetVersionCode,
                $packageSha256
            );

            $this->jsonResponse(200, [
                'success' => true,
                'result' => [
                    'status' => (string) ($result['status'] ?? 'in_progress'),
                    'phase' => (string) ($result['phase'] ?? 'backup'),
                    'progress' => (int) ($result['progress'] ?? 20),
                    'message' => (string) ($result['message'] ?? ''),
                    'transaction_id' => (string) ($result['transaction_id'] ?? ''),
                    'continuation_token' => (string) ($result['continuation_token'] ?? ''),
                    'target_version' => (string) ($result['target_version'] ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(
                in_array((int) $e->getCode(), [400, 403, 409, 503], true)
                    ? (int) $e->getCode()
                    : 500,
                [
                    'success' => false,
                    'error' => 'update_start_failed',
                    'message' => $e->getMessage() ?: 'Не удалось начать обновление',
                ]
            );
        }
    }

    /**
     * Однокнопочный запуск из глобального уведомления.
     *
     * Повторно проверяет подписанный feed и начинает тот же пошаговый web-контур,
     * что используется на странице Admin.
     */
    public function webStartLatest(Request $request): void
    {
        $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
        $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);

        try {
            $result = (new AdminUpdateService())->beginLatestWebApply(
                (int) $request->session('user_id', 0)
            );

            $this->jsonResponse(200, [
                'success' => true,
                'result' => [
                    'status' => (string) ($result['status'] ?? 'in_progress'),
                    'phase' => (string) ($result['phase'] ?? 'backup'),
                    'progress' => (int) ($result['progress'] ?? 20),
                    'message' => (string) ($result['message'] ?? ''),
                    'transaction_id' => (string) ($result['transaction_id'] ?? ''),
                    'continuation_token' => (string) ($result['continuation_token'] ?? ''),
                    'target_version' => (string) ($result['target_version'] ?? ''),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(
                in_array((int) $e->getCode(), [400, 403, 409, 503], true)
                    ? (int) $e->getCode()
                    : 500,
                [
                    'success' => false,
                    'error' => 'update_latest_start_failed',
                    'message' => $e->getMessage() ?: 'Не удалось начать установку последнего обновления',
                ]
            );
        }
    }

    /**
     * Первый web-шаг выполняется через обычный Router, пока maintenance ещё
     * не включён. Последующие шаги принимает ранний capability-защищённый мост.
     */
    public function webStep(Request $request): void
    {
        $transactionId = trim((string) $request->server(
            'HTTP_X_WORKSPACE_UPDATE_TRANSACTION',
            ''
        ));
        $token = trim((string) $request->server(
            'HTTP_X_WORKSPACE_UPDATE_TOKEN',
            ''
        ));
        $actorId = (int) $request->session('user_id', 0);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            if ($transactionId === '' || $token === '') {
                throw new InvalidArgumentException(
                    'Не передано безопасное продолжение updater-транзакции'
                );
            }

            $result = (new AdminUpdateService())->stepWebApply(
                $actorId,
                $transactionId,
                $token
            );

            if (($result['status'] ?? '') === 'committed') {
                $this->resetOpcodeCacheAfterUpdate();
            }

            $this->jsonResponse(200, [
                'success' => true,
                'result' => $result,
            ]);
        } catch (\Core\UpdateWebTransactionException $e) {
            $status = in_array((int) $e->getCode(), [403, 409], true)
                ? (int) $e->getCode()
                : 500;
            $this->jsonResponse($status, [
                'success' => false,
                'error' => $e->safeCode,
                'message' => $e->getMessage(),
                'retryable' => $e->safeCode === 'operation_busy',
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse(500, [
                'success' => false,
                'error' => 'update_step_failed',
                'message' => $e->getMessage() ?: 'Шаг обновления завершился ошибкой',
                'retryable' => true,
            ]);
        }
    }

    public function applyLatest(Request $request): void
    {
        $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
        $request->unsetSession(self::STAGE_BINDING_SESSION_KEY);

        try {
            $result = (new AdminUpdateService())->applyLatest(
                (int) $request->session('user_id', 0)
            );
            $this->resetOpcodeCacheAfterUpdate();
            $request->setSession('updates_result', $this->safeApplyResult($result));
            $this->redirectWithFlash(
                $request,
                true,
                'Обновление установлено. Workspace Organizer работает на новой версии.'
            );
        } catch (\Throwable $e) {
            $this->redirectWithFlash(
                $request,
                false,
                $e->getMessage() ?: 'Не удалось установить последнее обновление'
            );
        }
    }

    public function apply(Request $request): void
    {
        $binding = $request->session(self::APPLY_BINDING_SESSION_KEY);
        $request->unsetSession(self::APPLY_BINDING_SESSION_KEY);
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

            $result = (new AdminUpdateService())->apply(
                (int) $request->session('user_id', 0),
                $targetVersionCode,
                $packageSha256
            );
            $this->resetOpcodeCacheAfterUpdate();
            $request->setSession('updates_result', $this->safeApplyResult($result));
            $this->redirectWithFlash(
                $request,
                true,
                'Обновление установлено. Workspace Organizer работает на новой версии.'
            );
        } catch (\Throwable $e) {
            $this->redirectWithFlash(
                $request,
                false,
                $e->getMessage() ?: 'Не удалось установить обновление'
            );
        }
    }

    /** @return array{0:int,1:string} */
    private function validatedBinding(mixed $binding): array
    {
        if (!is_array($binding)) {
            throw new InvalidArgumentException(
                'Сначала повторно проверьте подписанное обновление'
            );
        }

        $targetVersionCode = (int) ($binding['target_version_code'] ?? 0);
        $packageSha256 = strtolower(trim((string) ($binding['package_sha256'] ?? '')));
        if ($targetVersionCode <= 0
            || preg_match('/^[0-9a-f]{64}$/D', $packageSha256) !== 1) {
            throw new InvalidArgumentException(
                'Сначала повторно проверьте подписанное обновление'
            );
        }

        return [$targetVersionCode, $packageSha256];
    }

    /** @param array<string,mixed> $payload */
    private function jsonResponse(int $status, array $payload): never
    {
        http_response_code($status);
        header('Cache-Control: no-store, private');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        exit;
    }

    private function resetOpcodeCacheAfterUpdate(): void
    {
        clearstatcache(true);
        if (!function_exists('opcache_reset')) {
            return;
        }

        @opcache_reset();
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

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function safeApplyResult(array $result): array
    {
        return [
            'kind' => 'apply',
            'status' => (string) ($result['status'] ?? 'unknown'),
            'target_version' => (string) ($result['target_version'] ?? ''),
            'target_version_code' => (int) ($result['target_version_code'] ?? 0),
            'package_sha256' => strtolower((string) ($result['package_sha256'] ?? '')),
            'installed_version' => (string) ($result['installed_version'] ?? ''),
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
