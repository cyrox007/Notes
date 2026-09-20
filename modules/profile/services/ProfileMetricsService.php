<?php

declare(strict_types=1);

namespace App\Services;

use Core\ModuleCapabilityRegistry;
use Core\ModuleRuntimeLoader;
use Core\ProfileContentProvider;
use InvalidArgumentException;

final class ProfileMetricsService
{
    /** @var list<string> */
    private const CAPABILITIES = [
        'workspace.notes',
        'workspace.tasks',
        'workspace.files',
    ];

    public function __construct(private ?ModuleCapabilityRegistry $capabilities = null)
    {
    }

    /** @return array<string,mixed> */
    public function summary(int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }

        $metrics = [
            'notes_count' => 0,
            'tasks_count' => 0,
            'open_tasks_count' => 0,
            'files_count' => 0,
            'storage' => [
                'used_bytes' => 0,
                'quota_bytes' => 0,
                'remaining_bytes' => 0,
                'percent' => 0.0,
            ],
        ];

        $registry = $this->capabilityRegistry();
        if ($registry !== null) {
            foreach (self::CAPABILITIES as $capability) {
                if (!$registry->has($capability)) {
                    continue;
                }

                $provider = $registry->require($capability, ProfileContentProvider::class);
                if (!$provider instanceof ProfileContentProvider) {
                    throw new \RuntimeException("Capability {$capability} has an invalid Profile metric provider");
                }
                $metrics = array_replace_recursive($metrics, $provider->profileMetrics($userId));
            }
        }

        $storage = is_array($metrics['storage'] ?? null) ? $metrics['storage'] : [];
        $used = max(0, (int) ($storage['used_bytes'] ?? 0));
        $quota = max(0, (int) ($storage['quota_bytes'] ?? 0));
        $metrics['storage'] = [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => max(0, (int) ($storage['remaining_bytes'] ?? max(0, $quota - $used))),
            'percent' => max(0.0, min(100.0, (float) ($storage['percent'] ?? 0.0))),
            'used_label' => $this->formatBytes($used),
            'quota_label' => $this->formatBytes($quota),
        ];

        return $metrics;
    }

    private function capabilityRegistry(): ?ModuleCapabilityRegistry
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }
        if (!ModuleRuntimeLoader::isBooted()) {
            return null;
        }

        return $this->capabilities = ModuleRuntimeLoader::getInstance()->capabilities();
    }

    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        $precision = $unit === 0 ? 0 : ($value >= 10 ? 1 : 2);
        return number_format($value, $precision, ',', ' ') . ' ' . $units[$unit];
    }
}
