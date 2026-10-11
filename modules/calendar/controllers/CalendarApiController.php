<?php

declare(strict_types=1);

namespace Modules\Calendar\Controllers;

use App\Services\PermissionService;
use Core\Controller;
use Core\Request;
use Core\UserActionLog;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Modules\Calendar\Services\CalendarEventService;
use Throwable;

final class CalendarApiController extends Controller
{
    public function index(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $from = $this->queryDate($request->get('from'), 'from');
            $to = $this->queryDate($request->get('to'), 'to');
            return ['status' => 'ok', ...(new CalendarEventService())->listForUser($userId, $from, $to)];
        });
    }

    public function show(Request $request, string $uid): void
    {
        $this->handle($request, static fn (int $userId): array => [
            'status' => 'ok',
            'event' => (new CalendarEventService())->detailForUser($userId, $uid),
        ]);
    }

    public function create(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $event = (new CalendarEventService())->createForUser($userId, $this->payload($request));
            $this->audit($userId, 'calendar.event.created', $event);
            return ['status' => 'ok', 'event' => $event];
        });
    }

    public function update(Request $request, string $uid): void
    {
        $this->handle($request, function (int $userId) use ($request, $uid): array {
            $event = (new CalendarEventService())->updateForUser($userId, $uid, $this->payload($request));
            $this->audit($userId, 'calendar.event.updated', $event);
            return ['status' => 'ok', 'event' => $event];
        });
    }

    public function delete(Request $request, string $uid): void
    {
        $this->handle($request, function (int $userId) use ($uid): array {
            (new CalendarEventService())->cancelForUser($userId, $uid);
            UserActionLog::emit(
                $userId,
                'calendar.event.deleted',
                'calendar',
                'http',
                'success',
                200,
                ['event_uid' => $uid]
            );
            return ['status' => 'ok'];
        });
    }

    /** @param callable(int):array<string,mixed> $operation */
    private function handle(Request $request, callable $operation): void
    {
        try {
            $userId = (int) $request->session('user_id', 0);
            (new PermissionService())->requirePermission($userId, 'calendar.use');
            $this->json($operation($userId));
        } catch (DomainException $e) {
            $status = $e->getCode() === 401 ? 401 : 403;
            $this->json(['status' => 'error', 'code' => 'calendar_access_denied', 'message' => $e->getMessage()], $status);
        } catch (InvalidArgumentException $e) {
            $this->json(['status' => 'error', 'code' => 'invalid_calendar_request', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Ошибка API Calendar: ' . $e::class . ': ' . $e->getMessage());
            $this->json([
                'status' => 'error',
                'code' => 'calendar_request_failed',
                'message' => 'Не удалось обработать запрос Calendar.',
            ], 500);
        }
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $payload = $request->json();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Тело запроса события должно быть JSON-объектом');
        }
        return $payload;
    }

    private function queryDate(mixed $value, string $name): DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Параметр ' . $name . ' обязателен');
        }
        try {
            $date = new DateTimeImmutable(trim($value));
        } catch (Throwable) {
            throw new InvalidArgumentException('Параметр ' . $name . ' содержит неверную дату');
        }
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param array<string,mixed> $event */
    private function audit(int $userId, string $action, array $event): void
    {
        UserActionLog::emit(
            $userId,
            $action,
            'calendar',
            'http',
            'success',
            http_response_code() >= 400 ? http_response_code() : 200,
            [
                'event_uid' => (string) ($event['uid'] ?? ''),
                'all_day' => (bool) ($event['all_day'] ?? false),
                'timezone' => (string) ($event['timezone'] ?? ''),
                'recurrence' => (string) (($event['recurrence']['frequency'] ?? 'none')),
            ]
        );
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
