<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\AdminBackupService;
use App\Services\PermissionService;
use Core\Controller;
use Core\Request;
use Core\Router;
use Core\SecurityEventLog;
use DomainException;
use Throwable;

/**
 * Серверная граница резервного копирования: только суперпользователь,
 * пароль текущей сессии, CSRF из маршрутов и отсутствие произвольных путей.
 */
final class AdminBackupController extends Controller
{
    public function index(Request $request): void
    {
        $actor = (int) $request->session('user_id', 0);
        if (!$this->authorized($actor)) {
            http_response_code(403);
            echo 'Доступ к резервным копиям разрешён только суперпользователю';
            return;
        }

        $user = UserModel::select()->where('id', '=', $actor)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $flash = $request->session('admin_backup_flash');
        $request->unsetSession('admin_backup_flash');
        $backups = [];
        $error = null;
        try {
            $backups = (new AdminBackupService())->list();
        } catch (Throwable) {
            $error = 'Приватное хранилище недоступно; создание и восстановление заблокированы';
        }

        $this->render_template('@admin/backups', [
            'user' => $user,
            'backups' => $backups,
            'backup_error' => $error,
            'backup_flash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function create(Request $request): void
    {
        $actor = $this->requireCurrentPassword($request);
        if ($actor === null) return;

        try {
            $created = (new AdminBackupService())->create();
            $this->audit($actor, 'backup.created', 'info', $created['id']);
            $this->flash($request, true, 'Резервная копия создана и проверена: ' . $created['id']);
        } catch (Throwable $error) {
            error_log('Не удалось создать операторскую копию: ' . get_debug_type($error));
            $this->audit($actor, 'backup.creation_failed', 'warning');
            $this->flash($request, false, 'Создание резервной копии не завершено. Проверьте место на диске и журнал сервера.');
        }
    }

    public function verify(Request $request): void
    {
        $actor = (int) $request->session('user_id', 0);
        if (!$this->authorized($actor)) {
            http_response_code(403);
            return;
        }
        try {
            $id = (string) $request->post('backup_id', '');
            (new AdminBackupService())->verify($id);
            $this->audit($actor, 'backup.verified', 'info', $id);
            $this->flash($request, true, 'Контроль целостности копии пройден: ' . $id);
        } catch (Throwable) {
            $this->audit($actor, 'backup.verification_failed', 'warning');
            $this->flash($request, false, 'Резервная копия повреждена либо недоступна; восстановление запрещено');
        }
    }

    public function restore(Request $request): void
    {
        $actor = $this->requireCurrentPassword($request);
        if ($actor === null) return;

        if (!hash_equals('ВОССТАНОВИТЬ', (string) $request->post('confirmation', ''))) {
            $this->flash($request, false, 'Для восстановления введите слово ВОССТАНОВИТЬ');
            return;
        }
        try {
            $id = (string) $request->post('backup_id', '');
            $result = (new AdminBackupService())->restore($id);
            $this->audit($actor, 'backup.restored', 'critical', $result['id']);
            $this->flash($request, true, 'Данные восстановлены. Защитная копия перед операцией: ' . $result['safety_id']);
        } catch (Throwable $error) {
            $this->audit($actor, 'backup.restore_failed', 'critical');
            error_log('Ошибка восстановления операторской копии: ' . get_debug_type($error));
            $this->flash(
                $request,
                false,
                'Восстановление не завершено. Не повторяйте операцию до проверки журнала и режима обслуживания.'
            );
        }
    }

    private function authorized(int $actor): bool
    {
        return $actor > 0 && (new PermissionService())->hasRole($actor, 'superadmin')
            && (new PermissionService())->hasPermission($actor, 'admin.settings.manage');
    }

    private function requireCurrentPassword(Request $request): ?int
    {
        $actor = (int) $request->session('user_id', 0);
        if (!$this->authorized($actor)) {
            http_response_code(403);
            return null;
        }
        $user = UserModel::select()->where('id', '=', $actor)->first();
        $password = (string) $request->post('current_password', '');
        if (!$user || $password === ''
            || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
            $this->audit($actor, 'backup.reauthentication_failed', 'warning');
            $this->flash($request, false, 'Неверный текущий пароль');
            return null;
        }
        return $actor;
    }

    private function audit(int $actor, string $event, string $severity, string $id = ''): void
    {
        SecurityEventLog::emit(
            $event, $severity, 'admin', 'user', $actor,
            $id !== '' ? ['backup_id' => $id] : []
        );
    }

    private function flash(Request $request, bool $success, string $message): void
    {
        $request->setSession('admin_backup_flash', [
            'type' => $success ? 'success' : 'error',
            'message' => $message,
        ]);
        Router::getInstance()->redirect('admin_backups');
    }
}
