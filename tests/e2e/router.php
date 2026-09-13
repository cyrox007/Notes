<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

// Let the PHP development server return existing public assets directly. Never
// resolve dot-segments or paths outside the repository root.
if ($path !== '/' && !str_contains($path, "\0") && !str_contains($path, '..')) {
    $candidate = realpath($root . $path);
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
