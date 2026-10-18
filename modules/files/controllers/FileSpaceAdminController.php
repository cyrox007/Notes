<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceAdminService;
use App\Services\Files\FileSpaceFolderService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use DomainException;
use InvalidArgumentException;
use Throwable;

/** Административная панель групп, правил и хранения общего пространства. */
final class FileSpaceAdminController extends Controller
{
    public function index(Request $request): void
    {
        try {
            $actorId = $this->actor($request);
            [$admin, $access, $folders] = $this->services();
            $snapshot = $admin->snapshot($actorId);
            $folderId = $this->optionalId($request->get('folder', ''));
            $catalog = $folders->browse($actorId, $folderId, (string) $request->get('cursor', ''));
            $rules = $folders->rules($actorId, $folderId);
            $userId = $this->optionalId($request->get('user', ''));
            $decisions = $userId === null ? [] : $access->explain($actorId, $userId, $folderId);
            $actor = UserModel::select()->where('id', '=', $actorId)->first();
            $this->render_template('@files/admin-spaces', [
                'user' => $actor,
                'admin_state' => $snapshot,
                'catalog' => $catalog,
                'acl_rules' => $rules,
                'diagnostic_user_id' => $userId,
                'diagnostic_decisions' => $decisions,
            ]);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->errorText($error->getMessage(), $this->status($error, 403));
        } catch (Throwable $error) {
            error_log('Не удалось загрузить администрирование общих файлов: ' . $error->getMessage());
            $this->errorText('Не удалось открыть настройки общего хранилища', 500);
        }
    }

    public function createGroup(Request $request): void
    {
        $this->execute($request, static fn (FileSpaceAdminService $service, int $actor): array =>
            $service->createGroup(
                $actor, (string) $request->post('name', ''),
                (string) $request->post('command_id', '')
            )
        );
    }

    public function groupMember(Request $request): void
    {
        $this->execute($request, fn (FileSpaceAdminService $service, int $actor): array =>
            $service->changeGroup(
                $actor,
                $this->positiveInt($request->post('group_id', '')),
                (string) $request->post('operation', ''),
                $this->positiveInt($request->post('member_id', '')),
                $this->positiveInt($request->post('expected_version', '')),
                (string) $request->post('command_id', '')
            )
        );
    }

    public function deleteGroup(Request $request): void
    {
        $this->execute($request, fn (FileSpaceAdminService $service, int $actor): array =>
            $service->changeGroup(
                $actor, $this->positiveInt($request->post('group_id', '')),
                'delete', 0, $this->positiveInt($request->post('expected_version', '')),
                (string) $request->post('command_id', '')
            )
        );
    }

    public function retention(Request $request): void
    {
        $this->execute($request, fn (FileSpaceAdminService $service, int $actor): array =>
            $service->setRetention(
                $actor,
                $this->positiveInt($request->post('days', '')),
                $this->positiveInt($request->post('expected_version', '')),
                (string) $request->post('command_id', '')
            )
        );
    }

    public function setAcl(Request $request): void
    {
        try {
            $actor = $this->actor($request);
            [$admin, , $folders] = $this->services();
            $admin->requireAdmin($actor);
            $inherit = (string) $request->post('inherit_acl', '');
            if (!in_array($inherit, ['', '0', '1'], true)) {
                throw new InvalidArgumentException('Некорректный режим наследования');
            }
            $result = $folders->setRule(
                $actor,
                $this->optionalId($request->post('folder_id', '')),
                (string) $request->post('subject_type', ''),
                $this->nonNegativeInt($request->post('subject_id', '0')),
                (string) $request->post('permission', ''),
                (string) $request->post('effect', ''),
                $this->positiveInt($request->post('expected_version', '')),
                (string) $request->post('command_id', ''),
                $inherit === '' ? null : $inherit === '1'
            );
            $this->reply($result);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()],
                $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Ошибка изменения административного ACL: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось изменить правила'], 500);
        }
    }

    public function setAclBulk(Request $request): void
    {
        try {
            $actor = $this->actor($request);
            [$admin, , $folders] = $this->services();
            $admin->requireAdmin($actor);
            $rawIds = $request->post('subject_ids', []);
            $rawPermissions = $request->post('permissions', []);
            if (!is_array($rawIds) || !is_array($rawPermissions)) {
                throw new InvalidArgumentException('Выберите получателей и разрешения');
            }
            $ids = [];
            foreach ($rawIds as $rawId) {
                $ids[] = $this->positiveInt($rawId);
            }
            $permissions = [];
            foreach ($rawPermissions as $permission) {
                if (!is_string($permission)) {
                    throw new InvalidArgumentException('Некорректное файловое право');
                }
                $permissions[] = $permission;
            }
            $result = $folders->setRulesBulk(
                $actor,
                $this->optionalId($request->post('folder_id', '')),
                (string) $request->post('subject_type', ''),
                $ids,
                $permissions,
                (string) $request->post('effect', ''),
                $this->positiveInt($request->post('expected_version', '')),
                (string) $request->post('command_id', '')
            );
            $this->reply($result);
        } catch (InvalidArgumentException|DomainException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()],
                $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Ошибка массового назначения прав Files: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось назначить права'], 500);
        }
    }

    /** @param \Closure(FileSpaceAdminService,int):array<string,mixed> $action */
    private function execute(Request $request, \Closure $action): void
    {
        try {
            [$admin] = $this->services();
            $actor = $this->actor($request);
            $admin->requireAdmin($actor);
            $this->reply($action($admin, $actor));
        } catch (DomainException|InvalidArgumentException $error) {
            $this->json(['success' => false, 'message' => $error->getMessage()],
                $this->status($error, 422));
        } catch (Throwable $error) {
            error_log('Ошибка управления Filespaces: ' . $error->getMessage());
            $this->json(['success' => false, 'message' => 'Не удалось сохранить настройки'], 500);
        }
    }

    /** @return array{FileSpaceAdminService,FileSpaceAccessService,FileSpaceFolderService} */
    private function services(): array
    {
        $pdo = DatabaseManager::getInstance()->getPdo();
        $access = new FileSpaceAccessService($pdo);
        return [new FileSpaceAdminService($pdo, $access), $access, new FileSpaceFolderService($pdo, $access)];
    }

    private function actor(Request $request): int
    {
        $id = (int) $request->session('user_id', 0);
        if ($id < 1) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $id;
    }

    private function positiveInt(mixed $raw): int
    {
        $raw = trim((string) $raw);
        if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            throw new InvalidArgumentException('Необходим положительный идентификатор или номер версии');
        }
        return (int) $raw;
    }

    private function nonNegativeInt(mixed $raw): int
    {
        $raw = trim((string) $raw);
        if (preg_match('/^(0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор');
        }
        return (int) $raw;
    }

    private function optionalId(mixed $raw): ?int
    {
        $raw = trim((string) $raw);
        return $raw === '' ? null : $this->positiveInt($raw);
    }

    /** @param array<string,mixed> $result */
    private function reply(array $result): void
    {
        $this->json($result, ($result['error'] ?? '') === 'version_conflict' ? 409 : 200);
    }

    private function status(Throwable $error, int $fallback): int
    {
        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function errorText(string $message, int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    /** @param array<string,mixed> $result */
    private function json(array $result, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
