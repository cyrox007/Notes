<?php

declare(strict_types=1);

namespace Modules\Calendar\Controllers;

use Core\Controller;
use Core\DatabaseManager;
use Core\Request;

final class CalendarController extends Controller
{
    public function index(Request $request): void
    {
        $userId = (int) $request->session('user_id', 0);
        $user = DatabaseManager::getInstance()->fetchOne(
            'SELECT id, uid, username, firstname, lastname, avatar, role, is_active FROM users WHERE id = :id LIMIT 1',
            [':id' => $userId]
        );
        if (!is_array($user)) {
            http_response_code(404);
            echo 'Пользователь не найден';
            return;
        }

        $this->render_template('@calendar/index', ['user' => $user]);
    }
}
