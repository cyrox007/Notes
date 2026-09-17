<?php

declare(strict_types=1);

namespace App\Handlers;

/**
 * Короткоживущий подписанный ticket для WebSocket-аутентификации.
 *
 * Ticket не содержит секретов и не доверяет данным клиента: identity берётся
 * только из payload, подпись которого проверяется WS_TICKET_SECRET.
 */
final class SocketTicket
{
    private const DEFAULT_TTL = 120;

    public static function issue(int $userId, ?int $ttl = null): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user id');
        }

        $now = time();
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $ttl = max(30, min($ttl, 300));

        $payload = [
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        $encodedPayload = self::base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encodedPayload, self::secret(), true);

        return $encodedPayload . '.' . self::base64UrlEncode($signature);
    }

    public static function validate(string $ticket): ?int
    {
        if ($ticket === '' || substr_count($ticket, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $encodedSignature] = explode('.', $ticket, 2);
        $signature = self::base64UrlDecode($encodedSignature);

        if ($signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, self::secret(), true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        $userId = filter_var($payload['sub'] ?? null, FILTER_VALIDATE_INT);
        $issuedAt = filter_var($payload['iat'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($payload['exp'] ?? null, FILTER_VALIDATE_INT);
        $now = time();

        if (!$userId || !$issuedAt || !$expiresAt) {
            return null;
        }

        // Небольшой допуск на рассинхронизацию часов, но не принимаем tickets из будущего.
        if ($issuedAt > $now + 30 || $expiresAt <= $now || ($expiresAt - $issuedAt) > 300) {
            return null;
        }

        return (int) $userId;
    }

    private static function secret(): string
    {
        $secret = trim((string) getenv('WS_TICKET_SECRET'));
        if (strlen($secret) < 32) {
            throw new \RuntimeException('WS_TICKET_SECRET must be at least 32 characters');
        }

        return $secret;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }

        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
