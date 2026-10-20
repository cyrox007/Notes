<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationChannelAdministration.php';
require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationExternalChannelAdministration.php';

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\Notifications\NotificationChannelAdministration;
use App\Services\Notifications\NotificationExternalChannelAdministration;
use Core\Controller;
use Core\Request;
use Core\UserActionLog;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class NotificationSettingsController extends Controller
{
    public function index(Request $request): void
    {
        $actorId = (int) $request->session('user_id', 0);
        $user = UserModel::select()->where('id', '=', $actorId)->first();
        if (!$user) {
            http_response_code(401);
            return;
        }

        $this->render_template('@admin/notifications', [
            'user' => $user,
            'notification_settings' => (new NotificationChannelAdministration())->overview(),
            'external_channels' => (new NotificationExternalChannelAdministration())->overview(),
        ]);
    }

    public function saveSmtp(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $actorId = (int) $request->session('user_id', 0);
            $user = UserModel::select()->where('id', '=', $actorId)->first();
            $password = (string) $request->rawPost('current_password', '');
            if (!$user || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
                throw new DomainException('Неверный текущий пароль администратора');
            }

            $input = [];
            foreach (['host','port','encryption','username','from_email','from_name','secret_ref'] as $field) {
                $input[$field] = $request->rawPost($field, '');
            }
            $input['enabled'] = $request->rawPost('enabled', '0') === '1';
            (new NotificationChannelAdministration())->saveSmtp($input);

            UserActionLog::emit(
                $actorId, 'notifications.channel.smtp.updated', 'core', 'http',
                'success', 200, ['enabled' => $input['enabled']]
            );
            return ['status' => 'ok', 'message' => 'Настройки SMTP сохранены без сохранения пароля в БД'];
        });
    }

    public function saveExternal(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $actorId = (int) $request->session('user_id', 0);
            $user = UserModel::select()->where('id', '=', $actorId)->first();
            $password = (string) $request->rawPost('current_password', '');
            if (!$user || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
                throw new DomainException('Неверный пароль администратора');
            }
            $input = [];
            foreach (['channel','url','owner_user_id','chat_id','max_user_id','secret_ref'] as $field) {
                $input[$field] = $request->rawPost($field, '');
            }
            $input['enabled'] = $request->rawPost('enabled', '0') === '1';
            (new NotificationExternalChannelAdministration())->save($input);
            UserActionLog::emit(
                $actorId, 'notifications.channel.external.updated',
                'core', 'http', 'success', 200,
                ['channel' => $input['channel'], 'enabled' => $input['enabled']]
            );
            return ['status' => 'ok', 'message' => 'Настройки внешнего канала сохранены'];
        });
    }

    public function sendTest(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $actorId = (int) $request->session('user_id', 0);
            $channel = (string) $request->rawPost('channel', 'email');
            $result = (new NotificationChannelAdministration())->testChannel($actorId, $channel);
            UserActionLog::emit(
                $actorId, 'notifications.channel.smtp.test', 'core', 'http',
                'success', 200,
                ['channel' => $channel, 'job_id' => $result['job_id'], 'state' => $result['state']]
            );
            return [
                'status' => 'ok',
                'message' => $result['state'] === 'sent'
                    ? 'Внешний сервис принял уведомление. Проверьте его фактическое получение.'
                    : 'Пробное уведомление создано. Доставка ещё не подтверждена: смотрите состояние очереди.',
                ...$result,
            ];
        });
    }

    /** @param callable():array<string,mixed> $operation */
    private function handle(callable $operation): void
    {
        try {
            $this->reply($operation(), 200);
        } catch (DomainException|InvalidArgumentException $error) {
            $this->reply(['status' => 'error', 'message' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            error_log('Администрирование уведомлений: ' . $error::class);
            $this->reply([
                'status' => 'error',
                'message' => 'Не удалось выполнить операцию. Проверьте журнал попыток и настройки сервера.',
            ], 500);
        }
    }

    /** @param array<string,mixed> $body */
    private function reply(array $body, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->responseJson($body);
    }
}
