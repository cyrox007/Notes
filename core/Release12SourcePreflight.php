<?php

declare(strict_types=1);

namespace Core;

/**
 * Предварительная проверка исходников релиз-кандидата 1.2.
 * Результат не является доказательством установки, защиты ветки,
 * обновления, нагрузки или ручной приёмки.
 */
final class Release12SourcePreflight
{
    /**
     * @return array{status:string,source_eligible:bool,release_ready:bool,checks:array<string,bool>,blockers:list<string>}
     */
    public static function inspect(
        string $root,
        string $version,
        int $versionCode,
        string $status,
        string $releaseDate,
        ?string $actualSha,
        ?string $expectedSha
    ): array {
        $readme = self::read($root . '/README.md');
        $changelog = self::read($root . '/CHANGELOG.md');
        $notes = self::read($root . '/docs/releases/v1.2.0.md');
        $composer = self::json($root . '/composer.json');
        $manifest = self::json($root . '/database/migrations/manifest.json');
        $migrations = $manifest['migrations'] ?? null;
        $filesPresent = is_array($migrations) && $migrations !== [] && array_is_list($migrations);
        if ($filesPresent) {
            foreach ($migrations as $migration) {
                if (!is_string($migration)
                    || preg_match('/^[a-zA-Z0-9_./-]+\.sql$/D', $migration) !== 1
                    || str_contains($migration, '..')
                    || !is_file($root . '/database/migrations/' . $migration)) {
                    $filesPresent = false;
                    break;
                }
            }
            if ($filesPresent) {
                $filesPresent = count($migrations) === count(array_unique($migrations));
            }
        }

        $checks = [
            'exact_sha' => is_string($actualSha) && is_string($expectedSha)
                && preg_match('/^[a-f0-9]{40}$/D', $actualSha) === 1
                && hash_equals($actualSha, $expectedSha),
            'version_identity' => $version === '1.2.0' && $versionCode === 12000
                && $status === 'stable'
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $releaseDate) === 1,
            'readme' => str_contains($readme, '**Версия:**')
                && str_contains($readme, '1.2.0'),
            'changelog' => preg_match('/^## 1\.2\.0(?:\s|$)/m', $changelog) === 1,
            'release_notes' => str_contains($notes, '# Workspace Organizer 1.2.0')
                && strlen($notes) > 150,
            'php_runtime' => is_array($composer)
                && ($composer['require']['php'] ?? null) === '>=8.3',
            'migration_manifest' => is_array($manifest)
                && ($manifest['schema'] ?? null) === 1
                && ($manifest['product'] ?? null) === 'workspace-organizer'
                && $filesPresent,
        ];
        $blockers = [];
        foreach ($checks as $name => $passed) {
            if (!$passed) $blockers[] = $name;
        }

        return [
            'status' => $blockers === [] ? 'source_eligible' : 'blocked',
            'source_eligible' => $blockers === [],
            'release_ready' => false,
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    private static function read(string $path): string
    {
        if (!is_file($path)) return '';
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    /** @return array<string,mixed>|null */
    private static function json(string $path): ?array
    {
        $raw = self::read($path);
        if ($raw === '') return null;
        try {
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($value) ? $value : null;
    }
}
