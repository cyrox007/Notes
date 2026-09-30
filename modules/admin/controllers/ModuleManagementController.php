<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\UserModel;
use App\Services\ModuleManagementService;
use Core\Controller;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class ModuleManagementController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = $actorId > 0 ? UserModel::select()->where('id', '=', $actorId)->first() : null;
        if (!$user) {
            http_response_code(401);
            return;
        }

        try {
            $modules = (new ModuleManagementService())->snapshot($actorId);
        } catch (DomainException $e) {
            http_response_code($this->status($e, 403));
            return;
        } catch (Throwable $e) {
            error_log('Загрузка управления модулями завершилась ошибкой: ' . $e->getMessage());
            http_response_code(503);
            $modules = [];
        }

        $flash = $request->session('admin_modules_flash');
        $request->unsetSession('admin_modules_flash');

        $this->render_template('@admin/modules', [
            'user' => $user,
            'modules' => $modules,
            'admin_modules_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function setState(Request $request): void
    {
        try {
            $enabledRaw = (string) $request->post('enabled', '');
            if (!in_array($enabledRaw, ['0', '1'], true)) {
                throw new InvalidArgumentException('Некорректное состояние модуля', 422);
            }

            $moduleId = trim((string) $request->post('module_id', ''));
            $enabled = $enabledRaw === '1';

            (new ModuleManagementService())->setEnabled(
                (int) $request->session('user_id', 0),
                $moduleId,
                $enabled,
            );

            $message = $enabled
                ? 'Модуль включён. Изменение полностью применяется со следующего запроса.'
                : 'Модуль отключён. Его маршруты и интеграции больше не загружаются.';
            $this->redirect($request, true, $message);
        } catch (InvalidArgumentException|DomainException $e) {
            $this->redirect($request, false, $e->getMessage(), $this->status($e, 422));
        } catch (Throwable $e) {
            error_log('Изменение состояния модуля завершилось ошибкой: ' . $e->getMessage());
            $this->redirect($request, false, 'Не удалось изменить состояние модуля', 500);
        }
    }

    private function status(Throwable $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function redirect(Request $request, bool $success, string $message, int $status = 200): void
    {
        if ($status >= 400) {
            http_response_code($status);
        }

        $request->setSession('admin_modules_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('admin_modules');
    }
}
