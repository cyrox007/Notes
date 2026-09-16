<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class ModuleAssetController
{
    private const MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    public function serve(Request $request): void
    {
        $moduleId = (string) $request->get('module', '');
        $asset = (string) $request->get('file', '');

        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $moduleId) !== 1 || !$this->validAssetPath($asset)) {
            $this->notFound();
        }

        if (!ModuleRuntimeLoader::isBooted()) {
            throw new RuntimeException('Module runtime loader is unavailable while serving module assets');
        }

        $roots = ModuleRuntimeLoader::getInstance()->assetRoots();
        $root = $roots[$moduleId] ?? null;
        if (!is_string($root) || $root === '') {
            $this->notFound();
        }

        $extension = strtolower((string) pathinfo($asset, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$extension] ?? null;
        if ($mime === null) {
            $this->notFound();
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            $this->notFound();
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            $this->notFound();
        }

        $size = filesize($resolved);
        if ($size === false) {
            throw new RuntimeException("Unable to stat module asset {$moduleId}:{$asset}");
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) $size);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($resolved);
    }

    private function validAssetPath(string $asset): bool
    {
        return $asset !== ''
            && !str_starts_with($asset, '/')
            && !str_contains($asset, '..')
            && !str_contains($asset, '\\')
            && preg_match('/^[A-Za-z0-9_.\/-]+$/D', $asset) === 1;
    }

    private function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo '404 Module Asset Not Found';
        exit;
    }
}
