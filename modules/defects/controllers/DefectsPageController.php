<?php

declare(strict_types=1);

namespace Modules\Defects\Controllers;

use App\Services\PermissionService;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;

final class DefectsPageController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $permissions = new PermissionService();
        if (!$permissions->hasPermission($userId, 'defects.use')) {
            http_response_code(403);
            echo 'Нет доступа к модулю «Дефекты»';
            return;
        }
        $user = DatabaseManager::getInstance()->fetchOne(
            'SELECT id,uid,username,firstname,lastname,avatar,role,is_active FROM users WHERE id=:id',
            ['id' => $userId]
        );
        if ($user === null) {
            http_response_code(404);
            return;
        }
        $this->render_template('@defects/index', [
            'user' => $user,
            'can_manage_defects' => $permissions->hasPermission($userId, 'defects.manage'),
            'csrf_input' => $this->getCSRFInputTag(),
        ]);
    }
}
