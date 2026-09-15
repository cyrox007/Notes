<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!defined('SITEPATH')) {
    define('SITEPATH', __DIR__);
}

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

    echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$safeTitle}</title>
    <style>
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

try {
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
require_once SITEPATH . '/core/routerConfig.php';
