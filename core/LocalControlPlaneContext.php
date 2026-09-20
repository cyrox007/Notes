<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Marker capability for installation-local recovery operations.
 *
 * This object cannot be constructed by the HTTP runtime. Services accepting it
 * therefore expose an explicit local-operator path without weakening their
 * normal user/RBAC authorization methods.
 */
final class LocalControlPlaneContext
{
    private function __construct()
    {
    }

    public static function forCli(): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Local control plane is available only from PHP CLI.');
        }

        return new self();
    }

    public function assertCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Local control plane context is not valid outside PHP CLI.');
        }
    }
}
