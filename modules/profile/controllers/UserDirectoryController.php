<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

final class UserDirectoryController extends Controller
{
    public function index(Request $request): void
    {
        $currentUserId = (int) $request->session('user_id', 0);
        $user = $currentUserId > 0
            ? UserModel::select()->where('id', '=', $currentUserId)->first()
            : null;
        if (!$user) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        $query = trim((string) $request->get('q', ''));
        if (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }
        $page = max(1, (int) $request->get('page', 1));
        $perPage = 40;
        $offset = ($page - 1) * $perPage;

        $params = [':current_user_id' => $currentUserId];
        $where = "is_active = 1 AND account_status = 'active' AND id <> :current_user_id";
        if ($query !== '') {
            $where .= ' AND (username LIKE :username_query OR firstname LIKE :firstname_query '
                . 'OR lastname LIKE :lastname_query '
                . "OR CONCAT(firstname, ' ', lastname) LIKE :fullname_query)";
            $pattern = '%' . $query . '%';
            $params[':username_query'] = $pattern;
            $params[':firstname_query'] = $pattern;
            $params[':lastname_query'] = $pattern;
            $params[':fullname_query'] = $pattern;
        }

        $db = DatabaseManager::getInstance();
        $total = (int) $db->fetchValue('SELECT COUNT(*) FROM users WHERE ' . $where, $params);
        $rows = $db->fetchAll(
            'SELECT uid,username,firstname,lastname,avatar FROM users WHERE ' . $where
            . ' ORDER BY firstname ASC,lastname ASC,username ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        $this->render_template('@profile/users', [
            'user' => $user,
            'people' => $rows,
            'query' => $query,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
        ]);
    }
}
