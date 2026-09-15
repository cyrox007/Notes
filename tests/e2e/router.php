<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));

$configuredBasePath = getenv('BASE_PATH');
if (!is_string($configuredBasePath) || trim($configuredBasePath) === '') {
    $envFile = $root . '/.env';
    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_starts_with($line, 'BASE_PATH=')) {
                continue;
            }

            $configuredBasePath = trim(substr($line, strlen('BASE_PATH=')));
            $configuredBasePath = trim($configuredBasePath, " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

$baseSegment = trim((string) $configuredBasePath, '/');
$basePath = $baseSegment !== '' ? '/' . $baseSegment : '';
$publicPath = $path;

if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $publicPath = substr($path, strlen($basePath)) ?: '/';
}

// Mirror the production source/package boundary from .htaccess. Browser E2E must
// never pass merely because the PHP development server exposes repository files
// that Apache/Nginx production is expected to keep private.
$trimmedPublicPath = ltrim($publicPath, '/');
$firstSegment = explode('/', $trimmedPublicPath, 2)[0] ?? '';
$privateSegments = [
    'app', 'bin', 'core', 'database', 'docs', 'modules', 'vendor', 'ws_server',
    '.github', '.git', '.logs',
];
$privateRootFiles = [
    '.env', 'default.env', 'composer.json', 'composer.lock', '.gitignore',
    'README.md', 'CHANGELOG.md', 'TASKS_MODULE_README.md',
];

if (
    in_array($firstSegment, $privateSegments, true)
    || in_array($trimmedPublicPath, $privateRootFiles, true)
    || str_starts_with($trimmedPublicPath, '.env.')
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Page Not Found';
    return true;
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
