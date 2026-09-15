<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\RegistrationPolicyService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class RegistrationSettingsController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        try {
            $service = new RegistrationPolicyService();
            $mode = $service->mode();
            $invites = $service->listInvites($actorId);
        } catch (Throwable $e) {
            error_log('Registration settings load failed: ' . $e->getMessage());
            http_response_code(500);
            return;
        }

        $flash = $request->session('registration_flash');
        $request->unsetSession('registration_flash');
        $this->render_template('admin-page/registration', [
            'user' => $user,
            'registration_mode' => $mode,
            'registration_invites' => $invites,
            'registration_flash' => is_array($flash) ? $flash : null,
            'legacy_invite_configured' => trim((string) (getenv('REGISTRATION_INVITE_CODE') ?: '')) !== '',
        ]);
    }

    public function saveMode(Request $request): void
    {
        try {
            (new RegistrationPolicyService())->saveMode(
                (int) $request->session('user_id', 0),
                (string) $request->post('registration_mode', '')
            );
            $this->redirectWithFlash($request, true, 'Режим регистрации обновлён');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->redirectWithFlash($request, false, $e->getMessage());
        } catch (Throwable $e) {
            error_log('Registration mode update failed: ' . $e->getMessage());
            $this->redirectWithFlash($request, false, 'Не удалось изменить режим регистрации');
        }
    }

    public function createInvite(Request $request): void
    {
        try {
            $invite = (new RegistrationPolicyService())->createInvite(
                (int) $request->session('user_id', 0),
                (string) $request->post('label', ''),
                (int) $request->post('max_uses', 1),
                trim((string) $request->post('expires_at', '')) ?: null
            );
            $this->redirectWithFlash(
                $request,
                true,
                'Инвайт создан. Код показывается только сейчас — сохраните его.',
                ['invite_code' => $invite['code']]
            );
        } catch (InvalidArgumentException|DomainException $e) {
            $this->redirectWithFlash($request, false, $e->getMessage());
        } catch (Throwable $e) {
            error_log('Registration invite creation failed: ' . $e->getMessage());
            $this->redirectWithFlash($request, false, 'Не удалось создать инвайт');
        }
    }

    public function revokeInvite(Request $request): void
    {
        try {
            (new RegistrationPolicyService())->revokeInvite(
                (int) $request->session('user_id', 0),
                (string) $request->post('invite_id', '')
            );
            $this->redirectWithFlash($request, true, 'Инвайт отозван');
        } catch (InvalidArgumentException|DomainException $e) {
            $this->redirectWithFlash($request, false, $e->getMessage());
        } catch (Throwable $e) {
            error_log('Registration invite revoke failed: ' . $e->getMessage());
            $this->redirectWithFlash($request, false, 'Не удалось отозвать инвайт');
        }
    }

    /** @param array<string,mixed> $extra */
    private function redirectWithFlash(
        Request $request,
        bool $success,
        string $message,
        array $extra = []
    ): void {
        $request->setSession('registration_flash', array_merge([
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ], $extra));
        Router::getInstance()->redirect('admin_registration');
    }
}
