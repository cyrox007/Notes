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

// Let the PHP development server return existing public assets directly. Never
// resolve dot-segments or paths outside the repository root. For subdirectory
// installs only strip BASE_PATH for physical asset lookup; the original request
// URI is preserved for the application Router below.
if ($publicPath !== '/' && !str_contains($publicPath, "\0") && !str_contains($publicPath, '..')) {
    $candidate = realpath($root . $publicPath);
    $realRoot = realpath($root);
    if (
        $candidate !== false
        && $realRoot !== false
        && str_starts_with($candidate, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        && is_file($candidate)
    ) {
        return false;
    }
}

require $root . '/index.php';
