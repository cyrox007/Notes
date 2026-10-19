<?php

declare(strict_types=1);

namespace Modules\Defects\Controllers;

use App\Services\PermissionService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\UserActionLog;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Modules\Defects\Services\DefectReferenceService;
use Modules\Defects\Services\DefectReferenceImportService;
use Modules\Defects\Services\DefectReportService;
use Modules\Defects\Services\DefectRegistrationService;
use Modules\Defects\Services\DefectRegistryService;
use Throwable;

/**
 * HTTP-граница: ядро отвечает за Request, Controller, Router, RBAC и CSRF.
 * Контроллер не выполняет SQL и не создаёт собственный механизм ответа.
 */
final class DefectsApiController extends Controller
{
    public function list(Request $request): void
    {
        $this->handle($request, 'defects.use', function () use ($request): array {
            return $this->reports()->search(
                $this->reportFilters($request),
                $this->integer($request->get('limit', 40), 'limit'),
                $this->integer($request->get('after_id', 0), 'after_id')
            );
        });
    }

    public function report(Request $request): void
    {
        $this->handle($request, 'defects.use', function () use ($request): array {
            return ['report' => $this->reports()->summary(
                $this->reportFilters($request),
                $this->integer($request->get('after_area_id', 0), 'after_area_id'),
                $this->integer($request->get('area_limit', 50), 'area_limit')
            )];
        });
    }

    /** @return array<string,mixed> */
    private function reportFilters(Request $request): array
    {
        $filters = [];
        foreach (['status', 'priority', 'area_id', 'object_id', 'service_id', 'from', 'to'] as $key) {
            $value = $request->get($key);
            if ($value !== null) {
                $filters[$key] = $value;
            }
        }
        return $filters;
    }

    public function show(Request $request, int $id): void
    {
        $this->handle($request, 'defects.use', function () use ($id): array {
            $record = $this->registry()->detail($id);
            if ($record === null) {
                throw new DomainException('Дефект не найден', 404);
            }
            return ['defect' => $record];
        });
    }

    public function references(Request $request): void
    {
        $this->handle($request, 'defects.use', function () use ($request): array {
            $references = $this->referencesService();
            $limit = $this->integer($request->get('limit', 100), 'limit');
            $cursor = $this->integer($request->get('after_id', 0), 'after_id');
            $kind = (string) $request->get('kind', 'areas');
            return ['items' => match ($kind) {
                'areas' => $references->areas($limit, $cursor),
                'categories' => $references->categories($this->integer($request->get('parent_id'), 'parent_id'), $limit, $cursor),
                'objects' => $references->objects($this->integer($request->get('parent_id'), 'parent_id'), $limit, $cursor),
                'services' => $references->services($limit, $cursor),
                default => throw new InvalidArgumentException('Неизвестный справочник'),
            }];
        });
    }

    public function createReference(Request $request): void
    {
        $this->handle($request, 'defects.manage', function (int $userId) use ($request): array {
            $data = $this->payload($request);
            $service = $this->referencesService();
            $name = $this->requiredString($data, 'name');
            $id = match ($data['kind'] ?? null) {
                'areas' => $service->createArea($name),
                'categories' => $service->createCategory($this->integer($data['parent_id'] ?? null, 'parent_id'), $name),
                'objects' => $service->createObject($this->integer($data['parent_id'] ?? null, 'parent_id'), $name),
                'services' => $service->createService($name),
                default => throw new InvalidArgumentException('Неизвестный справочник'),
            };
            $this->audit($userId, 'defects.reference.created', ['reference_id' => $id, 'kind' => $data['kind']]);
            return ['reference_id' => $id];
        }, 201);
    }

    public function previewImport(Request $request): void
    {
        $this->handle($request, 'defects.manage', function () use ($request): array {
            return ['preview' => $this->importService()->preview($this->uploadedText($request))];
        });
    }

    public function applyImport(Request $request): void
    {
        $this->handle($request, 'defects.manage', function (int $userId) use ($request): array {
            $hash = $request->rawPost('expected_hash');
            if (!is_string($hash)) {
                throw new InvalidArgumentException('Предварительный просмотр обязателен');
            }

            $result = $this->importService()->apply($this->uploadedText($request), $hash);
            if ($result['created'] > 0) {
                $this->audit($userId, 'defects.references.imported', $result);
            }
            return ['import' => $result];
        });
    }

    private function uploadedText(Request $request): string
    {
        $file = $request->file('file');
        if (!is_array($file)) {
            throw new InvalidArgumentException('Файл справочников не загружен');
        }
        $path = $file['tmp_name'] ?? null;
        if (!is_string($path) || !is_uploaded_file($path) || is_link($path)) {
            throw new InvalidArgumentException('Недопустимый загруженный файл');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > 524288) {
            throw new InvalidArgumentException('Файл должен быть не больше 512 КиБ');
        }
        $data = file_get_contents($path);
        if (!is_string($data)) {
            throw new InvalidArgumentException('Не удалось прочитать файл');
        }
        return $data;
    }

    private function importService(): DefectReferenceImportService
    {
        return new DefectReferenceImportService(DatabaseManager::getInstance());
    }

    public function create(Request $request): void
    {
        $this->handle($request, 'defects.use', function (int $userId) use ($request): array {
            $data = $this->payload($request);
            $services = $data['service_ids'] ?? null;
            if (!is_array($services) || !array_is_list($services)) {
                throw new InvalidArgumentException('Укажите список ответственных служб');
            }

            $serviceIds = array_map(fn (mixed $id): int => $this->integer($id, 'service_ids'), $services);
            $result = (new DefectRegistrationService(DatabaseManager::getInstance()))->register(
                $this->integer($data['object_id'] ?? null, 'object_id'),
                $this->requiredString($data, 'description'),
                $this->requiredString($data, 'priority'),
                $this->discoveredAt($this->requiredString($data, 'discovered_at')),
                $userId,
                $this->requiredString($data, 'registered_by_name'),
                $serviceIds,
                $this->requiredString($data, 'command_id')
            );
            if (!$result['replayed']) {
                $this->audit($userId, 'defects.created', ['defect_id' => $result['id']]);
            }
            return ['defect' => $result];
        }, 201);
    }

    public function close(Request $request, int $id): void
    {
        $this->handle($request, 'defects.use', function (int $userId) use ($request, $id): array {
            $data = $this->payload($request);
            $result = $this->registry()->close(
                $id,
                $this->integer($data['version'] ?? null, 'version'),
                $userId,
                $this->requiredString($data, 'completed_work'),
                $this->requiredString($data, 'command_id')
            );
            if (!$result['replayed']) {
                $this->audit($userId, 'defects.closed', ['defect_id' => $result['id']]);
            }
            return ['defect' => $result];
        });
    }

    /** @param callable(int):array<string,mixed> $action */
    private function handle(Request $request, string $permission, callable $action, int $successStatus = 200): void
    {
        try {
            $userId = (int) $request->session('user_id', 0);
            (new PermissionService())->requirePermission($userId, $permission);
            $this->respond(['status' => 'ok'] + $action($userId), $successStatus);
        } catch (DomainException $error) {
            $status = in_array($error->getCode(), [401, 403, 404, 409], true) ? $error->getCode() : 409;
            $this->respond(['status' => 'error', 'code' => 'defect_conflict', 'message' => $error->getMessage()], $status);
        } catch (InvalidArgumentException $error) {
            $this->respond(['status' => 'error', 'code' => 'invalid_defect_request', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Ошибка модуля Дефекты: ' . $error::class . ': ' . $error->getMessage());
            $this->respond(['status' => 'error', 'code' => 'defect_request_failed', 'message' => 'Не удалось выполнить операцию.'], 500);
        }
    }

    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        $data = $request->rawJson();
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Тело запроса должно быть JSON-объектом');
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Обязательное поле: ' . $field);
        }
        return $value;
    }

    private function integer(mixed $value, string $field): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^\d{1,12}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Неверное целое значение: ' . $field);
        }
        return (int) $value;
    }

    private function discoveredAt(string $value): DateTimeImmutable
    {
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new InvalidArgumentException('Дата обнаружения должна содержать часовой пояс');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new InvalidArgumentException('Неверная дата обнаружения');
        }
    }

    /** @param array<string,int|string> $context */
    private function audit(int $userId, string $action, array $context): void
    {
        UserActionLog::emit($userId, $action, 'defects', 'http', 'success', 200, $context);
    }

    /** @param array<string,mixed> $body */
    private function respond(array $body, int $status): void
    {
        http_response_code($status);
        header('Cache-Control: private, no-store');
        header('Content-Type: application/json; charset=utf-8');
        $this->responseJson($body);
    }

    private function reports(): DefectReportService
    {
        return new DefectReportService(DatabaseManager::getInstance());
    }

    private function registry(): DefectRegistryService
    {
        return new DefectRegistryService(DatabaseManager::getInstance());
    }

    private function referencesService(): DefectReferenceService
    {
        return new DefectReferenceService(DatabaseManager::getInstance());
    }
}
