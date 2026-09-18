<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use RuntimeException;

final class ModuleLifecycleStore
{
    /** @var list<string> */
    public const CONFIGURED_STATES = [
        'discovered',
        'installed',
        'enabled',
        'disabled',
        'degraded',
        'quarantined',
        'uninstalled',
    ];

    /** @var list<string> */
    public const EFFECTIVE_STATES = [
        'discovered',
        'installed',
        'enabled',
        'disabled',
        'incompatible',
        'degraded',
        'quarantined',
        'uninstalled',
    ];

    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * Reconcile persisted lifecycle rows with already validated manifests.
     *
     * Manifest discovery/path/schema/dependency/capability failures are handled by
     * ModuleRegistry before this store is called. A valid but core-incompatible
     * manifest is recorded as effective_state=incompatible without losing its
     * configured state.
     *
     * @param array<string,ModuleManifest> $modules
     * @return array<string,array<string,mixed>>
     */
    public function reconcile(array $modules, string $coreVersion): array
    {
        $rows = $this->rows();

        foreach ($rows as $moduleId => $row) {
            if (!isset($modules[$moduleId]) && (string) $row['configured_state'] !== 'uninstalled') {
                throw new RuntimeException("Registered module is missing from disk: {$moduleId}");
            }
        }

        foreach ($modules as $moduleId => $manifest) {
            if (!isset($rows[$moduleId])) {
                $configured = $manifest->bundled()
                    ? ($manifest->defaultEnabled() ? 'enabled' : 'disabled')
                    : 'discovered';
                $this->insert($manifest, $configured, $configured);
            }
        }

        $rows = $this->rows();
        $effective = [];
        $errors = [];
        $visiting = [];

        $resolve = function (string $moduleId) use (
            &$resolve,
            &$effective,
            &$errors,
            &$visiting,
            $modules,
            $rows,
            $coreVersion
        ): string {
            if (isset($effective[$moduleId])) {
                return $effective[$moduleId];
            }
            if (isset($visiting[$moduleId])) {
                throw new RuntimeException("Module lifecycle dependency cycle detected at {$moduleId}");
            }
            $visiting[$moduleId] = true;

            $manifest = $modules[$moduleId];
            $configured = (string) $rows[$moduleId]['configured_state'];
            $this->assertConfiguredState($configured);

            if (!$manifest->isCompatibleWithCore($coreVersion)) {
                $state = 'incompatible';
                $errors[$moduleId] = $this->compatibilityError($manifest, $coreVersion);
            } elseif ($configured === 'enabled') {
                $blockedBy = [];
                foreach ($manifest->dependencies() as $dependency) {
                    $dependencyState = $resolve($dependency);
                    if ($dependencyState !== 'enabled') {
                        $blockedBy[] = $dependency . ':' . $dependencyState;
                    }
                }
                if ($blockedBy !== []) {
                    $state = 'degraded';
                    $errors[$moduleId] = 'Dependency unavailable: ' . implode(', ', $blockedBy);
                } else {
                    $state = 'enabled';
                    $errors[$moduleId] = null;
                }
            } else {
                $state = $configured;
                $errors[$moduleId] = in_array($configured, ['degraded', 'quarantined'], true)
                    ? ($rows[$moduleId]['last_error'] ?? null)
                    : null;
            }

            unset($visiting[$moduleId]);
            $effective[$moduleId] = $state;
            return $state;
        };

        foreach (array_keys($modules) as $moduleId) {
            $resolve($moduleId);
        }

        foreach ($modules as $moduleId => $manifest) {
            $row = $rows[$moduleId];
            $stateChanged = (string) $row['effective_state'] !== $effective[$moduleId];
            $this->db->execute(
                'UPDATE module_lifecycle '
                . 'SET installed_version = :installed_version, manifest_hash = :manifest_hash, '
                . 'effective_state = :effective_state, last_error = :last_error, '
                . 'state_changed_at = CASE WHEN :state_changed = 1 THEN CURRENT_TIMESTAMP ELSE state_changed_at END '
                . 'WHERE module_id = :module_id',
                [
                    ':installed_version' => $manifest->version(),
                    ':manifest_hash' => $manifest->integrityHash(),
                    ':effective_state' => $effective[$moduleId],
                    ':last_error' => $errors[$moduleId],
                    ':state_changed' => $stateChanged ? 1 : 0,
                    ':module_id' => $moduleId,
                ]
            );
        }

        return $this->rows();
    }

    /**
     * Persist an explicit lifecycle transition. Dependency safety is checked
     * against the current reconciled effective state before the write.
     *
     * @param array<string,ModuleManifest> $modules
     * @return array<string,mixed>
     */
    public function transition(
        string $moduleId,
        string $targetState,
        array $modules,
        string $coreVersion,
        ?string $reason = null
    ): array {
        if (!isset($modules[$moduleId])) {
            throw new InvalidArgumentException("Unknown module: {$moduleId}");
        }
        $this->assertConfiguredState($targetState);

        $rows = $this->reconcile($modules, $coreVersion);
        $current = (string) $rows[$moduleId]['configured_state'];
        if ($current === $targetState) {
            return $rows[$moduleId];
        }

        $this->assertTransitionAllowed($current, $targetState);
        $manifest = $modules[$moduleId];

        if ($targetState === 'enabled') {
            if (!$manifest->isCompatibleWithCore($coreVersion)) {
                throw new RuntimeException("Cannot enable core-incompatible module: {$moduleId}");
            }
            foreach ($manifest->dependencies() as $dependency) {
                if (($rows[$dependency]['effective_state'] ?? null) !== 'enabled') {
                    throw new RuntimeException(
                        "Cannot enable {$moduleId}; dependency {$dependency} is not enabled"
                    );
                }
            }
        }

        if (in_array($targetState, ['disabled', 'quarantined', 'uninstalled'], true)) {
            foreach ($modules as $candidateId => $candidate) {
                if ($candidateId === $moduleId) {
                    continue;
                }
                if (
                    in_array($moduleId, $candidate->dependencies(), true)
                    && ($rows[$candidateId]['effective_state'] ?? null) === 'enabled'
                ) {
                    throw new RuntimeException(
                        "Cannot {$targetState} {$moduleId}; enabled module {$candidateId} depends on it"
                    );
                }
            }
        }

        $this->db->execute(
            'UPDATE module_lifecycle '
            . 'SET configured_state = :configured_state, last_error = :last_error, state_changed_at = CURRENT_TIMESTAMP '
            . 'WHERE module_id = :module_id',
            [
                ':configured_state' => $targetState,
                ':last_error' => in_array($targetState, ['degraded', 'quarantined'], true) ? $reason : null,
                ':module_id' => $moduleId,
            ]
        );

        $rows = $this->reconcile($modules, $coreVersion);
        SecurityEventLog::emit(
            'module.lifecycle_changed',
            in_array($targetState, ['disabled', 'degraded', 'quarantined', 'uninstalled'], true) ? 'warning' : 'info',
            'module_lifecycle',
            'system',
            null,
            [
                'module_id' => $moduleId,
                'from' => $current,
                'to' => $targetState,
                'reason' => $reason,
                'effective_state' => (string) ($rows[$moduleId]['effective_state'] ?? ''),
            ]
        );
        return $rows[$moduleId];
    }

    /** @return array<string,array<string,mixed>> */
    private function rows(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT module_id,installed_version,manifest_hash,configured_state,effective_state,last_error,'
            . 'discovered_at,state_changed_at,updated_at FROM module_lifecycle ORDER BY module_id ASC'
        );

        $indexed = [];
        foreach ($rows as $row) {
            $moduleId = (string) ($row['module_id'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $moduleId) !== 1) {
                throw new RuntimeException('Persisted module lifecycle contains an invalid module id');
            }
            $this->assertConfiguredState((string) ($row['configured_state'] ?? ''));
            $effective = (string) ($row['effective_state'] ?? '');
            if (!in_array($effective, self::EFFECTIVE_STATES, true)) {
                throw new RuntimeException("Invalid persisted effective state for module {$moduleId}");
            }
            if (isset($indexed[$moduleId])) {
                throw new RuntimeException("Duplicate persisted module lifecycle row: {$moduleId}");
            }
            $indexed[$moduleId] = $row;
        }
        return $indexed;
    }

    private function insert(ModuleManifest $manifest, string $configured, string $effective): void
    {
        $this->db->execute(
            'INSERT INTO module_lifecycle '
            . '(module_id,installed_version,manifest_hash,configured_state,effective_state,last_error) '
            . 'VALUES (:module_id,:installed_version,:manifest_hash,:configured_state,:effective_state,NULL)',
            [
                ':module_id' => $manifest->id(),
                ':installed_version' => $manifest->version(),
                ':manifest_hash' => $manifest->integrityHash(),
                ':configured_state' => $configured,
                ':effective_state' => $effective,
            ]
        );
    }

    private function assertConfiguredState(string $state): void
    {
        if (!in_array($state, self::CONFIGURED_STATES, true)) {
            throw new RuntimeException("Invalid configured module lifecycle state: {$state}");
        }
    }

    private function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = [
            'discovered' => ['installed', 'quarantined'],
            'installed' => ['enabled', 'disabled', 'quarantined', 'uninstalled'],
            'enabled' => ['disabled', 'degraded', 'quarantined'],
            'disabled' => ['enabled', 'quarantined', 'uninstalled'],
            'degraded' => ['enabled', 'disabled', 'quarantined'],
            'quarantined' => ['disabled'],
            'uninstalled' => ['installed'],
        ];
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new RuntimeException("Invalid module lifecycle transition: {$from} -> {$to}");
        }
    }

    private function compatibilityError(ModuleManifest $manifest, string $coreVersion): string
    {
        try {
            $manifest->assertCompatibleWithCore($coreVersion);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        return 'Module is incompatible with the running core';
    }
}
