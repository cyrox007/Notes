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

        $mime = function_exists('mime_content_type') ? mime_content_type($candidate) : false;
        if (is_string($mime) && $mime !== '') {
            header('Content-Type: ' . $mime);
        }
        $size = filesize($candidate);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($candidate);
        return true;
    }
}

require $root . '/index.php';
