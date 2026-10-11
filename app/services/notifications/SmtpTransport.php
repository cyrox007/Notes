<?php

declare(strict_types=1);

namespace App\Services\Notifications;

interface SmtpTransport
{
    /**
     * @param array<string,mixed> $settings
     */
    public function send(
        array $settings,
        string $recipientEmail,
        string $subject,
        string $textBody,
        string $htmlBody,
        #[\SensitiveParameter] ?string $password
    ): void;
}
