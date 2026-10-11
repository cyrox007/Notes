<?php

declare(strict_types=1);

namespace App\Controllers;

require_once dirname(__DIR__, 2) . '/core/Controller.php';
require_once dirname(__DIR__) . '/services/notifications/DatabaseNotificationCenter.php';
require_once dirname(__DIR__) . '/services/notifications/DatabaseNotificationPreferences.php';

use App\Services\Notifications\DatabaseNotificationCenter;
use App\Services\Notifications\DatabaseNotificationPreferences;
use Core\Controller;
use Core\Request;
use Core\UserActionLog;
use InvalidArgumentException;
use Throwable;

final class NotificationController extends Controller
{
    public function index(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $center = new DatabaseNotificationCenter();
            return [
                'status' => 'ok',
                ...$center->listForUser(
                    $this->userId($request),
                    [
                        'status' => $request->get('status', 'all'),
                        'category' => $request->get('category'),
                        'importance' => $request->get('importance'),
                        'source' => $request->get('source'),
                    ],
                    $this->queryInt($request, 'page', 1),
                    $this->queryInt($request, 'limit', 20),
                ),
            ];
        });
    }

    public function unreadCount(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $center = new DatabaseNotificationCenter();
            return [
                'status' => 'ok',
                'unread_count' => $center->unreadCount($this->userId($request)),
            ];
        });
    }

    public function preferences(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $preferences = new DatabaseNotificationPreferences();
            return [
                'status' => 'ok',
                ...$preferences->summaryForUser($this->userId($request)),
            ];
        });
    }

    public function updatePreferences(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $payload = $request->json();
            if (!is_array($payload) || array_is_list($payload)) {
                throw new InvalidArgumentException('Тело запроса настроек должно быть JSON-объектом');
            }

            $userId = $this->userId($request);
            $preferences = new DatabaseNotificationPreferences();
            $result = $preferences->updateForUser($userId, $payload);
            $subscription = $result['subscription'];

            UserActionLog::emit(
                $userId,
                'notifications.subscription.updated',
                'core',
                'http',
                'success',
                200,
                [
                    'category' => (string) ($subscription['category'] ?? ''),
                    'external_enabled' => (bool) ($subscription['external_enabled'] ?? false),
                    'channels' => (array) ($subscription['channels'] ?? []),
                ]
            );

            return [
                'status' => 'ok',
                ...$result,
            ];
        });
    }

    public function markRead(Request $request, int $notificationId): void
    {
        $this->setReadState($request, $notificationId, true);
    }

    public function markUnread(Request $request, int $notificationId): void
    {
        $this->setReadState($request, $notificationId, false);
    }

    public function markAllRead(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $center = new DatabaseNotificationCenter();
            $userId = $this->userId($request);
            $updated = $center->markAllRead($userId);
            return [
                'status' => 'ok',
                'updated' => $updated,
                'unread_count' => $center->unreadCount($userId),
            ];
        });
    }

    private function setReadState(Request $request, int $notificationId, bool $read): void
    {
        $this->handle(function () use ($request, $notificationId, $read): array {
            $center = new DatabaseNotificationCenter();
            $userId = $this->userId($request);
            if ($read) {
                $center->markRead($userId, $notificationId);
            } else {
                $center->markUnread($userId, $notificationId);
            }

            return [
                'status' => 'ok',
                'unread_count' => $center->unreadCount($userId),
            ];
        });
    }

    /** @param callable():array<string,mixed> $operation */
    private function handle(callable $operation): void
    {
        try {
            $this->json($operation());
        } catch (InvalidArgumentException $e) {
            $this->json([
                'status' => 'error',
                'code' => 'invalid_notification_request',
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            error_log('Ошибка API центра уведомлений: ' . $e::class . ': ' . $e->getMessage());
            $this->json([
                'status' => 'error',
                'code' => 'notification_center_failed',
                'message' => 'Не удалось обработать запрос центра уведомлений.',
            ], 500);
        }
    }

    private function userId(Request $request): int
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('Активная пользовательская сессия отсутствует');
        }
        return $userId;
    }

    private function queryInt(Request $request, string $name, int $default): int
    {
        $value = $request->get($name, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new InvalidArgumentException('Параметр ' . $name . ' должен быть целым положительным числом');
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->responseJson($payload);
    }
}
