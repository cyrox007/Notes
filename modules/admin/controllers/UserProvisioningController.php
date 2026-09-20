<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\UserProvisioningService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class UserProvisioningController extends Controller
{
    public function create(Request $request): void
    {
        try {
            (new UserProvisioningService())->createByAdmin(
                (int) $request->session('user_id', 0),
                [
                    'login' => $request->post('login'),
                    'email' => $request->post('email'),
                    'password' => $request->rawPost('password'),
                    'first_name' => $request->post('first_name'),
                    'patronymic' => $request->post('patronymic'),
                    'surname' => $request->post('surname'),
                    'user_phone' => $request->post('user_phone'),
                ]
            );
            $this->redirectWithFlash($request, true, 'Пользователь создан и получил базовую роль User');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->redirectWithFlash($request, false, $e->getMessage());
        } catch (Throwable $e) {
            error_log('Admin user creation failed: ' . $e->getMessage());
            $this->redirectWithFlash($request, false, 'Не удалось создать пользователя');
        }
    }

    private function redirectWithFlash(Request $request, bool $success, string $message): void
    {
        $request->setSession('admin_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('adminpanel');
    }
}
