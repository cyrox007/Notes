<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/WebPushHttpTransport.php';
require_once __DIR__ . '/PushSubscriptionService.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * Публичный HTTPS push-сервис, проверенный DNS с закреплением адреса,
 * без редиректов и использования системного прокси.
 */
final class CurlWebPushHttpTransport implements WebPushHttpTransport
{
    public function send(string $endpoint, string $body, array $headers): int
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Расширение PHP cURL необходимо для Web Push');
        }
        $endpoint = PushSubscriptionService::endpoint($endpoint);
        if (strlen($body) > 4096) {
            throw new InvalidArgumentException('Зашифрованное Push-сообщение превышает 4096 байт');
        }
        $parts = parse_url($endpoint);
        $host = strtolower((string) $parts['host']);
        $ips = dns_get_record($host, DNS_A + DNS_AAAA);
        if (!is_array($ips) || $ips === []) {
            throw new RuntimeException('Недоступен публичный DNS Push-сервиса');
        }
        $safe = [];
        foreach ($ips as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || str_contains(strtolower($ip), ':ffff:')
                || filter_var($ip, FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Адрес Push-сервиса ведёт в запрещённую сеть');
            }
            $safe[] = $ip;
        }
        $headersList = ['Content-Type: application/octet-stream'];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,80}$/D', $name) !== 1
                || strlen($value) > 4000 || strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Недопустимый HTTP-заголовок Push');
            }
            $headersList[] = $name . ': ' . $value;
        }

        $ip = $safe[0];
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Не удалось открыть Web Push соединение');
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headersList,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host . ':443:' . $pinned],
            ]);
            $response = curl_exec($curl);
            if (!is_string($response)) {
                throw new RuntimeException('Push-сервис недоступен или сертификат не прошёл проверку');
            }
            return (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }
    }
}
