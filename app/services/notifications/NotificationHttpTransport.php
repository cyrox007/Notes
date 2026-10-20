<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface NotificationHttpTransport
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @return array{status:int,body:string}
     */
    public function postJson(string $url, array $headers, array $payload, int $timeout = 10): array;
}
