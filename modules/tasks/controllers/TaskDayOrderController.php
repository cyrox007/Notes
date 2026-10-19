<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Modules\Tasks\TaskDayOrderService;
use InvalidArgumentException;

/** HTTP-граница порядка личной дневной очереди Tasks. */
final class TaskDayOrderController extends Controller
{
    public function move(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $versionRaw = trim((string) $request->post('expected_version', ''));
        if ($userId <= 0) {
            $this->reply(['success' => false, 'message' => 'Требуется авторизация'], 401);
            return;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $versionRaw) !== 1) {
            $this->reply(['success' => false, 'message' => 'Неизвестна версия очереди'], 422);
            return;
        }

        try {
            $result = (new TaskDayOrderService(DatabaseManager::getInstance()))->move(
                $userId,
                (string) $request->post('day', ''),
                (string) $request->post('task_uid', ''),
                (string) $request->post('direction', ''),
                (int) $versionRaw,
                (string) $request->post('command_id', '')
            );
            $this->reply($result, ($result['success'] ?? false) ? 200 : 409);
        } catch (InvalidArgumentException $error) {
            $this->reply(['success' => false, 'message' => $error->getMessage()], 422);
        }
    }

    /** @param array<string,mixed> $payload */
    private function reply(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
