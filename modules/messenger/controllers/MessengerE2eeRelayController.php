<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessengerE2eeRelayService;
use Core\Controller;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** Транспорт публичных ключей и непрозрачных сообщений E2EE. */
final class MessengerE2eeRelayController extends Controller
{
    public function devices(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            return ['devices' => $service->devices($user, (string) $request->get('user_uid', ''))];
        });
    }

    public function register(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            $deviceId = self::field($body, 'device_id');
            if (!is_array($body['identity'] ?? null) || array_is_list($body['identity'])) {
                throw new InvalidArgumentException('Нужен объект открытой идентичности устройства');
            }
            $service->register($user, $deviceId, $body['identity']);
            return ['registered' => true, 'device_id' => $deviceId];
        });
    }

    public function prekeys(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            if (!is_array($body['keys'] ?? null)) {
                throw new InvalidArgumentException('Нужен объект одноразовых ключей');
            }
            return ['published' => $service->publishPrekeys($user, self::field($body, 'device_id'), $body['keys'])];
        });
    }

    public function claim(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            return ['prekey' => $service->claimPrekey(
                $user,
                self::field($body, 'user_uid'),
                self::field($body, 'device_id')
            )];
        });
    }

    public function send(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            if (!is_array($body['envelope'] ?? null)) {
                throw new InvalidArgumentException('Нужен пакет зашифрованного сообщения');
            }
            $service->send(
                $user,
                self::field($body, 'sender_device_id'),
                self::field($body, 'recipient_user_uid'),
                self::field($body, 'recipient_device_id'),
                self::field($body, 'dialog_uid'),
                self::field($body, 'transaction_id'),
                $body['envelope']
            );
            return ['queued' => true];
        });
    }

    public function inbox(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $cursor = (string) $request->get('after', '0');
            if (strlen($cursor) > 18 || !ctype_digit($cursor)) {
                throw new InvalidArgumentException('Некорректный курсор входящих пакетов');
            }
            return ['messages' => $service->inbox(
                $user,
                (string) $request->get('device_id', ''),
                (int) $cursor
            )];
        });
    }

    public function acknowledge(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            if (!is_array($body['ids'] ?? null) || !array_is_list($body['ids'])) {
                throw new InvalidArgumentException('Нужен список полученных пакетов');
            }
            $service->acknowledge($user, self::field($body, 'device_id'), $body['ids']);
            return ['acknowledged' => true];
        });
    }

    public function revoke(Request $request): void
    {
        $this->handle($request, static function (MessengerE2eeRelayService $service, int $user) use ($request): array {
            $body = self::payload($request);
            $service->revoke($user, self::field($body, 'device_id'));
            return ['revoked' => true];
        });
    }

    /** @param callable(MessengerE2eeRelayService,int):array<string,mixed> $action */
    private function handle(Request $request, callable $action): void
    {
        $userId = (int) $request->session('user_id');
        if ($userId <= 0) {
            $this->fail('Требуется авторизация', 401);
            return;
        }
        header('Cache-Control: no-store');
        try {
            $this->responseJson(['status' => 'ok'] + $action(new MessengerE2eeRelayService(), $userId));
        } catch (InvalidArgumentException | JsonException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            $code = $e->getCode();
            $this->fail($e->getMessage(), in_array($code, [403, 404, 409, 429], true) ? $code : 403);
        } catch (Throwable $e) {
            error_log('Messenger E2EE relay: ' . get_class($e));
            $this->fail('Защищённый транспорт временно недоступен', 503);
        }
    }

    /** @return array<string,mixed> */
    private static function payload(Request $request): array
    {
        $raw = (string) $request->rawPost('payload', '');
        if ($raw === '' || strlen($raw) > 65536) {
            throw new InvalidArgumentException('Отсутствуют данные или превышен лимит запроса');
        }
        $result = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) {
            throw new InvalidArgumentException('Ожидается объект JSON');
        }
        return $result;
    }

    /** @param array<string,mixed> $body */
    private static function field(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 180) {
            throw new InvalidArgumentException('Отсутствует или некорректно поле ' . $key);
        }
        return $value;
    }

    private function fail(string $message, int $code): void
    {
        http_response_code($code);
        $this->responseJson(['status' => 'error', 'message' => $message]);
    }
}
