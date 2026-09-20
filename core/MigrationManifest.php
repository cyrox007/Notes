<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;

/**
 * Reads the release migration order from data-only JSON.
 *
 * This class is intentionally part of the updater/runtime source, not loaded
 * from a candidate release during preflight. A candidate contributes only JSON
 * and SQL bytes until the live mutation boundary is crossed.
 */
final class MigrationManifest
{
    public const SCHEMA = 1;
    public const MAX_MANIFEST_BYTES = 131072;
    public const MAX_MIGRATIONS = 512;
    public const MAX_MIGRATION_BYTES = 16777216;

    private string $releaseRoot;
    private string $migrationRoot;

    /** @var array{path:string,sha256:string,migrations:list<string>}|null */
    private ?array $loaded = null;

    public function __construct(string $releaseRoot)
    {
        $input = $releaseRoot;
        $real = realpath($releaseRoot);
        if (!is_string($real) || !is_dir($real) || is_link($input)) {
            throw new RuntimeException('Release root cannot be resolved safely for migration manifest');
        }
        $this->releaseRoot = $this->normalize($real);
        $migrationRoot = realpath($real . '/database/migrations');
        if (!is_string($migrationRoot) || !is_dir($migrationRoot) || is_link($real . '/database/migrations')) {
            throw new RuntimeException('Release migration directory is missing or unsafe');
        }
        $this->migrationRoot = $this->normalize($migrationRoot);
        if (!$this->inside($this->migrationRoot, $this->releaseRoot) || $this->migrationRoot === $this->releaseRoot) {
            throw new RuntimeException('Release migration directory resolves outside release root');
        }
    }

    /** @return array{path:string,sha256:string,migrations:list<string>} */
    public function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $manifestPath = $this->migrationRoot . '/manifest.json';
        $bytes = $this->readRegularFile($manifestPath, self::MAX_MANIFEST_BYTES, 'migration manifest');
        try {
            $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Migration manifest is not valid JSON', 0, $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Migration manifest root must be an object');
        }
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Migration manifest schema is not supported');
        }
        if (($data['product'] ?? null) !== 'workspace-organizer') {
            throw new RuntimeException('Migration manifest product is invalid');
        }

        $migrations = $data['migrations'] ?? null;
        if (
            !is_array($migrations)
            || !array_is_list($migrations)
            || $migrations === []
            || count($migrations) > self::MAX_MIGRATIONS
        ) {
            throw new RuntimeException('Migration manifest list is invalid');
        }

        $validated = [];
        $seen = [];
        foreach ($migrations as $filename) {
            if (
                !is_string($filename)
                || preg_match('/^[0-9]{8}_[a-z0-9_]+\.sql$/', $filename) !== 1
                || basename($filename) !== $filename
            ) {
                throw new RuntimeException('Migration manifest contains an unsafe filename');
            }
            if (isset($seen[$filename])) {
                throw new RuntimeException('Migration manifest contains duplicate entry: ' . $filename);
            }
            $seen[$filename] = true;

            // Prove every declared entry resolves to a bounded regular file now;
            // callers may read it later through readMigration().
            $this->resolveMigration($filename);
            $validated[] = $filename;
        }

        $this->loaded = [
            'path' => $manifestPath,
            'sha256' => hash('sha256', $bytes),
            'migrations' => $validated,
        ];
        return $this->loaded;
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->load()['migrations'];
    }

    public function readMigration(string $filename): string
    {
        if (!in_array($filename, $this->names(), true)) {
            throw new RuntimeException('Migration is not declared by manifest: ' . $filename);
        }
        $path = $this->resolveMigration($filename);
        return $this->readRegularFile($path, self::MAX_MIGRATION_BYTES, 'migration ' . $filename);
    }

    private function resolveMigration(string $filename): string
    {
        $path = $this->migrationRoot . '/' . $filename;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('Declared migration is missing or unsafe: ' . $filename);
        }
        $real = realpath($path);
        if (!is_string($real)) {
            throw new RuntimeException('Declared migration path cannot be resolved: ' . $filename);
        }
        $real = $this->normalize($real);
        if (!$this->inside($real, $this->migrationRoot) || $real === $this->migrationRoot) {
            throw new RuntimeException('Declared migration resolves outside migration directory: ' . $filename);
        }
        $size = filesize($real);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_MIGRATION_BYTES) {
            throw new RuntimeException('Declared migration has an invalid size: ' . $filename);
        }
        return $real;
    }

    private function readRegularFile(string $path, int $maxBytes, string $label): string
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException(ucfirst($label) . ' is missing or unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
            throw new RuntimeException(ucfirst($label) . ' has an invalid size');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException(ucfirst($label) . ' cannot be read completely');
        }
        return $bytes;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
