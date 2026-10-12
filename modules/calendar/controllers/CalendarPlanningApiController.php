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
use Modules\Calendar\Services\CalendarAgendaService;
use Modules\Calendar\Services\CalendarLinkService;
use Modules\Calendar\Services\CalendarTimeBlockService;
use Throwable;

final class CalendarPlanningApiController extends Controller
{
    public function agenda(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $from = $this->queryDate($request->get('from'), 'from');
            $to = $this->queryDate($request->get('to'), 'to');
            return ['status' => 'ok', ...(new CalendarAgendaService())->listForUser($userId, $from, $to)];
        });
    }

    public function linkTargets(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $type = $this->queryText($request->get('type'), 'type', 20);
            $query = $this->queryOptionalText($request->get('q'), 190);
            $limit = $this->queryInt($request->get('limit'), 20, 1, 50);
            return [
                'status' => 'ok',
                ...(new CalendarLinkService())->targetOptions($userId, $type, $query, $limit),
            ];
        });
    }

    public function links(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $sourceType = $this->queryText($request->get('source_type'), 'source_type', 20);
            $sourceUid = $this->queryText($request->get('source_uid'), 'source_uid', 64);
            return [
                'status' => 'ok',
                'links' => (new CalendarLinkService())->linksForItem($userId, $sourceType, $sourceUid),
            ];
        });
    }

    public function replaceLinks(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $payload = $this->payload($request);
            $sourceType = (string) ($payload['source_type'] ?? '');
            $sourceUid = (string) ($payload['source_uid'] ?? '');
            $links = $payload['links'] ?? null;
            $result = (new CalendarLinkService())->replaceLinks($userId, $sourceType, $sourceUid, $links);
            UserActionLog::emit(
                $userId,
                'calendar.links.updated',
                'calendar',
                'http',
                'success',
                200,
                [
                    'source_type' => $sourceType,
                    'source_uid' => $sourceUid,
                    'links_count' => count($result),
                ]
            );
            return ['status' => 'ok', 'links' => $result];
        });
    }

    public function showTimeBlock(Request $request, string $uid): void
    {
        $this->handle($request, static fn (int $userId): array => [
            'status' => 'ok',
            'block' => (new CalendarTimeBlockService())->detailForUser($userId, $uid),
        ]);
    }

    public function createTimeBlock(Request $request): void
    {
        $this->handle($request, function (int $userId) use ($request): array {
            $block = (new CalendarTimeBlockService())->createForUser($userId, $this->payload($request));
            $this->auditBlock($userId, 'calendar.time_block.created', $block);
            return ['status' => 'ok', 'block' => $block];
        });
    }

    public function updateTimeBlock(Request $request, string $uid): void
    {
        $this->handle($request, function (int $userId) use ($request, $uid): array {
            $block = (new CalendarTimeBlockService())->updateForUser($userId, $uid, $this->payload($request));
            $this->auditBlock($userId, 'calendar.time_block.updated', $block);
            return ['status' => 'ok', 'block' => $block];
        });
    }

    public function deleteTimeBlock(Request $request, string $uid): void
    {
        $this->handle($request, function (int $userId) use ($uid): array {
            (new CalendarTimeBlockService())->cancelForUser($userId, $uid);
            UserActionLog::emit(
                $userId,
                'calendar.time_block.deleted',
                'calendar',
                'http',
                'success',
                200,
                ['block_uid' => $uid]
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
            error_log('Ошибка API планирования Calendar: ' . $e::class . ': ' . $e->getMessage());
            $this->json([
                'status' => 'error',
                'code' => 'calendar_planning_failed',
                'message' => 'Не удалось обработать запрос планирования Calendar.',
            ], 500);
        }
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $payload = $request->json();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Тело запроса должно быть JSON-объектом');
        }
        return $payload;
    }

    private function queryDate(mixed $value, string $name): DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Параметр ' . $name . ' обязателен');
        }
        try {
            return (new DateTimeImmutable(trim($value)))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InvalidArgumentException('Параметр ' . $name . ' содержит неверную дату');
        }
    }

    private function queryText(mixed $value, string $name, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Параметр ' . $name . ' обязателен');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Параметр ' . $name . ' содержит недопустимое значение');
        }
        return $value;
    }

    private function queryOptionalText(mixed $value, int $max): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Поисковый запрос должен быть строкой');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Поисковый запрос слишком длинный');
        }
        return $value;
    }

    private function queryInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Числовой параметр находится вне допустимого диапазона');
        }
        return $value;
    }

    /** @param array<string,mixed> $block */
    private function auditBlock(int $userId, string $action, array $block): void
    {
        UserActionLog::emit(
            $userId,
            $action,
            'calendar',
            'http',
            'success',
            200,
            [
                'block_uid' => (string) ($block['uid'] ?? ''),
                'timezone' => (string) ($block['timezone'] ?? ''),
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
