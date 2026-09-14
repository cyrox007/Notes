<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProfilePublicationService;
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
            'SELECT id, uid, username, firstname, lastname, avatar, created_at
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

        $profileId = (int) $profile['id'];
        unset($profile['id']);
        $avatarUrl = !empty($profile['avatar'])
            ? '/profile/avatar/' . rawurlencode((string) $profile['uid']) . '?v=' . rawurlencode(substr(hash('sha256', (string) $profile['avatar']), 0, 12))
            : null;
        $publicContent = (new ProfilePublicationService($db))->publicItems($profileId);
        $publicTotal = count($publicContent['notes']) + count($publicContent['tasks']) + count($publicContent['files']);

        $this->render_template('profile_page/public', [
            '$user' => $layoutUser,
        ]);
    }
}
