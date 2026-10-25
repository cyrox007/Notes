<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Проверяет препятствия для SMTP без отправки письма.
 * Вывод содержит только фиксированные коды и сообщения: адреса,
 * имена серверов, переменные окружения и пароли наружу не попадают.
 */
final class SmtpOperationalReadiness
{
    /**
     * @param array<string,mixed>|null $config Строка таблицы каналов
     * @return array{state:string,blockers:list<array{code:string,message:string}>,warnings:list<array{code:string,message:string}>,delivery_verified:bool}
     */
    public static function assess(
        ?array $config,
        bool $secretAvailable,
        int $activeSubscriptions,
        string $workerState
    ): array {
        $blockers = [];
        $warnings = [];
        if ($config === null) {
            $blockers[] = self::finding('smtp_not_configured',
                'SMTP ещё не настроен. Сохраните параметры канала в административном разделе.');
        } else {
            if ((int) ($config['enabled'] ?? 0) !== 1) {
                $blockers[] = self::finding('smtp_disabled',
                    'SMTP выключен. Включите канал в его настройках.');
            }

            $raw = $config['config_json'] ?? null;
            $settings = is_array($raw) ? $raw
                : (is_string($raw) ? json_decode($raw, true) : null);
            if (!is_array($settings) || array_is_list($settings)) {
                $blockers[] = self::finding('smtp_settings_invalid',
                    'Настройки SMTP повреждены или не заполнены. Сохраните их повторно.');
            } else {
                $host = trim((string) ($settings['host'] ?? ''));
                $port = filter_var($settings['port'] ?? null, FILTER_VALIDATE_INT);
                $from = trim((string) ($settings['from_email'] ?? ''));
                $encryption = (string) ($settings['encryption'] ?? '');
                $username = trim((string) ($settings['username'] ?? ''));
                if ($host === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $host) !== 1
                    || $port === false || $port < 1 || $port > 65535
                    || !in_array($encryption, ['tls', 'ssl', 'none'], true)
                    || filter_var($from, FILTER_VALIDATE_EMAIL) === false
                    || ($username !== '' && $encryption === 'none')) {
                    $blockers[] = self::finding('smtp_settings_invalid',
                        'Неверны адрес сервера, порт, отправитель или защита соединения SMTP.');
                }
                if ($username !== '' && !$secretAvailable) {
                    $blockers[] = self::finding('smtp_secret_unavailable',
                        'Для SMTP-авторизации не найден пароль в окружении текущего процесса PHP.');
                }
            }
        }

        if ($activeSubscriptions < 1) {
            $warnings[] = self::finding('smtp_no_subscriptions',
                'Нет активных подписок на почту. Обычные события без подписки не создают внешних заданий; пробная отправка настраивает собственную диагностическую категорию.');
        }
        if ($workerState !== 'healthy') {
            $warnings[] = self::finding('smtp_worker_unverified',
                'Нет подтверждённого свежего запуска отдельного обработчика очереди. Проверьте расписание; резервная HTTP-обработка оценивается отдельно.');
        }

        return [
            'state' => $blockers !== [] ? 'blocked' : 'needs_delivery_proof',
            'blockers' => $blockers,
            'warnings' => $warnings,
            // Даже отсутствие препятствий не доказывает SMTP-доставку или получение адресатом.
            'delivery_verified' => false,
        ];
    }

    /** @return array{code:string,message:string} */
    private static function finding(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
