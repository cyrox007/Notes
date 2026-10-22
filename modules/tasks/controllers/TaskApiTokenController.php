<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PermissionService;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use InvalidArgumentException;
use Modules\Tasks\TaskApiTokenService;
use Modules\Tasks\TaskApiTrafficService;

/**
 * Управление личными API-ключами возможно только через сессию и штатный CSRF.
 * Открытый секрет возвращается исключительно в ответе на создание.
 */
final class TaskApiTokenController extends Controller
{
    /** Страница управления собственными ключами доступа. */
    public function page(Request $request): void
    {
        $userId = $this->owner($request);
        if ($userId === null) {
            return;
        }
        $user = UserModel::select()->where('id', '=', $userId)->first();
        if (!$user) {
            $this->respond(['error' => 'unauthorized'], 401);
            return;
        }
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $this->render_template('@tasks/api-tokens', ['user' => $user]);
    }

    public function index(Request $request): void
    {
        $userId = $this->owner($request);
        if ($userId === null) {
            return;
        }
        $this->respond(['items' => (new TaskApiTokenService(DatabaseManager::getInstance()))->listOwned($userId)]);
    }

    public function history(Request $request): void
    {
        $userId = $this->owner($request);
        if ($userId === null) {
            return;
        }
        $items = (new TaskApiTrafficService(DatabaseManager::getInstance()))->recentForOwner($userId);
        $this->respond(['items' => $items]);
    }

    public function create(Request $request): void
    {
        $userId = $this->owner($request);
        if ($userId === null) {
            return;
        }
        $name = $request->rawPost('name', '');
        $days = $request->rawPost('days', '30');
        $scope = $request->rawPost('scope', TaskApiTokenService::SCOPE_READ);
        if (!is_string($name) || !is_string($scope) || !is_string($days) || preg_match('/^[1-9][0-9]{0,1}$/D', $days) !== 1) {
            $this->respond(['error' => 'invalid_parameter', 'message' => 'Проверьте имя и срок ключа'], 422);
            return;
        }

        try {
            $created = (new TaskApiTokenService(DatabaseManager::getInstance()))
                ->issue($userId, $name, (int) $days, $scope);
            $this->respond(['created' => $created, 'message' => 'Сохраните ключ: он больше не отображается'], 201);
        } catch (InvalidArgumentException $exception) {
            $this->respond(['error' => 'invalid_parameter', 'message' => $exception->getMessage()], 422);
        }
    }

    public function revoke(Request $request, int $tokenId): void
    {
        $userId = $this->owner($request);
        if ($userId === null) {
            return;
        }
        $revoked = (new TaskApiTokenService(DatabaseManager::getInstance()))
            ->revoke($userId, $tokenId);
        $this->respond($revoked ? ['revoked' => true] : ['error' => 'not_found'], $revoked ? 200 : 404);
    }

    private function owner(Request $request): ?int
    {
        $userId = (int) $request->session('user_id', 0);
        if (!$request->session('auth') || $userId <= 0) {
            $this->respond(['error' => 'unauthorized'], 401);
            return null;
        }
        if (!(new PermissionService())->hasPermission($userId, 'tasks.use')) {
            $this->respond(['error' => 'forbidden'], 403);
            return null;
        }
        return $userId;
    }

    /** @param array<string,mixed> $payload */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
