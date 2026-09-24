<?php

declare(strict_types=1);

namespace App\Middlewares;

require_once dirname(__DIR__, 2) . '/core/SecurityEventLog.php';

use App\Services\TwoFactorPolicyService;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use Core\SecurityEventLog;
use Core\SessionSecurity;

final class EnforceTwoFactorPolicy
{
    public function handle(Request $request): bool
    {
        if ($request->session('auth', false) !== true) {
            return true;
        }

        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0 || !(new TwoFactorPolicyService())->required()) {
            return true;
        }

        $enabled = DatabaseManager::getInstance()->fetchValue(
            'SELECT totp_enabled FROM users '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\' LIMIT 1',
            [':id' => $userId]
        );
        if ((int) $enabled === 1) {
            return true;
        }

        SecurityEventLog::emit(
            'auth.two_factor_policy_session_revoked',
            'warning',
            'auth',
            'user',
            $userId,
            ['reason' => 'global_two_factor_required']
        );
        SessionSecurity::destroyCurrentSession();
        Router::getInstance()->redirect('authpage', 'name');
        return false;
    }
}
