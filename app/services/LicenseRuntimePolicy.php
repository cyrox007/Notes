<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Throwable;

/**
 * Runtime enforcement policy shared by HTTP and WebSocket transports.
 *
 * Enforcement is deliberately dormant until at least one production public
 * verification key is compiled into LicenseVerifier. Once trust is configured,
 * an invalid/missing/expired license makes mutations read-only while reads and
 * explicit recovery paths remain available.
 */
final class LicenseRuntimePolicy
{
    private Closure $trustConfigured;
    private Closure $statusProvider;

    public function __construct(?callable $trustConfigured = null, ?callable $statusProvider = null)
    {
        $this->trustConfigured = $trustConfigured instanceof Closure
            ? $trustConfigured
            : ($trustConfigured !== null
                ? Closure::fromCallable($trustConfigured)
                : static fn (): bool => (new LicenseVerifier())->hasTrustedKeys());

        $this->statusProvider = $statusProvider instanceof Closure
            ? $statusProvider
            : ($statusProvider !== null
                ? Closure::fromCallable($statusProvider)
                : static fn (): array => (new LicenseService())->status());
    }

    public function enforcementEnabled(): bool
    {
        try {
            return (bool) ($this->trustConfigured)();
        } catch (Throwable $e) {
            // A broken trust-root bootstrap must not accidentally turn a licensed
            // build into an unrestricted one. Fail closed for mutations.
            error_log('License trust evaluation failed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * @return array{enforced:bool,writable:bool,code:string,message:string}
     */
    public function state(): array
    {
        if (!$this->enforcementEnabled()) {
            return [
                'enforced' => false,
                'writable' => true,
                'code' => 'enforcement_not_configured',
                'message' => '',
            ];
        }

        try {
            $status = ($this->statusProvider)();
            if (!is_array($status)) {
                throw new \RuntimeException('License status provider returned an invalid result');
            }

            $valid = (bool) ($status['valid'] ?? false);
            return [
                'enforced' => true,
                'writable' => $valid,
                'code' => (string) ($status['code'] ?? ($valid ? 'valid' : 'invalid_license')),
                'message' => (string) ($status['message'] ?? ($valid
                    ? 'Лицензия действительна'
                    : 'Установка работает в режиме только для чтения')),
            ];
        } catch (Throwable $e) {
            error_log('License runtime evaluation failed: ' . $e->getMessage());
            return [
                'enforced' => true,
                'writable' => false,
                'code' => 'license_check_failed',
                'message' => 'Не удалось подтвердить лицензию. Изменение данных временно заблокировано.',
            ];
        }
    }

    public function canMutate(): bool
    {
        return $this->state()['writable'];
    }
}
