<?php

declare(strict_types=1);

namespace App\Controllers;

require_once dirname(__DIR__) . '/services/notifications/PushSubscriptionService.php';
require_once dirname(__DIR__) . '/services/notifications/WebPushCrypto.php';

use App\Services\Notifications\PushSubscriptionService;
use App\Services\Notifications\WebPushCrypto;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class NotificationPushController extends Controller
{
    public function publicKey(Request $request): void
    {
        try {
            $this->userId($request);
            $key = WebPushCrypto::publicApplicationKey();
            $this->reply(['status' => 'ok', 'available' => true, 'public_key' => $key]);
        } catch (RuntimeException) {
            $this->reply([
                'status' => 'ok', 'available' => false,
                'message' => 'Web Push не настроен администратором установки',
            ]);
        } catch (Throwable) {
            $this->reply(['status' => 'error', 'message' => 'Не удалось проверить Web Push'], 500);
        }
    }

    public function status(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $service = new PushSubscriptionService();
            $hash = (string) $request->session('notes_push_hash', '');
            $state = $service->status($this->userId($request));
            $state['current'] = false;
            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                $state['current'] = $service->ownsHash($this->userId($request), $hash);
            }
            return ['status' => 'ok', ...$state];
        });
    }

    public function subscribe(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $data = $request->json();
            if (!is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Требуется объект браузерной подписки');
            }
            $id = $this->userId($request);
            $result = (new PushSubscriptionService())->subscribe($id, $data);
            $request->setSession('notes_push_hash', $result['endpoint_hash']);
            return [
                'status' => 'ok', 'registered' => true,
                'active_devices' => $result['active'],
            ];
        });
    }

    public function unsubscribe(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $data = $request->json();
            if (!is_array($data) || !is_string($data['endpoint'] ?? null)) {
                throw new InvalidArgumentException('Нужен адрес отключаемой подписки');
            }
            $result = (new PushSubscriptionService())->revoke(
                $this->userId($request), $data['endpoint']
            );
            $request->unsetSession('notes_push_hash');
            return ['status' => 'ok', 'revoked' => $result];
        });
    }

    /** @param callable():array<string,mixed> $operation */
    private function handle(callable $operation): void
    {
        try {
            $this->reply($operation());
        } catch (DomainException|InvalidArgumentException $error) {
            $this->reply(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Web Push: ошибка API ' . $error::class);
            $this->reply([
                'status' => 'error',
                'message' => 'Ошибка настройки устройства. Проверьте миграцию и журнал сервера.',
            ], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function reply(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->responseJson($payload);
    }

    private function userId(Request $request): int
    {
        $id = (int) $request->session('user_id', 0);
        if ($id < 1) {
            throw new InvalidArgumentException('Войдите в Notes для настройки устройства');
        }
        return $id;
    }
}
