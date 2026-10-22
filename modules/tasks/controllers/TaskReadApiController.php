<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use App\Services\PermissionService;
use Modules\Tasks\TaskApiTokenService;
use Modules\Tasks\TaskApiTrafficService;
use Core\DatabaseManager;
use Core\Request;
use InvalidArgumentException;
use Modules\Tasks\TaskReadApiService;

/** HTTP-контракт чтения Tasks 1.0. */
final class TaskReadApiController extends Controller
{
    public function index(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');

        $db = DatabaseManager::getInstance();
        $authorization = $request->server('HTTP_AUTHORIZATION', '');
        $authorization = is_string($authorization) ? trim($authorization) : '';
        $userId = 0;
        $tokenId = 0;
        $traffic = new TaskApiTrafficService($db);

        if ($authorization !== '') {
            $principal = (new TaskApiTokenService($db))->authenticate($authorization);
            if ($principal === null) {
                $this->reply(['error' => 'invalid_token'], 401);
                return;
            }
            $userId = $principal['user_id'];
            $tokenId = $principal['token_id'];
            $quota = $traffic->consume($tokenId, 'read');
            if (!$quota['allowed']) {
                header('Retry-After: ' . $quota['retry_after']);
                $traffic->record($tokenId, $userId, 'read', 429);
                $this->reply(['error' => 'rate_limited', 'message' => 'Превышен лимит обращений'], 429);
                return;
            }
        } elseif ($request->session('auth')) {
            $userId = (int) $request->session('user_id', 0);
        }

        if ($userId <= 0) {
            $this->reply(['error' => 'unauthorized'], 401);
            return;
        }
        if (!(new PermissionService($db))->hasPermission($userId, 'tasks.use')) {
            if ($tokenId > 0) $traffic->record($tokenId, $userId, 'read', 403);
            $this->reply(['error' => 'forbidden'], 403);
            return;
        }

        $rawLimit = $request->get('limit', '30');
        $rawCursor = $request->get('cursor', null);

        if (!is_scalar($rawLimit) || !is_string($rawLimit)
            || !preg_match('/^[1-9][0-9]{0,2}$/D', $rawLimit)
            || ($rawCursor !== null && !is_string($rawCursor))) {
            if ($tokenId > 0) $traffic->record($tokenId, $userId, 'read', 422);
            $this->reply(['error' => 'invalid_parameter', 'message' => 'Некорректные параметры списка'], 422);
            return;
        }

        try {
            $page = (new TaskReadApiService(DatabaseManager::getInstance()))->listOwned(
                $userId,
                (int) $rawLimit,
                $rawCursor
            );
            if ($tokenId > 0) $traffic->record($tokenId, $userId, 'read', 200);
            $this->reply(['api_version' => 'v1', ...$page], 200);
        } catch (InvalidArgumentException $error) {
            if ($tokenId > 0) $traffic->record($tokenId, $userId, 'read', 422);
            $this->reply(['error' => 'invalid_parameter', 'message' => $error->getMessage()], 422);
        }
    }

    /** @param array<string,mixed> $body */
    private function reply(array $body, int $status): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
