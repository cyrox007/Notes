<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Controllers;

use App\Services\PermissionService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\UserActionLog;
use DomainException;
use InvalidArgumentException;
use Modules\WorkPermits\Services\WorkPermitDraftService;
use Throwable;

/**
 * Тонкая HTTP-граница: доступ и CSRF контролирует ядро,
 * SQL и предметные ограничения принадлежат сервису модуля.
 */
final class WorkPermitController extends Controller
{
    public function index(Request $request): void
    {
        $id = (int) $request->session('user_id', 0);
        if (!(new PermissionService())->hasPermission($id, 'work_permits.use')) {
            http_response_code(403);
            echo 'Нет доступа к модулю «Наряды и допуски»';
            return;
        }
        $user = DatabaseManager::getInstance()->fetchOne(
            'SELECT id,uid,username,firstname,lastname,avatar,role,is_active FROM users WHERE id=:id',
            [':id' => $id]
        );
        if ($user === null) {
            http_response_code(404);
            return;
        }
        $this->render_template('@work-permits/index', [
            'user' => $user,
            'csrf_input' => $this->getCSRFInputTag(),
            'can_manage_references' => (new PermissionService())->hasPermission($id, 'work_permits.manage'),
        ]);
    }

    public function list(Request $request): void
    {
        $this->handle($request, function (int $actor) use ($request): array {
            return $this->drafts()->listMine(
                $actor,
                $this->number($request->get('limit', 20), 'limit'),
                $this->number($request->get('before_id', 0), 'before_id')
            );
        });
    }

    public function show(Request $request, int $id): void
    {
        $this->handle($request, function (int $actor) use ($id): array {
            $document = $this->drafts()->detail($id, $actor);
            if ($document === null) {
                throw new DomainException('Документ не найден или недоступен', 404);
            }
            return ['document' => $document];
        });
    }

    public function create(Request $request): void
    {
        $this->handle($request, function (int $actor) use ($request): array {
            $payload = $this->json($request);
            $created = $this->drafts()->create($actor, $payload);
            if (!$created['replayed']) {
                UserActionLog::emit($actor, 'work_permits.draft_created', 'work-permits', 'http',
                    'success', 201, ['document_id' => $created['id'], 'kind' => $payload['kind']]);
            }
            return ['document' => $created];
        }, 201);
    }

    /** Ограниченный список для формы; архив виден только управляющему. */
    public function references(Request $request, string $kind): void
    {
        $this->handle($request, function (int $actor) use ($request, $kind): array {
            $archived = (string) $request->get('archived', '0') === '1';
            if ($archived) {
                (new PermissionService())->requirePermission($actor, 'work_permits.manage');
            }
            return $this->referencesService()->listing(
                $kind,
                $this->number($request->get('organization_id', 0), 'organization_id'),
                $archived,
                $this->number($request->get('limit', 50), 'limit'),
                $this->number($request->get('before_id', 0), 'before_id')
            );
        });
    }

    public function createReference(Request $request, string $kind): void
    {
        $this->handle($request, function (int $actor) use ($request, $kind): array {
            (new PermissionService())->requirePermission($actor, 'work_permits.manage');
            $created = $this->referencesService()->create($kind, $this->json($request), $actor);
            UserActionLog::emit($actor, 'work_permits.reference_created', 'work-permits',
                'http', 'success', 201, ['kind' => $kind, 'id' => $created['id']]);
            return ['reference' => $created];
        }, 201);
    }

    public function changeReference(Request $request, string $kind, int $id): void
    {
        $this->handle($request, function (int $actor) use ($request, $kind, $id): array {
            (new PermissionService())->requirePermission($actor, 'work_permits.manage');
            $payload = $this->json($request);
            $action = $payload['action'] ?? null;
            $name = $action === 'rename' ? ($payload['name'] ?? null) : null;
            if ($action === 'rename' && !is_string($name)) {
                throw new InvalidArgumentException('Название должно быть строкой');
            }
            $archived = match ($action) {
                'archive' => true,
                'restore' => false,
                'rename' => null,
                default => throw new InvalidArgumentException('Неизвестное действие со справочником'),
            };
            $updated = $this->referencesService()->change(
                $kind, $id, $this->number($payload['version'] ?? null, 'version'),
                $actor, $name, $archived
            );
            UserActionLog::emit($actor, 'work_permits.reference_changed', 'work-permits',
                'http', 'success', 200, ['kind' => $kind, 'id' => $id, 'action' => $action]);
            return ['reference' => $updated];
        });
    }

    /** @return array<string,mixed> */
    private function json(Request $request): array
    {
        $payload = $request->rawJson();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('Ожидается JSON-объект');
        }
        return $payload;
    }

    private function referencesService(): \Modules\WorkPermits\Services\WorkPermitReferenceService
    {
        return new \Modules\WorkPermits\Services\WorkPermitReferenceService(DatabaseManager::getInstance());
    }

    private function drafts(): WorkPermitDraftService
    {
        return new WorkPermitDraftService(DatabaseManager::getInstance());
    }

    private function number(mixed $value, string $name): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^\d{1,15}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Некорректное число: ' . $name);
        }
        return (int) $value;
    }

    /** @param callable(int):array<string,mixed> $operation */
    private function handle(Request $request, callable $operation, int $status = 200): void
    {
        try {
            $actor = (int) $request->session('user_id', 0);
            (new PermissionService())->requirePermission($actor, 'work_permits.use');
            $this->respond(['status' => 'ok'] + $operation($actor), $status);
        } catch (DomainException $error) {
            $code = in_array($error->getCode(), [401, 403, 404, 409], true) ? $error->getCode() : 409;
            $this->respond(['status' => 'error', 'message' => $error->getMessage()], $code);
        } catch (InvalidArgumentException $error) {
            $this->respond(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Ошибка журнала нарядов: ' . $error::class);
            $this->respond(['status' => 'error', 'message' => 'Не удалось выполнить операцию с документом.'], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $code): void
    {
        http_response_code($code);
        header('Cache-Control: private, no-store');
        header('Content-Type: application/json; charset=utf-8');
        $this->responseJson($payload);
    }
}
