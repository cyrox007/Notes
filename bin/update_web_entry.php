<?php

declare(strict_types=1);

// Шаблон публикуется один раз до maintenance. Никакой live PHP-код не подключается.
$config = /* UPDATE_WEB_CONFIG */ [];
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload) use ($config): never {
    if (empty($payload['success'])) {
        $code = (string) ($payload['error'] ?? 'update_step_failed');
        $messages = [
            'continuation_invalid' => 'Продолжение обновления недействительно или истекло. Повторите проверку обновления.',
            'origin_forbidden' => 'Адрес страницы не совпадает с адресом установки. Откройте обновления по основному адресу сайта.',
        ];
        $payload['message'] = $payload['message'] ?? ($messages[$code]
            ?? 'Внешний шаг обновления завершился ошибкой. Подробная причина записана в приватный журнал.');
        $payload['transaction_id'] = (string) ($config['transaction_id'] ?? '');
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

if ($config === [] || PHP_SAPI === 'cli') $respond(404, ['success' => false]);
$transactionId = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TRANSACTION'] ?? ''));
$token = trim((string) ($_SERVER['HTTP_X_WORKSPACE_UPDATE_TOKEN'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !hash_equals($config['transaction_id'], $transactionId) || $token === '') {
    $respond(403, ['success' => false, 'error' => 'continuation_invalid', 'retryable' => false]);
}
$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($origin !== '') {
    $a = parse_url($origin);
    $b = parse_url($config['site_url']);
    $authority = static function (array|false $parts): string {
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $scheme = strtolower($parts['scheme']);
        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    };
    if ($authority($a) === '' || !hash_equals($authority($b), $authority($a))) {
        $respond(403, ['success' => false, 'error' => 'origin_forbidden', 'retryable' => false]);
    }
}

$runtimeVerified = false;
try {
    $root = $config['runtime_root'];
    $manifestPath = $root . '/runtime.json';
    if (is_link($root) || is_link($manifestPath) || !is_file($manifestPath)
        || !hash_equals($config['manifest_sha256'], hash_file('sha256', $manifestPath))) {
        throw new RuntimeException('Runtime manifest mismatch');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
    foreach ($manifest['files'] as $relative => $file) {
        if (!is_string($relative) || preg_match('~^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[a-zA-Z0-9_./-]+$~D', $relative) !== 1) {
            throw new RuntimeException('Unsafe runtime file');
        }
        $path = $root . '/' . $relative;
        if (is_link($path) || !is_file($path) || filesize($path) !== $file['size']
            || !hash_equals($file['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Runtime file mismatch');
        }
    }
    $runtimeVerified = true;
    require_once $root . '/core/UpdateWebContinuation.php';
    if (!(new \Core\UpdateWebContinuation($config['state_root']))->verify($transactionId, $token)) {
        $respond(403, ['success' => false, 'error' => 'continuation_invalid', 'retryable' => false]);
    }
    require_once $root . '/core/Environment.php';
    \Core\Environment::load($config['app_root'] . '/.env');
    // Привязка к путям запуска сильнее изменяемого окружения установки.
    putenv('UPDATE_STATE_PATH=' . $config['state_root']);
    require_once $root . '/core/UpdateWebTransaction.php';
    $result = (new \Core\UpdateWebTransaction($config['app_root']))->step($transactionId, $token);
    $respond(200, ['success' => true, 'result' => $result]);
} catch (\Core\UpdateWebTransactionException $e) {
    $respond(in_array($e->getCode(), [403, 409], true) ? $e->getCode() : 500, [
        'success' => false, 'error' => $e->safeCode,
        'message' => $e->getMessage(), 'retryable' => $e->safeCode === 'operation_busy',
    ]);
} catch (Throwable $e) {
    error_log('External web updater: ' . $e->getMessage());
    try {
        if (!$runtimeVerified) throw new RuntimeException('Unverified runtime cannot supply diagnostics');
        require_once $config['runtime_root'] . '/core/ServiceLog.php';
        (new \Core\ServiceLog($config['state_root'] . '/http-events.jsonl', $config['app_root']))
            ->record('updater.external_step_failed', 'error', 'updater', [
                'transaction_id' => $config['transaction_id'],
                'error_type' => $e::class, 'message' => $e->getMessage(),
                'file' => $e->getFile(), 'line' => $e->getLine(),
                'executor' => PHP_SAPI, 'platform' => PHP_OS_FAMILY,
            ]);
    } catch (Throwable) { /* Preserve the original error if diagnostics are unavailable. */ }

    $respond(500, ['success' => false, 'error' => 'external_update_step_failed', 'retryable' => true]);
}
