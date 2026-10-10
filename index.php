<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

if (!defined('SITEPATH')) {
    define('SITEPATH', __DIR__);
}

require_once SITEPATH . '/core/SecurityHeaders.php';
\Core\SecurityHeaders::apply();

// Ошибки до загрузки .env пишем во внешний временный журнал. После запуска
// ServiceLog приложение переключается на штатный контур журналирования.
ini_set('error_log', sys_get_temp_dir() . '/workspace-organizer-startup.log');

function startupRequestPath(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) ? $path : '';
}

function handleStartupError(string $message, string $title = 'Системная ошибка'): never
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
        <p class="hint">Проверьте конфигурацию окружения и журнал ошибок сервера. Для новой установки откройте <code>/install.php</code>.</p>
    </main>
</body>
</html>
HTML;
    exit;
}

function isMessengerLongPollRequest(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }

    $path = startupRequestPath();
    return $path !== '' && preg_match('~(?:^|/)messenger/realtime/poll/?$~D', $path) === 1;
}

function handleSuspendedMessengerLongPoll(int $retryAfterMs = 3000): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Workspace-Realtime-Suspended: 1');

    $cursor = trim((string) ($_GET['cursor'] ?? ''));
    if ($cursor !== '' && preg_match('/^[a-f0-9]{64}$/D', $cursor) !== 1) {
        $cursor = '';
    }

    $revisionRaw = trim((string) ($_GET['revision'] ?? ''));
    $revision = ctype_digit($revisionRaw) ? (int) $revisionRaw : null;

    $activityCursor = trim((string) ($_GET['activity_cursor'] ?? ''));
    if ($activityCursor !== '' && preg_match('/^[a-f0-9]{64}$/D', $activityCursor) !== 1) {
        $activityCursor = '';
    }

    echo json_encode([
        'status' => 'ok',
        'transport' => 'long_poll',
        'changed' => false,
        'cursor' => $cursor,
        'revision' => $revision,
        'activity_cursor' => $activityCursor,
        'events' => [],
        'suspended' => true,
        'retry_after_ms' => max(500, min(10000, $retryAfterMs)),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

/** @param array{active:bool,valid:bool,transaction_id:?string,reason:string,started_at:?int,state_path:?string} $state */
function handleMaintenanceMode(array $state): never
{
    if (isMessengerLongPollRequest()) {
        handleSuspendedMessengerLongPoll();
    }

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

function isSupportDiagnosticsRequest(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }

    return preg_match('~(?:^|/)support-diagnostics/?$~D', startupRequestPath()) === 1;
}

function tryHandleSupportDiagnostics(string $root): void
{
    if (!isSupportDiagnosticsRequest()) {
        return;
    }

    $diagnosticsPath = $root . '/core/SupportDiagnostics.php';
    if (!is_file($diagnosticsPath) || is_link($diagnosticsPath)) {
        return;
    }
    if (!is_file($root . '/core/SupportZipWriter.php') || !is_file($root . '/core/HostingProfileProbe.php')) {
        return;
    }

    require_once $diagnosticsPath;
    if (\Core\SupportDiagnostics::canHandleRequest()) {
        \Core\SupportDiagnostics::handleRequest($root);
    }
}

function isUpdateWebStepRequest(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }

    return str_ends_with(rtrim(startupRequestPath(), '/'), '/admin/updates/web-step');
}

/**
 * @param array{active:bool,valid:bool,transaction_id:?string,reason:string,started_at:?int,state_path:?string} $state
 */
function tryHandleUpdateWebStep(
    string $root,
    \App\Services\MaintenanceModeService $maintenance,
    array $state
): void {
    if (!$state['active'] || !$state['valid'] || !isUpdateWebStepRequest()) {
        return;
    }

    require_once $root . '/core/UpdateWebHttpBridge.php';
    if (\Core\UpdateWebHttpBridge::canHandle($maintenance, $state)) {
        \Core\UpdateWebHttpBridge::handle($root, $maintenance, $state);
    }
}

try {
    require_once SITEPATH . '/core/Environment.php';
    \Core\Environment::load(SITEPATH . '/.env');

    require_once SITEPATH . '/core/ServiceLog.php';
    \Core\ServiceLog::registerRuntimeCapture();

    // Диагностика должна оставаться доступной до maintenance/recovery. Проверка
    // наличия файлов защищает пофайловое обновление от временно неполного дерева.
    tryHandleSupportDiagnostics(SITEPATH);

    require_once SITEPATH . '/app/services/MaintenanceModeService.php';
    $maintenance = new \App\Services\MaintenanceModeService();
    $maintenanceState = $maintenance->state();

    // Единственный updater-endpoint, который разрешён до общего maintenance-барьера.
    tryHandleUpdateWebStep(SITEPATH, $maintenance, $maintenanceState);

    // Сначала завершаем восстановление оборванного обновления. Только после
    // этого можно зависеть от новых файлов обычного запуска 1.1.
    require_once SITEPATH . '/core/UpdateBootRecoveryGate.php';
    \Core\UpdateBootRecoveryGate::enforce(SITEPATH);

    $maintenanceState = $maintenance->state();
    if ($maintenanceState['active']) {
        handleMaintenanceMode($maintenanceState);
    }

    require_once SITEPATH . '/core/ApplicationEntryPoint.php';
    \Core\ApplicationEntryPoint::bootstrap(SITEPATH);
} catch (Throwable $e) {
    $exceptionClass = $e::class;
    $incidentId = substr(
        hash('sha256', microtime(true) . ':' . getmypid() . ':' . $exceptionClass . ':' . $e->getMessage()),
        0,
        16
    );

    error_log("Ошибка запуска Workspace [{$incidentId}] {$exceptionClass}: {$e->getMessage()}");
    if (class_exists('Core\\ServiceLog', false)) {
        \Core\ServiceLog::emit(
            'bootstrap.failed',
            'critical',
            'bootstrap',
            [
                'incident_id' => $incidentId,
                'error_type' => $exceptionClass,
                'message' => $e->getMessage(),
            ]
        );
    }

    handleStartupError(
        "Не удалось безопасно запустить приложение. Код ошибки: {$incidentId}. Подробности записаны в журнал ошибок сервера.",
        'Ошибка конфигурации'
    );
}

\Core\ApplicationEntryPoint::dispatch(SITEPATH);
