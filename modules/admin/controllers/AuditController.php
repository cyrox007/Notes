<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use Core\Controller;
use Core\Request;
use Core\UserActionLog;
use InvalidArgumentException;
use Throwable;

final class AuditController extends Controller
{
    private const LIMITS = [20, 50, 100];

    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = $actorId > 0 ? UserModel::select()->where('id', '=', $actorId)->first() : null;
        if (!$user) {
            http_response_code(401);
            return;
        }

        $page = max(1, (int) $request->get('page', 1));
        $limit = (int) $request->get('limit', 50);
        if (!in_array($limit, self::LIMITS, true)) {
            $limit = 50;
        }
        $filters = [
            'q' => trim((string) $request->get('q', '')),
            'actor_id' => max(0, (int) $request->get('actor_id', 0)),
            'module_id' => trim((string) $request->get('module_id', '')),
            'action' => trim((string) $request->get('action', '')),
            'outcome' => trim((string) $request->get('outcome', '')),
            'from' => trim((string) $request->get('from', '')),
            'to' => trim((string) $request->get('to', '')),
        ];

        try {
            $result = (new UserActionLog())->search($filters, $limit, ($page - 1) * $limit);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $result = ['items' => [], 'total' => 0];
            $filters['error'] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Audit log read failed: ' . $e->getMessage());
            http_response_code(500);
            $result = ['items' => [], 'total' => 0];
            $filters['error'] = 'Не удалось прочитать журнал действий';
        }

        $total = (int) $result['total'];
        $totalPages = max(1, (int) ceil($total / $limit));
        if ($page > $totalPages && $total > 0) {
            $page = $totalPages;
        }

        $this->render_template('@admin/audit', [
            'user' => $user,
            'auditRows' => $result['items'],
            'auditFilters' => $filters,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }
}
