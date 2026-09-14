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
        $uid = trim($uid);
        if ($uid === '') {
            http_response_code(404);
            $this->render_template('profile_page/public', ['profile' => null]);
            return;
        }

        $currentUserId = (int) $request->session('user_id', 0);
        if ($currentUserId > 0) {
            $currentUid = DatabaseManager::getInstance()->fetchValue(
                'SELECT uid FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
                [':id' => $currentUserId]
            );
            if (is_string($currentUid) && hash_equals($currentUid, $uid)) {
                Router::getInstance()->redirect('profile');
                return;
            }
        }

        $profile = DatabaseManager::getInstance()->fetchOne(
            'SELECT uid, username, firstname, lastname, avatar, created_at
             FROM users
             WHERE uid = :uid AND is_active = 1
             LIMIT 1',
            [':uid' => $uid]
        );

        if ($profile === null) {
            http_response_code(404);
            $this->render_template('profile_page/public', ['profile' => null]);
            return;
        }

        $avatarUrl = !empty($profile['avatar'])
            ? '/profile/avatar/' . rawurlencode((string) $profile['uid']) . '?v=' . rawurlencode(substr(hash('sha256', (string) $profile['avatar']), 0, 12))
            : null;

        $this->render_template('profile_page/public', [
            'profile' => (object) $profile,
            'avatar_url' => $avatarUrl,
            // 0.13 deliberately does not treat capability/share links as public-profile publication.
            // Objects will appear here only after an explicit public_profile publication contract exists.
            'public_content' => [],
        ]);
    }
}
