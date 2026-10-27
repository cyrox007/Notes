<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationDeliveryResult.php';

use Closure;
use Throwable;

/** Local Windows COM integration; no HTTP endpoint or shell commands. */
final class HeadwindSmsTransport
{
    public function __construct(private ?Closure $factory = null)
    {
    }

    /** Checks PHP prerequisites without starting the driver or sending SMS. */
    public static function environment(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['available' => false, 'message' => 'Headwind GSM требует Windows на сервере Workspace.'];
        }
        if (!class_exists('COM')) {
            return ['available' => false, 'message' => 'В PHP не включено расширение com_dotnet. Включите его в PHP веб-сервера и обработчика очереди.'];
        }
        return ['available' => true, 'message' => 'PHP COM доступен (' . (PHP_INT_SIZE * 8)
            . ' бит). Headwind GSM должен быть установлен и доступен учётной записи обработчика очереди.'];
    }

    public function send(string $phone, string $text): NotificationDeliveryResult
    {
        if ($this->factory === null && !self::environment()['available']) {
            return NotificationDeliveryResult::failed('sms_headwind_unavailable', self::environment()['message'], false);
        }
        $create = $this->factory ?? static fn (string $id): object => new \COM($id, null, CP_UTF8);
        try {
            $driver = $create('HeadwindGSM.SMSDriver');
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('sms_headwind_driver_unavailable',
                'Не удалось открыть Headwind GSM. Проверьте регистрацию COM, разрядность PHP и права учётной записи.', false);
        }
        try {
            $status = strtolower(trim((string) $driver->StatusStr));
            if ($status === 'disconnected') {
                $driver->Connect();
                $status = strtolower(trim((string) $driver->StatusStr));
            }
            if ($status !== 'ready') {
                return NotificationDeliveryResult::failed('sms_headwind_not_ready',
                    'GSM-модем ещё не готов. Проверьте подключение и состояние устройства в Headwind GSM.', true);
            }
            $message = $create('HeadwindGSM.SMSMessage');
            $message->To = ltrim($phone, '+');
            $message->Body = $text;
            $message->Flags = 0;
        } catch (Throwable) {
            return NotificationDeliveryResult::failed('sms_headwind_prepare_failed',
                'Не удалось подготовить SMS в Headwind GSM. Проверьте драйвер и подключение модема.', true);
        }
        try {
            $message->Send();
        } catch (Throwable) {
            // Send may already have queued the message. Avoid an automatic duplicate.
            return NotificationDeliveryResult::failed('sms_headwind_send_unknown',
                'Результат передачи SMS неизвестен. Проверьте исходящие Headwind GSM перед повторной отправкой.', false);
        }
        return NotificationDeliveryResult::sent('sms_headwind_accepted');
    }
}
