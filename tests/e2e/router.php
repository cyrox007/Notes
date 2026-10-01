<?php

declare(strict_types=1);

$defaultRoot = dirname(__DIR__, 2);
$configuredRoot = trim((string) getenv('E2E_APP_ROOT'));
$root = $configuredRoot !== '' ? (realpath($configuredRoot) ?: '') : $defaultRoot;
if ($root === '' || !is_dir($root)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Некорректный E2E_APP_ROOT';
    return true;
}
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

// Во время детерминированной проверки updater не пропускаем фоновые
// динамические запросы старой страницы в bootstrap/recovery. Статические файлы
// уже обработаны выше, а сам updater продолжает работать через web-start/web-step.
$updaterGuardPath = trim((string) getenv('E2E_UPDATER_GUARD_PATH'));
if ($updaterGuardPath !== '' && is_file($updaterGuardPath)) {
    $guardBytes = file_get_contents($updaterGuardPath);
    $guard = is_string($guardBytes) ? json_decode($guardBytes, true) : null;
    $guardTransaction = is_array($guard)
        ? trim((string) ($guard['transaction_id'] ?? ''))
        : '';

    $normalizedPath = rtrim($publicPath, '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    $requestTransaction = trim(
        (string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TRANSACTION'] ?? '')
    );
    $e2eDriver = trim((string) ($_SERVER['HTTP_X_E2E_UPDATER_DRIVER'] ?? ''));
    $isTestDriver = hash_equals('1', $e2eDriver);

    $isStart = $isTestDriver
        && $method === 'POST'
        && str_ends_with($normalizedPath, '/admin/updates/web-start');
    $isStep = $isTestDriver
        && $method === 'POST'
        && str_ends_with($normalizedPath, '/admin/updates/web-step')
        && $guardTransaction !== ''
        && $requestTransaction !== ''
        && hash_equals($guardTransaction, $requestTransaction);

    if (!$isStart && !$isStep) {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'E2E updater handoff guard';
        return true;
    }

    // Помеченный driver-step передаём прямо в тот же ранний bridge, который
    // использует production index.php. Так проверка точки прерывания не зависит
    // от гонки между bootstrap recovery и следующим тестовым HTTP-запросом.
    if ($isStep) {
        require_once $root . '/core/Environment.php';
        \Core\Environment::load($root . '/.env');
        require_once $root . '/app/services/MaintenanceModeService.php';

        $maintenance = new \App\Services\MaintenanceModeService(null, $root);
        $maintenanceState = $maintenance->state();

        // Первый web-step штатно идёт через обычный Router: именно он включает
        // maintenance. После этого все driver-step обязаны проходить через
        // production early bridge и не могут быть обогнаны boot recovery.
        if (!empty($maintenanceState['active']) && !empty($maintenanceState['valid'])) {
            require_once $root . '/core/UpdateWebHttpBridge.php';

            if (!\Core\UpdateWebHttpBridge::canHandle($maintenance, $maintenanceState)) {
                http_response_code(409);
                header('Cache-Control: no-store');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(
                    [
                        'success' => false,
                        'error' => 'e2e_bridge_handoff_failed',
                        'message' => 'Тестовый updater driver не прошёл production bridge.',
                    ],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL;
                return true;
            }

            \Core\UpdateWebHttpBridge::handle(
                $root,
                $maintenance,
                $maintenanceState
            );
        }
    }
}

$configuredSupportRoot = trim((string) getenv('E2E_SUPPORT_ROOT'));
$supportRoot = $configuredSupportRoot !== '' ? (realpath($configuredSupportRoot) ?: '') : $root;
$licenseFixture = $supportRoot !== '' ? $supportRoot . '/tests/support/ci_license_fixture.php' : '';

if ($licenseFixture !== '' && is_file($licenseFixture)) {
    require_once $licenseFixture;
    workspaceEnsureCiLicense();
}

require $root . '/index.php';
