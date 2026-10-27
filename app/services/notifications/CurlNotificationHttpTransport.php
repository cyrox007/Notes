<?php

declare(strict_types=1);

namespace App\Services\Notifications;

require_once __DIR__ . '/NotificationHttpTransport.php';
require_once __DIR__ . '/LimitedHttpResponse.php';

use InvalidArgumentException;
use RuntimeException;

final class CurlNotificationHttpTransport implements NotificationHttpTransport
{
    public function postJson(string $url, array $headers, array $payload, int $timeout = 10): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Для HTTPS-доставки необходимо расширение PHP cURL');
        }
        if ($timeout < 1 || $timeout > 20) {
            throw new InvalidArgumentException('Время ожидания HTTPS должно быть от 1 до 20 секунд');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('Доставка разрешена только на корректный HTTPS-адрес');
        }

        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || strlen($host) > 253 || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Недопустимое имя узла HTTPS');
        }

        // Резолвим и закрепляем проверенный публичный адрес. Без CURLOPT_RESOLVE
        // возможна подмена DNS между проверкой и сетевым соединением.
        $ips = [];
        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || filter_var(
                $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                throw new InvalidArgumentException('Адрес ведёт во внутреннюю или запрещённую сеть');
            }
            $ips[] = $ip;
        }
        if ($ips === []) {
            throw new RuntimeException('Публичный DNS-адрес канала не найден');
        }

        $body = json_encode(
            $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (strlen($body) > 8192) {
            throw new InvalidArgumentException('Тело внешнего уведомления превышает безопасный размер');
        }

        $requestHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $key => $value) {
            if (preg_match('/^[A-Za-z0-9-]{1,80}$/', $key) !== 1
                || strlen($value) > 1000 || strpbrk($value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Недопустимый заголовок HTTPS-доставки');
            }
            $requestHeaders[] = $key . ': ' . $value;
        }

        $responseBuffer = new LimitedHttpResponse();
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Не удалось начать HTTPS-доставку');
        }
        try {
            $ip = $ips[0];
            $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int
                    => $responseBuffer->accept($chunk),
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host . ':443:' . $pinned],
            ]);

            $response = curl_exec($curl);
            if ($responseBuffer->exceeded()) {
                throw new RuntimeException('HTTP-ответ внешнего канала превышает допустимый размер');
            }
            if ($response !== true) {
                // Не возвращаем URL, токен, тело запроса, DNS и cURL error.
                throw new RuntimeException('HTTPS-соединение не выполнено; проверьте сеть и сертификаты');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return ['status' => $status, 'body' => $responseBuffer->preview()];
        } finally {
            curl_close($curl);
        }
    }
}
