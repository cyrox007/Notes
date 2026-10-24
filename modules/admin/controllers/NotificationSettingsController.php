<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationChannelAdministration.php';
require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationExternalChannelAdministration.php';
require_once dirname(__DIR__, 3) . '/app/services/notifications/TaskReminderScheduleHealth.php';
require_once dirname(__DIR__, 3) . '/app/services/notifications/NotificationWorkerScheduleHealth.php';

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\Notifications\NotificationChannelAdministration;
use App\Services\Notifications\NotificationExternalChannelAdministration;
use App\Services\Notifications\TaskReminderScheduleHealth;
use App\Services\Notifications\NotificationWorkerScheduleHealth;
use App\Services\Notifications\NotificationReceiptAttestation;
use App\Services\Notifications\NotificationLiveAcceptanceReport;
use App\Services\Notifications\NotificationDeliveryReviewQueue;
use Core\DatabaseManager;
use Core\ModuleRuntimeLoader;
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

        $inspection = null;
        $jobRaw = $request->get('inspect_job', '');
        if (is_string($jobRaw) && ctype_digit($jobRaw) && (int) $jobRaw > 0) {
            try {
                $inspection = (new NotificationLiveAcceptanceReport(DatabaseManager::getInstance()))
                    ->forJob((int) $jobRaw);
            } catch (Throwable $error) {
                error_log('Просмотр истории доставки недоступен: ' . $error::class);
                $inspection = ['status' => 'unavailable'];
            }
        }
        $attentionPage = $this->reviewPage($request, 'attention_page');
        $unverifiedPage = $this->reviewPage($request, 'unverified_page');
        $this->render_template('@admin/notifications', [
            'user' => $user,
            'notification_settings' => (new NotificationChannelAdministration())->overview(),
            'external_channels' => (new NotificationExternalChannelAdministration())->overview(),
            'reminder_scheduler' => $this->reminderSchedulerStatus(),
            'notification_worker' => $this->deliveryWorkerStatus(),
            'receipt_inspection' => $inspection,
            'receipt_review' => $this->receiptReview($attentionPage, $unverifiedPage),
        ]);
    }

    /** @return array<string,mixed> */
    private function deliveryWorkerStatus(): array
    {
        try {
            return NotificationWorkerScheduleHealth::evaluate(
                NotificationWorkerScheduleHealth::readLastSuccess(DatabaseManager::getInstance())
            );
        } catch (Throwable $error) {
            // Устаревшая схема не должна скрывать остальные настройки администратора.
            error_log('Диагностика обработчика уведомлений недоступна: ' . $error::class);
            return [
                'state' => 'unavailable',
                'message' => 'Не удалось прочитать время прохода. Проверьте миграции уведомлений.',
                'last_success_utc' => null,
                'minutes_since_success' => null,
            ];
        }
    }

    private function reviewPage(Request $request, string $name): int
    {
        $value = $request->get($name, '1');
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 4) {
            return 1;
        }
        $page = (int) $value;
        return $page >= 1 && $page <= 1000 ? $page : 1;
    }

    /** @return array<string,mixed> */
    private function receiptReview(int $attentionPage, int $unverifiedPage): array
    {
        try {
            return (new NotificationDeliveryReviewQueue(DatabaseManager::getInstance()))
                ->overview($attentionPage, $unverifiedPage);
        } catch (Throwable $error) {
            error_log('Список проверки доставки недоступен: ' . $error::class);
            return [
                'attention' => [],
                'unverified' => [],
                'unavailable' => true,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function reminderSchedulerStatus(): array
    {
        if (!ModuleRuntimeLoader::isBooted()
            || !ModuleRuntimeLoader::getInstance()->capabilities()->has('workspace.tasks')) {
            return [
                'state' => 'disabled',
                'message' => 'Модуль Tasks отключён или недоступен по лицензии.',
            ];
        }

        try {
            $report = TaskReminderScheduleHealth::readReport(DatabaseManager::getInstance());
            return TaskReminderScheduleHealth::evaluate($report);
        } catch (Throwable $error) {
            // Сбой чтения журнала не должен скрывать настройки остальных каналов.
            // В том числе возможна ещё не применённая миграция Tasks.
            error_log('Состояние планировщика Tasks недоступно: ' . $error::class);
            return [
                'state' => 'unavailable',
                'message' => 'Журнал напоминаний недоступен. Проверьте миграции базы данных и подключение.',
            ];
        }
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
            foreach (['channel','url','owner_user_id','chat_id','max_user_id','phone','secret_ref'] as $field) {
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

    /**
     * Администратор вручную подтверждает наличие/отсутствие сообщения
     * у контролируемого адресата; это не ответ внешнего провайдера.
     */
    public function confirmReceipt(Request $request): void
    {
        $this->handle(function () use ($request): array {
            $actorId = (int) $request->session('user_id', 0);
            $user = UserModel::select()->where('id', '=', $actorId)->first();
            $password = (string) $request->rawPost('current_password', '');
            if (!$user || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
                throw new DomainException('Неверный пароль администратора');
            }
            $jobRaw = (string) $request->rawPost('job_id', '');
            $status = (string) $request->rawPost('receipt', '');
            if (!ctype_digit($jobRaw) || (int) $jobRaw < 1
                || !in_array($status, ['received', 'not_received'], true)) {
                throw new InvalidArgumentException('Выберите задание и подтверждённый результат');
            }
            $jobId = (int) $jobRaw;
            (new NotificationReceiptAttestation(DatabaseManager::getInstance()))
                ->record($jobId, $actorId, $status === 'received');
            UserActionLog::emit(
                $actorId, 'notifications.delivery.receipt_attested',
                'core', 'http', 'success', 200,
                ['job_id' => $jobId, 'receipt' => $status]
            );
            return [
                'status' => 'ok',
                'message' => 'Ручное свидетельство сохранено. Оно не является подтверждением внешнего провайдера.',
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
