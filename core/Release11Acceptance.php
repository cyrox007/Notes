<?php

declare(strict_types=1);

namespace Core;

final class Release11Acceptance
{
    /** @param list<string> $arguments */
    public static function run(string $root, array $arguments): int
    {
        $policy = json_decode((string) file_get_contents($root . '/.github/release-readiness.json'), true, 32, JSON_THROW_ON_ERROR);
        $checks = [
            'php_floor' => PHP_VERSION_ID >= 80200,
            'release_identity' => Version::VERSION === '1.1.0-rc.1' && Version::VERSION_CODE === 10901 && Version::STATUS === 'rc',
            'release_policy_identity' => ($policy['version'] ?? '') === Version::VERSION,
            'upgrade_drill' => is_file($root . '/.github/workflows/1.0.16-to-1.1-upgrade.yml'),
            'rollback_drill' => is_file($root . '/.github/workflows/1.0.16-to-1.1-rollback.yml'),
            'release_notes' => is_file($root . '/docs/releases/v' . Version::VERSION . '.md'),
            'operator_instructions' => is_file($root . '/docs/releases/1.1-UPGRADE.md'),
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
        $pending = ($policy['ready'] ?? false) === true ? [] : ($policy['blockers'] ?? ['Release acceptance pending']);
        $result = ['status' => $failed !== [] ? 'fail' : ($pending !== [] ? 'pending' : 'ok'),
            'version' => Version::VERSION, 'checks' => $checks, 'failures' => $failed, 'pending' => $pending,
            'load_testing' => 'Deferred to 1.2.0; capacity not yet certified'];
        if (in_array('--json', $arguments, true)) {
            CliRuntime::writeJson($result, true);
        } else {
            foreach ($checks as $name => $ok) echo ($ok ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
            foreach ($pending as $item) echo '[PENDING] ' . $item . PHP_EOL;
        }
        return $failed !== [] || (in_array('--strict', $arguments, true) && $pending !== []) ? 1 : 0;
    }
}
