<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$baseSegment = trim((string) getenv('BASE_PATH'), '/');
$basePath = $baseSegment !== '' ? '/' . $baseSegment : '';
$publicPath = $path;

if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $publicPath = substr($path, strlen($basePath)) ?: '/';
}

// Let the PHP development server return existing root-install assets directly.
// For subdirectory installs the physical file does not live under /workspace,
// so the router streams the validated repository file itself. Never resolve
// dot-segments or paths outside the repository root.
if ($publicPath !== '/' && !str_contains($publicPath, "\0") && !str_contains($publicPath, '..')) {
    $candidate = realpath($root . $publicPath);
    $realRoot = realpath($root);
    if (
        $candidate !== false
        && $realRoot !== false
        && str_starts_with($candidate, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        if ($publicPath === $path) {
            return false;
        }

        // Do not rely on mime_content_type() for browser executable assets: on
        // many Linux runners .js is reported as text/plain, which modern Chromium
        // refuses to execute under strict MIME checking.
        $extension = strtolower((string) pathinfo($candidate, PATHINFO_EXTENSION));
        $mimeTypes = [
            'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        $mime = $mimeTypes[$extension] ?? null;
        if ($mime === null && function_exists('mime_content_type')) {
            $detected = mime_content_type($candidate);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }
        header('Content-Type: ' . ($mime ?? 'application/octet-stream'));

        $size = filesize($candidate);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($candidate);
        return true;
    }
}

require $root . '/index.php';
