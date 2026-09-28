<?php

declare(strict_types=1);

use Core\UpdateWebContinuation;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateWebContinuation.php';

function updaterWebModeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] web-updater contract: {$message}\n");
        exit(1);
    }
}

function updaterWebModeRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $current = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($current) && !is_link($current)) {
            updaterWebModeRemoveTree($current);
            continue;
        }
        @unlink($current);
    }
    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/notes-web-updater-' . bin2hex(random_bytes(6));
updaterWebModeAssert(mkdir($temp, 0700, true), 'не удалось создать временный state-root');

try {
    $transactionId = 'web-update-contract-01';
    $continuation = new UpdateWebContinuation($temp);
    $token = $continuation->create($transactionId);

    updaterWebModeAssert(strlen($token) >= 40, 'capability-токен слишком короткий');
    updaterWebModeAssert($continuation->verify($transactionId, $token), 'валидный capability-токен отклонён');
    updaterWebModeAssert(!$continuation->verify($transactionId, $token . 'x'), 'неверный capability-токен принят');
    updaterWebModeAssert($continuation->active($transactionId), 'живой lease web-updater не распознан');
    updaterWebModeAssert(
        $continuation->verifyAndRenew($transactionId, $token),
        'lease web-updater не продлевается'
    );

    $statePath = $temp . '/web-continuations/' . $transactionId . '.json';
    $stateBytes = (string) file_get_contents($statePath);
    updaterWebModeAssert($stateBytes !== '', 'файл capability-состояния не создан');
    updaterWebModeAssert(
        !str_contains($stateBytes, $token),
        'сырой capability-токен записан на диск'
    );
    updaterWebModeAssert(
        str_contains($stateBytes, hash('sha256', $token)),
        'на диске отсутствует SHA-256 capability-токена'
    );

    $continuation->revoke($transactionId);
    updaterWebModeAssert(!$continuation->active($transactionId), 'отозванный capability lease остался активным');

    $readiness = (string) file_get_contents($root . '/core/UpdateReadiness.php');
    $readyExpression = '';
    if (preg_match('/\\$readyForApply\\s*=\\s*(.*?);/s', $readiness, $readyMatch) === 1) {
        $readyExpression = (string) ($readyMatch[1] ?? '');
    }
    updaterWebModeAssert(
        $readyExpression !== ''
            && !str_contains($readyExpression, '$procOpen')
            && !str_contains($readyExpression, '$phpCliReady'),
        'proc_open или PHP CLI по-прежнему обязательны для ready_for_apply'
    );
    updaterWebModeAssert(
        str_contains($readiness, "'install_mode' =>")
            && str_contains($readiness, "'process_mode_available' =>"),
        'готовность updater не публикует автоматически выбранный режим'
    );

    $runner = (string) file_get_contents($root . '/core/UpdateInProcessRunner.php');
    updaterWebModeAssert(
        str_contains($runner, 'implements UpdateCommandRunner'),
        'совместимый runner не использует общую границу выполнения'
    );
    foreach (['proc_open(', 'shell_exec(', 'exec(', 'system(', 'passthru(', 'popen('] as $forbidden) {
        updaterWebModeAssert(
            !str_contains($runner, $forbidden),
            'совместимый runner использует запрещённый process API: ' . $forbidden
        );
    }

    $apply = (string) file_get_contents($root . '/core/UpdateApplyCommand.php');
    updaterWebModeAssert(
        str_contains($apply, 'private UpdateCommandRunner $processRunner')
            && str_contains($apply, '?UpdateCommandRunner $processRunner = null'),
        'UpdateApplyCommand остаётся жёстко связан с process runner'
    );

    $recovery = (string) file_get_contents($root . '/core/UpdateAutomaticRecovery.php');
    updaterWebModeAssert(
        str_contains($recovery, 'UpdateWebContinuation')
            && str_contains($recovery, 'new UpdateInProcessRunner()')
            && str_contains($recovery, "'web_update_in_progress'"),
        'автоматический recovery не учитывает живой web-updater или отсутствие proc_open'
    );

    $bridge = (string) file_get_contents($root . '/core/UpdateWebHttpBridge.php');
    updaterWebModeAssert(
        str_contains($bridge, "private const STEP_SUFFIX = '/admin/updates/web-step'")
            && str_contains($bridge, 'HTTP_X_WORKSPACE_UPDATE_TOKEN')
            && str_contains($bridge, 'HTTP_X_WORKSPACE_UPDATE_TRANSACTION')
            && str_contains($bridge, 'sameOrigin()'),
        'ранний maintenance-мост недостаточно ограничен'
    );

    $index = (string) file_get_contents($root . '/index.php');
    $bridgePos = strpos($index, 'UpdateWebHttpBridge::canHandle');
    $recoveryPos = strpos($index, 'UpdateBootRecoveryGate::enforce');
    updaterWebModeAssert(
        $bridgePos !== false && $recoveryPos !== false && $bridgePos < $recoveryPos,
        'защищённый web-step не обрабатывается до автоматического recovery'
    );

    $service = (string) file_get_contents($root . '/modules/admin/services/AdminUpdateService.php');
    updaterWebModeAssert(
        str_contains($service, 'public function beginWebApply')
            && str_contains($service, 'public function stepWebApply')
            && str_contains($service, 'applyWebSynchronously'),
        'AdminUpdateService не содержит автоматический web-путь и fallback без JavaScript'
    );

    $routes = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
    updaterWebModeAssert(
        str_contains($routes, "'/updates/web-start'")
            && str_contains($routes, "'/updates/web-step'")
            && str_contains(
                $routes,
                "[LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_web_start'"
            )
            && str_contains(
                $routes,
                "[LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_web_step'"
            ),
        'start/step маршруты web-updater потеряли авторизацию или CSRF'
    );

    $view = (string) file_get_contents($root . '/modules/admin/views/updates.php');
    updaterWebModeAssert(
        str_contains($view, 'Совместимый с хостингом')
            && str_contains($view, "route('admin_updates_web_start')")
            && str_contains($view, "route('admin_updates_web_step')")
            && str_contains($view, 'proc_open')
            && str_contains($view, 'не требуются'),
        'Admin UI не объясняет автоматический совместимый режим'
    );

    $javascript = (string) file_get_contents($root . '/modules/admin/assets/admin-updates.js');
    updaterWebModeAssert(
        str_contains($javascript, 'X-Workspace-Update-Token')
            && str_contains($javascript, 'X-Workspace-Update-Transaction')
            && str_contains($javascript, 'MAX_TRANSIENT_RETRIES')
            && str_contains($javascript, "result.status || '') === 'in_progress'"),
        'браузерный updater не содержит автоматический цикл продолжения'
    );

    echo "[OK] совместимый web-updater не зависит от proc_open и PHP CLI\n";
} finally {
    updaterWebModeRemoveTree($temp);
}
