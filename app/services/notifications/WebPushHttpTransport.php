<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/** HTTP-транспорт Web Push не принимает JSON: тело уже зашифровано AES128GCM. */
interface WebPushHttpTransport
{
    /** @param array<string,string> $headers */
    public function send(string $endpoint, string $body, array $headers): int;
}
