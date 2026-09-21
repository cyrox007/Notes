<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\LicenseRuntimePolicy;
use App\Services\MaintenanceModeService;
use App\Services\PermissionService;
use Closure;
use Core\UserActionLog;
use RuntimeException;
use Throwable;

final class MessengerActionDispatcher
{
    /** @var array<string,list<string>> */
    private const ALLOWED_ROUTES = [
        'PingSocket' => ['index'],
        'MessangerSocket' => [
            'get_dialogs',
            'load',
            'create_dialog',
            'message_send',
            'edit_message',
            'delete_message',
            'mark_read',
            'user_typing',
            'stop_typing',
            'activity',
        ],
        'DialogStateSocket' => ['list', 'pin', 'archive', 'mute'],
        'ReceiptSocket' => ['list', 'delivered'],
        'MediaSocket' => ['send'],
        'GroupSocket' => [
            'info',
            'refresh',
            'rename',
            'add_members',
            'remove_member',
            'set_role',
            'transfer_owner',
            'leave',
        ],
        'SearchSocket' => ['all', 'messages', 'dialogs'],
        'ForwardSocket' => ['saved', 'save_message', 'forward'],
        'ReactionSocket' => ['list', 'toggle'],
    ];

    /** @var array<string,list<string>> */
    private const READ_ONLY_ROUTES = [
        'PingSocket' => ['index'],
        'MessangerSocket' => ['get_dialogs', 'load', 'user_typing', 'stop_typing', 'activity'],
        'DialogStateSocket' => ['list'],
        'ReceiptSocket' => ['list'],
        'GroupSocket' => ['info', 'refresh'],
        'SearchSocket' => ['all', 'messages', 'dialogs'],
        'ReactionSocket' => ['list'],
    ];

    private LicenseRuntimePolicy $licensePolicy;
    private Closure $maintenanceStateResolver;
    private Closure $messengerPermissionChecker;

    public function __construct(
        ?LicenseRuntimePolicy $licensePolicy = null,
        ?callable $maintenanceStateResolver = null,
        ?callable $messengerPermissionChecker = null
    ) {
        $this->licensePolicy = $licensePolicy ?? new LicenseRuntimePolicy();
        $this->maintenanceStateResolver = $maintenanceStateResolver !== null
            ? Closure::fromCallable($maintenanceStateResolver)
            : static fn (): array => (new MaintenanceModeService())->state();
        $this->messengerPermissionChecker = $messengerPermissionChecker !== null
            ? Closure::fromCallable($messengerPermissionChecker)
            : static fn (int $userId): bool => (new PermissionService())->hasPermission($userId, 'messenger.use');
    }

    public function canUseMessenger(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            return (bool) ($this->messengerPermissionChecker)($userId);
        } catch (Throwable $e) {
            error_log('Messenger permission evaluation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Dispatch the same Messenger action contract for WebSocket and HTTP fallback.
     *
     * @param array<string,array<int,SocketConnection>> $connections
     * @param array<string,mixed> $payload
     */
    public function dispatch(
        array $connections,
        SocketConnection $connection,
        string $action,
        array $payload,
        string $transport = 'websocket'
    ): bool {
        if (!$connection->authenticated || $connection->uid === null || $connection->userId === null) {
            $this->sendJson($connection, [
                'action' => 'error',
                'code' => 'authentication_required',
                'message' => 'Требуется авторизация',
            ]);
            return false;
        }

        if (!$this->canUseMessenger($connection->userId)) {
            $this->sendJson($connection, [
                'action' => 'error',
                'code' => 'permission_revoked',
                'message' => 'Доступ к Messenger запрещён',
            ]);
            return false;
        }

        if (substr_count($action, ':') !== 1) {
            $this->sendJson($connection, [
                'action' => 'error',
                'code' => 'invalid_action',
                'message' => 'Некорректное действие Messenger',
            ]);
            return false;
        }

        [$className, $methodName] = explode(':', $action, 2);
        if (!self::isAllowedAction($className, $methodName)) {
            error_log('Rejected Messenger transport action: ' . $action);
            $this->sendJson($connection, [
                'action' => 'error',
                'code' => 'action_not_allowed',
                'message' => 'Действие Messenger запрещено',
            ]);
            return false;
        }

        $readOnly = self::isReadOnlyAction($className, $methodName);
        if (!$readOnly) {
            try {
                $maintenanceState = ($this->maintenanceStateResolver)();
            } catch (Throwable $e) {
                error_log('Messenger maintenance state evaluation failed: ' . $e->getMessage());
                $this->sendJson($connection, [
                    'action' => 'MaintenanceMode',
                    'message' => 'Не удалось безопасно проверить режим обслуживания.',
                ]);
                return false;
            }

            if (!empty($maintenanceState['active'])) {
                $this->sendJson($connection, [
                    'action' => 'MaintenanceMode',
                    'reason' => (string) ($maintenanceState['reason'] ?? ''),
                    'transaction_id' => $maintenanceState['transaction_id'] ?? null,
                ]);
                return false;
            }

            $licenseState = $this->licensePolicy->state();
            if ($licenseState['enforced'] && !$licenseState['writable']) {
                $this->sendJson($connection, [
                    'action' => 'LicenseReadOnly',
                    'code' => $licenseState['code'],
                    'message' => $licenseState['message'],
                ]);
                return false;
            }
        }

        $fullClassName = __NAMESPACE__ . '\\' . $className;
        if (!class_exists($fullClassName) || !method_exists($fullClassName, $methodName)) {
            throw new RuntimeException('Configured Messenger action is unavailable: ' . $action);
        }

        unset($payload['user_uid'], $payload['user_id'], $payload['from_user_id']);
        $transport = $transport === 'long_poll' ? 'long_poll' : 'websocket';
        $auditPrefix = $transport === 'long_poll' ? 'http.longpoll.' : 'ws.';
        $mutatingAction = !$readOnly;

        try {
            $handler = new $fullClassName();
            $handler->$methodName($connections, $connection, $connection->uid, $payload);

            if ($mutatingAction) {
                UserActionLog::emit(
                    $connection->userId,
                    $auditPrefix . strtolower($className . '.' . $methodName),
                    'messenger',
                    $transport,
                    'success',
                    null,
                    ['action' => $action]
                );
            }
            return true;
        } catch (Throwable $e) {
            if ($mutatingAction) {
                UserActionLog::emit(
                    $connection->userId,
                    $auditPrefix . strtolower($className . '.' . $methodName),
                    'messenger',
                    $transport,
                    'failure',
                    null,
                    ['action' => $action, 'error_type' => get_debug_type($e)]
                );
            }
            error_log(sprintf('Messenger %s handler failure for %s: %s', $transport, $action, $e->getMessage()));
            $this->sendJson($connection, [
                'action' => 'error',
                'code' => 'transport_handler_failure',
                'message' => 'Не удалось выполнить действие Messenger',
            ]);
            return false;
        }
    }

    public function sendReadOnlyLicenseState(SocketConnection $connection): void
    {
        $state = $this->licensePolicy->state();
        if (!$state['enforced'] || $state['writable']) {
            return;
        }

        $this->sendJson($connection, [
            'action' => 'LicenseReadOnly',
            'code' => $state['code'],
            'message' => $state['message'],
        ]);
    }

    public static function isAllowedAction(string $className, string $methodName): bool
    {
        return isset(self::ALLOWED_ROUTES[$className])
            && in_array($methodName, self::ALLOWED_ROUTES[$className], true);
    }

    public static function isReadOnlyAction(string $className, string $methodName): bool
    {
        return isset(self::READ_ONLY_ROUTES[$className])
            && in_array($methodName, self::READ_ONLY_ROUTES[$className], true);
    }

    /** @param array<string,mixed> $payload */
    private function sendJson(SocketConnection $connection, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            $connection->send($json);
        }
    }
}
