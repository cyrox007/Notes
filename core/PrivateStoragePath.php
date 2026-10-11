<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/Environment.php';
require_once __DIR__ . '/PrivateStorageResolver.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * Единая точка разрешения путей приватного хранилища для прикладных модулей.
 */
final class PrivateStoragePath
{
    private string $appRoot;
    private PrivateStorageResolver $resolver;
    private ?string $resolvedRoot = null;

    public function __construct(?string $appRoot = null, ?string $documentRoot = null)
    {
        $root = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Не удалось определить корень приложения');
        }

        $this->appRoot = $root;
        $this->resolver = new PrivateStorageResolver($root, $documentRoot);
    }

    public function root(): string
    {
        if ($this->resolvedRoot !== null) {
            return $this->resolvedRoot;
        }

        $configured = Environment::get('PRIVATE_STORAGE_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            return $this->resolvedRoot = $this->resolver->explicitCandidate(trim($configured));
        }

        $legacy = dirname($this->appRoot) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        if (is_dir($legacy) && !is_link($legacy)) {
            return $this->resolvedRoot = $this->resolver->explicitCandidate($legacy);
        }

        return $this->resolvedRoot = $this->resolver->candidate();
    }

    public function child(string ...$segments): string
    {
        $path = $this->root();
        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1) {
                throw new InvalidArgumentException('Некорректный сегмент пути приватного хранилища');
            }
            $path .= DIRECTORY_SEPARATOR . $segment;
        }
        return $path;
    }

    public function resolveExisting(string $path, ?string $scopeRoot = null): ?string
    {
        $realPath = realpath($path);
        $realRoot = realpath($scopeRoot ?? $this->root());
        if (!is_string($realPath) || !is_string($realRoot)) {
            return null;
        }

        $candidate = self::normalize($realPath);
        $root = self::normalize($realRoot);
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate . '/', $root . '/')
            ? $realPath
            : null;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
