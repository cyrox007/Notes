<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;

final class PublicProfileController extends Controller
{
    public function view(Request $request, string $uid): void
    {
        $db = DatabaseManager::getInstance();
        $currentUserId = (int) $request->session('user_id', 0);
        $layoutUser = $db->fetchOne(
            'SELECT id,uid,username,email,firstname,patronymic,lastname,phone,avatar,property,role,is_active
             FROM users
             WHERE id = :id AND is_active = 1
             LIMIT 1',
            [':id' => $currentUserId]
        );

        // LoginRequared normally guarantees this, but keep the layout fail-closed if
        // session/user state changes between middleware and controller execution.
        if ($layoutUser === null) {
            Router::getInstance()->redirect('authpage');
            return;
        }

        $uid = trim($uid);
        if ($uid === '') {
            http_response_code(404);
            $this->render_template('profile_page/public', [
                'user' => $layoutUser,
                'profile' => null,
            ]);
            return;
        }

        if (hash_equals((string) $layoutUser['uid'], $uid)) {
            Router::getInstance()->redirect('profile');
            return;
        }

        $profile = $db->fetchOne(
            'SELECT uid, username, firstname, lastname, avatar, created_at
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $uid]
        );

        if ($profile === null) {
            http_response_code(404);
            $this->render_template('profile_page/public', [
                'user' => $layoutUser,
                'profile' => null,
            ]);
            return;
        }

        $avatarUrl = !empty($profile['avatar'])
            ? '/profile/avatar/' . rawurlencode((string) $profile['uid']) . '?v=' . rawurlencode(substr(hash('sha256', (string) $profile['avatar']), 0, 12))
            : null;

        $this->render_template('profile_page/public', [
            // `$user` belongs to the authenticated viewer and is used by shared layout/sidebar.
            // `$profile` is the deliberately narrow read-only subject shown in page content.
            'user' => $layoutUser,
            'profile' => (object) $profile,
            'avatar_url' => $avatarUrl,
            // 0.13 deliberately does not treat capability/share links as public-profile publication.
            // Objects will appear here only after an explicit public_profile publication contract exists.
            'public_content' => [],
        ]);
    }
}
