<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateWebRuntimeLauncher.php';
require_once dirname(__DIR__, 2) . '/core/UpdateWebContinuation.php';
require_once dirname(__DIR__, 2) . '/core/UpdateTransactionJournal.php';
require_once dirname(__DIR__, 2) . '/core/UpdateFileMutator.php';
require_once dirname(__DIR__, 2) . '/app/services/MaintenanceModeService.php';

use Core\UpdateExternalRuntime;
use Core\UpdateWebRuntimeLauncher;
use Core\UpdateWebContinuation;
use Core\UpdateTransactionJournal;
use Core\UpdatePath;

function webExternalAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$temp = sys_get_temp_dir() . '/notes-external-web-' . bin2hex(random_bytes(6));
$app = $temp . '/app';
$private = $temp . '/private';
$state = $private . '/updates';
foreach ([$app . '/core', $state] as $dir) mkdir($dir, 0700, true);
$server = null;
try {
    putenv('PRIVATE_STORAGE_PATH=' . $private);
    putenv('BASE_PATH=/workspace');
    putenv('SITEURL=http://127.0.0.1');
    putenv('UPDATE_CREDENTIALS_FILE=');
    $runtime = (new UpdateExternalRuntime(dirname(__DIR__, 2)))->prepare();
    $transaction = 'external-web-contract-001';
    $token = (new UpdateWebContinuation($state))->create($transaction);
    $journal = new UpdateTransactionJournal($state, $app);
    $data = $journal->initialize([
        'transaction_id' => $transaction,
        'installed_version' => '1.0.14', 'installed_version_code' => 10014,
        'target_version' => '1.0.15', 'target_version_code' => 10015,
        'package_sha256' => str_repeat('a', 64), 'stage_dir' => $temp,
    ]);
    // Терминальная фикстура проверяет независимость HTTP-входа от любого live PHP.
    $data['state'] = 'rollback_verified';
    file_put_contents($journal->path($transaction), json_encode($data, JSON_THROW_ON_ERROR));
    file_put_contents($app . '/.env', 'PRIVATE_STORAGE_PATH=' . $private . "\n");
    $runtimeManifest = json_decode((string) file_get_contents($runtime['manifest']), true, 32, JSON_THROW_ON_ERROR);
    foreach ($runtimeManifest['files'] as $relative => $metadata) {
        if (!is_dir(dirname($app . '/' . $relative))) mkdir(dirname($app . '/' . $relative), 0755, true);
        copy($runtime['runtime_root'] . '/' . $relative, $app . '/' . $relative);
    }
    file_put_contents($app . '/legacy-step.php', <<<'PHP'
<?php
require __DIR__ . '/core/UpdateWebTransaction.php';
$result = (new Core\UpdateWebTransaction(__DIR__))->step(
    $_SERVER['HTTP_X_WORKSPACE_UPDATE_TRANSACTION'], $_SERVER['HTTP_X_WORKSPACE_UPDATE_TOKEN']);
header('Content-Type: application/json');
echo json_encode(['success' => true, 'result' => $result]);
PHP);
    file_put_contents($app . '/index.php', '<?php throw new RuntimeException("LIVE_INDEX_LOADED");');
    $url = (new UpdateWebRuntimeLauncher())->publish($app, $state, $transaction, $runtime);
    webExternalAssert(str_starts_with($url, '/workspace/update-continuations/'), 'BASE_PATH потерян');
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    webExternalAssert(is_resource($listener), 'Нет свободного HTTP-порта');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    putenv('E2E_APP_ROOT=' . $app);
    $server = proc_open([
        PHP_BINARY, '-d', 'disable_functions=proc_open,popen,exec,shell_exec,system,passthru,opcache_reset',
        '-d', 'memory_limit=32M', '-S', $address, '-t', $app,
        dirname(__DIR__) . '/e2e/router.php',
    ], [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
    webExternalAssert(is_resource($server), 'HTTP-сервер не запущен');
    for ($i = 0; $i < 100; ++$i) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($socket)) { fclose($socket); break; }
        usleep(20000);
    }
    $request = static function (string $method, string $credential, string $origin = '', string $requestPath = '') use ($address, $url, $transaction): array {
        $headers = "X-Workspace-Update-Transaction: {$transaction}\r\nX-Workspace-Update-Token: {$credential}\r\n";
        if ($origin !== '') $headers .= "Origin: {$origin}\r\n";
        $context = stream_context_create(['http' => [
            'method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $body = file_get_contents('http://' . $address . ($requestPath ?: $url), false, $context);
        return ['status' => $http_response_header[0] ?? '', 'body' => json_decode((string) $body, true)];
    };
    $handoff = $request('POST', $token, '', '/legacy-step.php');
    webExternalAssert(($handoff['body']['result']['continuation_url'] ?? '') === $url,
        'Старый контроллер не передаёт продолжение внешнему runtime: ' . json_encode($handoff));
    webExternalAssert(!file_exists($state . '/maintenance.json'), 'Перед handoff уже включён maintenance');
    // Обычный запрос во время частичного switch не должен загружать live
    // updater: часть новых зависимостей могла ещё не попасть в live-tree.
    $sourceRoot = dirname(__DIR__, 2);
    foreach (['index.php', 'core/SecurityHeaders.php', 'core/CrawlerDefense.php',
        'core/RequestOrigin.php', 'core/SecurityEventLog.php', 'core/UpdateBootRecoveryGate.php',
        'app/services/RequestRateLimiter.php'] as $relative) {
        copy($sourceRoot . '/' . $relative, $app . '/' . $relative);
    }
    file_put_contents($app . '/core/UpdateAutomaticRecovery.php', '<?php throw new RuntimeException("LIVE_RECOVERY_LOADED");');
    file_put_contents($app . '/core/UpdateWebHttpBridge.php', '<?php throw new RuntimeException("LIVE_BRIDGE_LOADED");');
    unlink($app . '/core/CrawlerDefense.php');
    file_put_contents($app . '/core/SupportDiagnostics.php', '<?php throw new RuntimeException("LIVE_DIAGNOSTICS_LOADED");');
    $data['state'] = 'live_mutation_started';
    file_put_contents($journal->path($transaction), json_encode($data, JSON_THROW_ON_ERROR));
    $maintenance = new App\Services\MaintenanceModeService($state, $app);
    $maintenance->enter($transaction);
    webExternalAssert(str_contains($request('GET', '', '', '/index.php')['status'], '503'),
        'Обычный запрос загрузил частично переключённый live updater');
    $maintenance->leave($transaction);
    $data['state'] = 'rollback_verified';
    file_put_contents($journal->path($transaction), json_encode($data, JSON_THROW_ON_ERROR));
    foreach ($runtimeManifest['files'] as $relative => $metadata) {
        if (str_starts_with($relative, 'core/')) {
            file_put_contents($app . '/' . $relative, '<?php throw new RuntimeException("LIVE_CORE_LOADED");');
        }
    }
    webExternalAssert(str_contains($request('GET', $token)['status'], '403'), 'Принят GET');
    webExternalAssert(str_contains($request('POST', 'wrong')['status'], '403'), 'Принят чужой токен');
    webExternalAssert(str_contains($request('POST', $token, 'https://evil.invalid')['status'], '403'), 'Принят чужой Origin');
    $result = $request('POST', $token, 'http://127.0.0.1');
    webExternalAssert(($result['body']['result']['status'] ?? '') === 'recovered',
        'Внешний HTTP-вход не работает без live-классов: ' . json_encode($result) . file_get_contents($temp . '/server.log'));
    webExternalAssert(str_contains($request('POST', $token)['status'], '403'), 'Отозванный токен принят');
    $releaseFiles = (new Core\UpdateFileMutator($app, ['update-continuations']))->releaseFiles();
    webExternalAssert(!array_filter(array_keys($releaseFiles), static fn ($path) => str_starts_with($path, 'update-continuations/')),
        'HTTP-вход попал в переключаемые файлы');
    // Повреждение даже не загруженного runtime-файла должно останавливать вход.
    $token = (new UpdateWebContinuation($state))->create($transaction);
    file_put_contents($runtime['runtime_root'] . '/core/UpdateApplyCommand.php', "\n// tamper", FILE_APPEND);
    webExternalAssert(str_contains($request('POST', $token)['status'], '500'), 'Повреждённый runtime принят');
    echo "[OK] Внешний HTTP updater: live PHP недоступен, process API запрещены, BASE_PATH, origin, capability и tamper\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    UpdatePath::removeTree($temp);
}
