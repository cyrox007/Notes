<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!defined('SITEPATH')) {
    define('SITEPATH', __DIR__);
}

require_once SITEPATH . '/core/SecurityHeaders.php';
\Core\SecurityHeaders::apply();

// Startup failures can happen before .env is loaded. Keep this fallback outside
// the public application tree; configured application logging takes over later.
ini_set('error_log', sys_get_temp_dir() . '/workspace-organizer-startup.log');

function handleStartupError(string $message, string $title = 'System Error'): never
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cspNonce = htmlspecialchars(\Core\SecurityHeaders::nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$safeTitle}</title>
    <style nonce="{$cspNonce}">
        :root { color-scheme: light; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; background:#f4f6f9; color:#1f2937; }
        .error-card { width:min(620px,100%); padding:28px; background:#fff; border:1px solid #dce2e9; border-radius:16px; box-shadow:0 18px 48px rgba(31,41,55,.10); }
        .error-mark { width:48px; height:48px; display:grid; place-items:center; border-radius:12px; background:#fff1f1; color:#b42318; font-size:24px; }
        h1 { margin:18px 0 8px; font-size:24px; } p { margin:0; line-height:1.6; color:#667085; }
        .message { margin-top:18px; padding:14px 16px; background:#f8fafc; border:1px solid #e4e7ec; border-radius:10px; color:#344054; word-break:break-word; }
        .hint { margin-top:16px; font-size:14px; }
    </style>
</head>
<body>
    <main class="error-card" role="alert">
        <div class="error-mark" aria-hidden="true">!</div>
        <h1>{$safeTitle}</h1>
        <p>Приложение не смогло завершить запуск.</p>
        <div class="message">{$safeMessage}</div>
        <p class="hint">Проверьте конфигурацию окружения и server error log. Для fresh install откройте <code>/install.php</code>.</p>
    </main>
</body>
</html>
HTML;
    exit;
}

/** @param array{active:bool,valid:bool,transaction_id:?string,reason:string,started_at:?int,state_path:?string} $state */
/**
 * @param array{ready:bool,missing_tables:list<string>,missing_user_columns:list<string>} $state
 */
function handleSchemaUpgradeRequired(array $state): never
{
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'database_schema_update_required',
            'message' => 'Код приложения новее схемы базы данных. Завершите миграции.',
            'retry_after' => 60,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $cspNonce = htmlspecialchars(\Core\SecurityHeaders::nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $missingCount = count($state['missing_tables']) + count($state['missing_user_columns']);
    echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Требуется обновление базы данных</title>
    <style nonce="{$cspNonce}">
        :root { color-scheme: light dark; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; background:#f4f6f9; color:#1f2937; }
        main { width:min(720px,100%); padding:28px; background:#fff; border:1px solid #dce2e9; border-radius:16px; box-shadow:0 18px 48px rgba(31,41,55,.10); }
        h1 { margin:0 0 10px; font-size:24px; }
        p { margin:8px 0; line-height:1.6; color:#667085; }
        code { display:block; margin-top:8px; padding:10px 12px; border:1px solid #e4e7ec; border-radius:8px; background:#f8fafc; color:#344054; white-space:pre-wrap; }
        .count { font-weight:700; color:#344054; }
        @media (prefers-color-scheme:dark) {
            body { background:#111318; color:#f3f4f6; }
            main { background:#191c22; border-color:#303640; }
            p { color:#aab2bf; }
            code { background:#111318; border-color:#303640; color:#e5e7eb; }
            .count { color:#e5e7eb; }
        }
    </style>
</head>
<body>
<main role="status">
    <h1>Требуется обновление базы данных</h1>
    <p>Файлы приложения уже обновлены, но схема базы данных ещё относится к предыдущей версии.</p>
    <p class="count">Обнаружено несоответствий: {$missingCount}.</p>
    <p>Перед продолжением сделайте резервную копию БД и выполните из корня Workspace:</p>
    <code>php bin/migrate.php --status
php bin/migrate.php
php bin/healthcheck.php --json</code>
    <p>После успешной миграции просто обновите страницу. Установщик запускать не нужно.</p>
</main>
</body>
</html>
HTML;
    exit;
}

function handleMaintenanceMode(array $state): never
{
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 60');

    $reason = trim((string) ($state['reason'] ?? ''));
    if ($reason === '' || !($state['valid'] ?? false)) {
        $reason = 'Выполняется техническое обслуживание приложения.';
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'maintenance_mode',
            'message' => $reason,
            'retry_after' => 60,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cspNonce = htmlspecialchars(\Core\SecurityHeaders::nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Техническое обслуживание</title>
    <style nonce="{$cspNonce}">
        :root { color-scheme: light; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; background:#f4f6f9; color:#1f2937; }
        main { width:min(620px,100%); padding:28px; background:#fff; border:1px solid #dce2e9; border-radius:16px; box-shadow:0 18px 48px rgba(31,41,55,.10); }
        h1 { margin:0 0 10px; font-size:24px; } p { margin:0; line-height:1.6; color:#667085; }
    </style>
</head>
<body><main role="status"><h1>Техническое обслуживание</h1><p>{$safeReason}</p><p>Повторите попытку через минуту.</p></main></body>
</html>
HTML;
    exit;
}

try {
    // Maintenance must be observable before any database/module bootstrap. During
    // an update the database may be intentionally unavailable or mid-migration.
    require_once SITEPATH . '/core/Environment.php';
    \Core\Environment::load(SITEPATH . '/.env');
    require_once SITEPATH . '/app/services/MaintenanceModeService.php';
    $maintenanceState = (new \App\Services\MaintenanceModeService())->state();
    if ($maintenanceState['active']) {
        handleMaintenanceMode($maintenanceState);
    }

    require_once SITEPATH . '/core/ModuleManifest.php';
    require_once SITEPATH . '/core/DatabaseOwnership.php';
    require_once SITEPATH . '/core/SchemaReadiness.php';
    $schemaState = \Core\SchemaReadiness::inspect(SITEPATH);
    if (!$schemaState['ready']) {
        handleSchemaUpgradeRequired($schemaState);
    }

    require_once SITEPATH . '/core.php';
} catch (Throwable $e) {
    $exceptionClass = $e::class;
    $incidentId = substr(
        hash('sha256', microtime(true) . ':' . getmypid() . ':' . $exceptionClass . ':' . $e->getMessage()),
        0,
        16
    );
    error_log("Workspace bootstrap failed [{$incidentId}] {$exceptionClass}: {$e->getMessage()}");
    handleStartupError(
        "Не удалось безопасно запустить приложение. Код ошибки: {$incidentId}. Подробности записаны в server error log.",
        'Configuration Error'
    );
}

require_once SITEPATH . '/core/Router.php';
$router = \Core\Router::getInstance();
$router->addGlobalMiddleware(\App\Middlewares\EnforceMaintenanceMode::class);
$router->add('GET', '/module-assets', [\Core\ModuleAssetController::class, 'serve'], [], 'module_asset');
require_once SITEPATH . '/core/routerConfig.php';
\Core\ModuleRuntimeLoader::getInstance()->registerRoutes($router);
$router->dispatch();
