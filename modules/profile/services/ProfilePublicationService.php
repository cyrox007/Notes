<?php

declare(strict_types=1);

namespace App\Services;

use Core\ModuleCapabilityRegistry;
use Core\ModuleRuntimeLoader;
use Core\ProfileContentProvider;
use InvalidArgumentException;

final class ProfilePublicationService
{
    /** @var array<string,array{capability:string,bucket:string}> */
    private const TYPES = [
        'note' => ['capability' => 'workspace.notes', 'bucket' => 'notes'],
        'task' => ['capability' => 'workspace.tasks', 'bucket' => 'tasks'],
        'file' => ['capability' => 'workspace.files', 'bucket' => 'files'],
    ];

    public function __construct(private ?ModuleCapabilityRegistry $capabilities = null)
    {
    }

    /**
     * @return array{
     *   notes:list<array<string,mixed>>,
     *   tasks:list<array<string,mixed>>,
     *   files:list<array<string,mixed>>,
     *   metrics:array<string,mixed>
     * }
     */
    public function ownerItems(int $userId, int $limitPerType = 12): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }

        $limit = max(1, min(50, $limitPerType));
        $result = [
            'notes' => [],
            'tasks' => [],
            'files' => [],
        ];

        foreach (self::TYPES as $definition) {
            $provider = $this->provider($definition['capability']);
            if ($provider !== null) {
                $result[$definition['bucket']] = $provider->ownerProfileItems($userId, $limit);
            }
        }

        $result['metrics'] = (new ProfileMetricsService($this->capabilityRegistry()))->summary($userId);
        return $result;
    }

    /**
     * Returns safe public-profile metadata only. Providers expose their own safe
     * projection; Profile never reads another module's tables directly.
     *
     * @return array{
     *   notes:list<array<string,mixed>>,
     *   tasks:list<array<string,mixed>>,
     *   files:list<array<string,mixed>>
     * }
     */
    public function publicItems(int $userId, int $limitPerType = 24): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Некорректный пользователь');
        }

        $limit = max(1, min(50, $limitPerType));
        $result = [
            'notes' => [],
            'tasks' => [],
            'files' => [],
        ];

        foreach (self::TYPES as $definition) {
            $provider = $this->provider($definition['capability']);
            if ($provider !== null) {
                $result[$definition['bucket']] = $provider->publicProfileItems($userId, $limit);
            }
        }

        return $result;
    }

    public function setVisibility(int $userId, string $type, string $uid, bool $isPublic): void
    {
        $type = trim($type);
        $uid = trim($uid);
        if ($userId <= 0 || !isset(self::TYPES[$type]) || $uid === '' || strlen($uid) > 64) {
            throw new InvalidArgumentException('Некорректный объект публикации');
        }

        $provider = $this->provider(self::TYPES[$type]['capability']);
        if ($provider === null) {
            throw new InvalidArgumentException('Модуль для этого типа публикации недоступен');
        }

        $provider->setProfileVisibility($userId, $uid, $isPublic);
    }

    private function provider(string $capability): ?ProfileContentProvider
    {
        $registry = $this->capabilityRegistry();
        if ($registry === null || !$registry->has($capability)) {
            return null;
        }

        $provider = $registry->require($capability, ProfileContentProvider::class);
        if (!$provider instanceof ProfileContentProvider) {
            throw new \RuntimeException("Capability {$capability} has an invalid Profile content provider");
        }

        return $provider;
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
}
