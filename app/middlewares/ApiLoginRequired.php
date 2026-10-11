<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\UserModel;
use Core\Request;

/**
 * JSON-вариант проверки активной пользовательской сессии.
 *
 * Обычный LoginRequared сохраняет браузерный контракт с перенаправлением на
 * форму входа. API вместо 302 возвращает 401 и не раскрывает состояние аккаунта.
 */
final class ApiLoginRequired
{
    public function handle(Request $request): bool
    {
        $userId = (int) $request->session('user_id', 0);
        if (!$request->session('auth') || $userId <= 0) {
            return $this->reject();
        }

        $user = UserModel::select('id', 'is_active', 'account_status')
            ->where('id', '=', $userId)
            ->first();

        if (
            !$user
            || (int) $user->is_active !== 1
            || (string) $user->account_status !== 'active'
        ) {
            $request->unsetSession('auth');
            $request->unsetSession('user_id');
            $request->unsetSession('user_uid');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            return $this->reject();
        }

        return true;
    }

    private function reject(): bool
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'status' => 'error',
            'code' => 'authentication_required',
            'message' => 'Требуется активная пользовательская сессия.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return false;
    }
}
