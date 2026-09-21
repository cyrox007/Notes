<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptMethods;
use Core\DatabaseManager;
use RuntimeException;
use Throwable;

final class TwoFactorService
{
    public const PERIOD_SECONDS = 30;
    public const DIGITS = 6;
    public const WINDOW_STEPS = 1;
    public const RECOVERY_CODE_COUNT = 10;

    private const SECRET_BYTES = 20;
    private const RECOVERY_RAW_LENGTH = 20;

    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public function provisioningUri(string $account, string $issuer, string $secret): string
    {
        $account = trim($account);
        $issuer = trim($issuer);
        if ($account === '' || $issuer === '') {
            throw new RuntimeException('TOTP account and issuer are required');
        }
        if (!self::isBase32Secret($secret)) {
            throw new RuntimeException('TOTP secret is invalid');
        }

        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS
            . '&period=' . self::PERIOD_SECONDS;
    }

    public function encryptSecret(string $secret, string $userUid): string
    {
        if (!self::isBase32Secret($secret) || trim($userUid) === '') {
            throw new RuntimeException('Cannot encrypt an invalid TOTP secret');
        }

        return CryptMethods::encrypt($secret, $this->secretAad($userUid));
    }

    public function decryptSecret(string $payload, string $userUid): string
    {
        $secret = CryptMethods::decrypt($payload, $this->secretAad($userUid));
        if (!self::isBase32Secret($secret)) {
            throw new RuntimeException('Stored TOTP secret is invalid');
        }

        return $secret;
    }

    public function codeForTime(string $secret, int $unixTime): string
    {
        $counter = intdiv(max(0, $unixTime), self::PERIOD_SECONDS);
        return $this->codeForCounter($secret, $counter);
    }

    public function matchingCounter(
        string $secret,
        string $code,
        ?int $lastAcceptedCounter = null,
        ?int $unixTime = null
    ): ?int {
        $code = trim($code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/D', $code) !== 1) {
            return null;
        }

        $now = $unixTime ?? time();
        $current = intdiv(max(0, $now), self::PERIOD_SECONDS);

        // Prefer the current step, then tolerate one step of drift in either
        // direction. Never accept a counter that was already consumed.
        $offsets = [0, -1, 1];
        foreach ($offsets as $offset) {
            if (abs($offset) > self::WINDOW_STEPS) {
                continue;
            }
            $counter = $current + $offset;
            if ($counter < 0 || ($lastAcceptedCounter !== null && $counter <= $lastAcceptedCounter)) {
                continue;
            }
            if (hash_equals($this->codeForCounter($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(10)));
            $codes[] = implode('-', str_split($raw, 5));
        }
        return $codes;
    }

    /** @param list<string> $codes */
    public function hashRecoveryCodes(array $codes): string
    {
        $hashes = [];
        foreach ($codes as $code) {
            $normalized = self::normalizeRecoveryCode($code);
            if (preg_match('/^[A-F0-9]{' . self::RECOVERY_RAW_LENGTH . '}$/D', $normalized) !== 1) {
                throw new RuntimeException('Recovery code has invalid format');
            }
            $hashes[] = hash('sha256', $normalized);
        }

        return json_encode($hashes, JSON_THROW_ON_ERROR);
    }

    /**
     * Verify and atomically consume either a TOTP or a recovery code.
     *
     * @return array{ok:bool,used_recovery:bool}
     */
    public function verifyAndConsume(DatabaseManager $db, int $userId, string $code): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'used_recovery' => false];
        }

        $db->beginTransaction();
        try {
            $row = $db->fetchOne(
                'SELECT uid,totp_enabled,totp_secret,totp_last_counter,totp_recovery_codes '
                . 'FROM users WHERE id = :id AND is_active = 1 AND account_status = \'active\' FOR UPDATE',
                [':id' => $userId]
            );

            if (
                !$row
                || (int) ($row['totp_enabled'] ?? 0) !== 1
                || !is_string($row['totp_secret'] ?? null)
                || trim((string) $row['totp_secret']) === ''
            ) {
                $db->endTransaction(false);
                return ['ok' => false, 'used_recovery' => false];
            }

            $uid = (string) ($row['uid'] ?? '');
            $secret = $this->decryptSecret((string) $row['totp_secret'], $uid);
            $lastCounter = $row['totp_last_counter'] === null
                ? null
                : (int) $row['totp_last_counter'];

            $counter = $this->matchingCounter($secret, trim($code), $lastCounter);
            if ($counter !== null) {
                $db->execute(
                    'UPDATE users SET totp_last_counter = :counter, updated_at = :updated_at WHERE id = :id',
                    [
                        ':counter' => $counter,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => $userId,
                    ]
                );
                $db->endTransaction(true);
                return ['ok' => true, 'used_recovery' => false];
            }

            $updatedRecoverySet = $this->consumeRecoveryCodeHashSet(
                is_string($row['totp_recovery_codes'] ?? null) ? (string) $row['totp_recovery_codes'] : null,
                $code
            );
            if ($updatedRecoverySet !== null) {
                $db->execute(
                    'UPDATE users SET totp_recovery_codes = :codes, updated_at = :updated_at WHERE id = :id',
                    [
                        ':codes' => $updatedRecoverySet,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => $userId,
                    ]
                );
                $db->endTransaction(true);
                return ['ok' => true, 'used_recovery' => true];
            }

            $db->endTransaction(false);
            return ['ok' => false, 'used_recovery' => false];
        } catch (Throwable $e) {
            $db->endTransaction(false);
            throw $e;
        }
    }

    public function consumeRecoveryCodeHashSet(?string $json, string $code): ?string
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $normalized = self::normalizeRecoveryCode($code);
        if (preg_match('/^[A-F0-9]{' . self::RECOVERY_RAW_LENGTH . '}$/D', $normalized) !== 1) {
            return null;
        }

        try {
            $hashes = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Stored recovery-code set is invalid', 0, $e);
        }
        if (!is_array($hashes) || !array_is_list($hashes)) {
            throw new RuntimeException('Stored recovery-code set is invalid');
        }

        $candidate = hash('sha256', $normalized);
        foreach ($hashes as $index => $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new RuntimeException('Stored recovery-code hash is invalid');
            }
            if (!hash_equals($hash, $candidate)) {
                continue;
            }

            unset($hashes[$index]);
            return json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
        }

        return null;
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        if (!self::isBase32Secret($secret) || $counter < 0) {
            throw new RuntimeException('Cannot calculate TOTP for invalid input');
        }

        $key = self::base32Decode($secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $message = pack('N2', $high, $low);
        $hmac = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0f;
        $part = substr($hmac, $offset, 4);
        $value = unpack('Nvalue', $part);
        $binary = ((int) ($value['value'] ?? 0)) & 0x7fffffff;
        $modulo = 10 ** self::DIGITS;

        return str_pad((string) ($binary % $modulo), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function secretAad(string $userUid): string
    {
        return 'two-factor-totp-secret:' . trim($userUid);
    }

    private static function isBase32Secret(string $secret): bool
    {
        return preg_match('/^[A-Z2-7]{16,128}$/D', strtoupper(trim($secret))) === 1;
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Fa-f0-9]/', '', trim($code)));
    }

    private static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($bytes) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $output .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
        $secret = strtoupper(trim($secret));
        if (!self::isBase32Secret($secret)) {
            throw new RuntimeException('TOTP secret is invalid');
        }

        $output = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($secret) as $character) {
            if (!array_key_exists($character, $alphabet)) {
                throw new RuntimeException('TOTP secret is invalid');
            }
            $buffer = ($buffer << 5) | $alphabet[$character];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $output;
    }
}
