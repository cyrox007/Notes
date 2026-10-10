<?php

declare(strict_types=1);

namespace App\Services;

use Core\PrivateStorageResolver;
use Throwable;

/** Read-only capacity estimate. Quotas are ceilings, never disk reservations. */
final class StorageCapacityService
{
    public function __construct(private ?string $storagePath = null)
    {
    }

    public function snapshot(array $users): array
    {
        $used = 0;
        $assigned = 0;
        $potential = 0;
        foreach ($users as $user) {
            $bytes = max(0, (int) ($user['used_bytes'] ?? 0));
            $quota = max(0, (int) ($user['effective_quota'] ?? 0));
            $used += $bytes;
            $assigned += $quota;
            $potential += max(0, $quota - $bytes);
        }

        $total = null;
        $free = null;
        try {
            $path = $this->storagePath ?? (new PrivateStorageResolver())->candidate();
            if (is_dir($path)) {
                $total = $this->measure('disk_total_space', $path);
                $free = $this->measure('disk_free_space', $path);
            }
        } catch (Throwable) {
            // Restricted hosting can hide filesystem information; retain unknown values.
        }

        return [
            'total_bytes' => $total,
            'free_bytes' => $free,
            'used_bytes' => $used,
            'assigned_bytes' => $assigned,
            'potential_bytes' => $potential,
            'overcommitted' => $free !== null && $potential > $free,
            'shortfall_bytes' => $free !== null ? max(0, $potential - $free) : null,
        ];
    }

    private function measure(string $function, string $path): ?int
    {
        if (!function_exists($function)) {
            return null;
        }
        $value = @$function($path);
        return is_numeric($value) && is_finite((float) $value) && $value >= 0
            ? (int) min((float) PHP_INT_MAX, (float) $value)
            : null;
    }
}
