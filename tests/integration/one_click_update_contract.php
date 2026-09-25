<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function updateNotificationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$header = (string) file_get_contents($root . '/app/views/^shared/header/index.php');
$base = (string) file_get_contents($root . '/app/views/core/base.php');
$script = (string) file_get_contents($root . '/assets/js/update-notifications.js');
$router = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
$controller = (string) file_get_contents($root . '/modules/admin/controllers/UpdateController.php');
$service = (string) file_get_contents($root . '/modules/admin/services/AdminUpdateService.php');
$installer = (string) file_get_contents($root . '/install.php');
$phpCli = (string) file_get_contents($root . '/core/UpdatePhpCli.php');
$updateRun = (string) file_get_contents($root . '/bin/update_run.php');
$updateApply = (string) file_get_contents($root . '/core/UpdateApplyCommand.php');
$updateBackup = (string) file_get_contents($root . '/core/UpdateBackupManager.php');
$automaticRecovery = (string) file_get_contents($root . '/core/UpdateAutomaticRecovery.php');
$coordinatorLock = (string) file_get_contents($root . '/core/UpdateCoordinatorLock.php');
$maintenanceMiddleware = (string) file_get_contents($root . '/app/middlewares/EnforceMaintenanceMode.php');
$bootRecoveryGate = (string) file_get_contents($root . '/core/UpdateBootRecoveryGate.php');
$entrypoint = (string) file_get_contents($root . '/index.php');
$coreBootstrap = (string) file_get_contents($root . '/core.php');
$liveApplyContract = (string) file_get_contents($root . '/tests/integration/updater_live_apply_contract.php');
$adminUpdateE2e = (string) file_get_contents($root . '/.github/workflows/admin-update-e2e.yml');
$rollbackBrowserE2e = (string) file_get_contents($root . '/tests/e2e/admin-update-rollback.mjs');

updateNotificationAssert(
    str_contains($header, 'data-update-notifications'),
    'В общей шапке отсутствует центр уведомлений об обновлениях'
);
updateNotificationAssert(
    str_contains($header, "route('admin_updates_status')"),
    'Центр уведомлений не привязан к фоновой проверке обновлений'
);
updateNotificationAssert(
    str_contains($header, "route('admin_updates_apply_latest')"),
    'В уведомлении отсутствует одношаговая установка последнего релиза'
);
updateNotificationAssert(
    str_contains($header, '$view->csrfInput()'),
    'Форма одношагового обновления потеряла CSRF-защиту'
);
updateNotificationAssert(
    str_contains($base, '/assets/js/update-notifications.js'),
    'Глобальная оболочка не подключает автоматическую проверку обновлений'
);
updateNotificationAssert(
    str_contains($script, 'const CHECK_INTERVAL_MS = 5 * 60 * 1000'),
    'Автоматическая проверка обновлений должна повторяться каждые пять минут'
);
updateNotificationAssert(
    str_contains($script, "fetch(statusUrl"),
    'Клиент уведомлений не выполняет фоновую проверку'
);
updateNotificationAssert(
    str_contains($script, "form.addEventListener('submit'"),
    'Кнопка обновления не фиксирует начало одношаговой установки'
);
updateNotificationAssert(
    str_contains($router, "->add('GET', '/updates/status'"),
    'Маршрут фоновой проверки обновлений отсутствует'
);
updateNotificationAssert(
    str_contains($router, "->add('POST', '/updates/apply-latest'"),
    'Маршрут одношаговой установки отсутствует'
);
updateNotificationAssert(
    str_contains($controller, 'public function status'),
    'Контроллер не публикует безопасный фоновый статус обновления'
);
updateNotificationAssert(
    str_contains($controller, 'public function applyLatest'),
    'Контроллер не поддерживает одношаговую установку'
);
updateNotificationAssert(
    str_contains($service, 'public function applyLatest'),
    'Сервис не выполняет проверку и установку последнего релиза одним действием'
);
updateNotificationAssert(
    str_contains($installer, "'proc_open для автоматических обновлений'"),
    'Установщик не проверяет возможность запуска обновлятора'
);
updateNotificationAssert(
    str_contains($installer, "'PHP CLI для автоматических обновлений'"),
    'Установщик не проверяет доступность PHP CLI'
);
updateNotificationAssert(
    str_contains($installer, "'openssl' => extension_loaded('openssl')"),
    'Установщик не требует openssl для подписанного канала'
);
updateNotificationAssert(
    str_contains($installer, "'zlib' => extension_loaded('zlib')"),
    'Установщик не требует zlib для пакетов обновления'
);
updateNotificationAssert(
    str_contains($phpCli, 'dirname($phpBinary) . DIRECTORY_SEPARATOR . self::cliBinaryName()'),
    'Поиск PHP CLI не проверяет бинарник рядом с фактическим web-PHP'
);
updateNotificationAssert(
    str_contains($updateRun, 'function updateRunAutomaticRecover('),
    'Обновлятор не запускает автоматическое восстановление после ошибки применения'
);
updateNotificationAssert(
    str_contains($updateRun, 'new UpdateTransactionJournal')
        && strpos($updateRun, 'new UpdateTransactionJournal') < strpos($updateRun, '$maintenance->enter('),
    'Журнал транзакции должен создаваться до включения maintenance'
);
updateNotificationAssert(
    str_contains($updateApply, "['initialized', 'backup_verified', 'candidate_verified', 'preflight_verified']"),
    'Recovery не поддерживает аварийный обрыв в самом раннем initialized-состоянии'
);
updateNotificationAssert(
    str_contains($updateBackup, 'MYSQLI_TYPE_JSON')
        && str_contains($updateBackup, 'USING utf8mb4'),
    'Резервная копия БД не восстанавливает JSON как текст utf8mb4'
);
updateNotificationAssert(
    str_contains($updateRun, "'apply_failed_recovered'"),
    'Обновлятор не подтверждает автоматически восстановленную неудачную установку'
);
updateNotificationAssert(
    str_contains($updateRun, 'final class UpdateRunSubprocessException'),
    'Обновлятор теряет машинный результат дочерней команды и не может отличить завершённый откат'
);
updateNotificationAssert(
    str_contains($updateRun, "\$applyErrorCode === 'apply_rolled_back'"),
    'Обновлятор повторно запускает recovery после уже проверенного отката'
);
updateNotificationAssert(
    str_contains($updateRun, "\$recoveryStatus === 'committed_recovery_verified'"),
    'Обновлятор не умеет завершить committed-транзакцию после автоматического снятия maintenance'
);
updateNotificationAssert(
    !str_contains($service, 'Для восстановления выполните: php'),
    'Веб-интерфейс не должен требовать ручную команду восстановления'
);
updateNotificationAssert(
    str_contains($automaticRecovery, 'final class UpdateAutomaticRecovery'),
    'Отсутствует механизм восстановления после гибели updater-процесса'
);
updateNotificationAssert(
    str_contains($automaticRecovery, "'operation_busy'"),
    'Автовосстановление не защищено от вмешательства в живое обновление'
);
updateNotificationAssert(
    str_contains($coordinatorLock, 'final class UpdateCoordinatorLock'),
    'Отсутствует coordinator-lock всей пользовательской операции обновления'
);
updateNotificationAssert(
    str_contains($updateRun, 'new UpdateCoordinatorLock($stateRoot, $transactionId)')
        && strpos($updateRun, 'new UpdateCoordinatorLock($stateRoot, $transactionId)')
            < strpos($updateRun, '$maintenance->enter('),
    'Coordinator-lock не охватывает участок до включения maintenance'
);
updateNotificationAssert(
    str_contains($automaticRecovery, 'new UpdateCoordinatorLock($stateRoot, $transactionId)')
        && str_contains($automaticRecovery, 'UpdateCoordinatorBusyException'),
    'Boot-recovery не проверяет coordinator-lock живого updater'
);
updateNotificationAssert(
    str_contains($automaticRecovery, "'/bin/update_apply.php'")
        && str_contains($automaticRecovery, "'--recover'"),
    'Автовосстановление не использует транзакционный recovery-контур'
);
updateNotificationAssert(
    str_contains($maintenanceMiddleware, '$this->automaticRecovery->attempt($this->maintenance)'),
    'Глобальный maintenance-barrier не запускает recovery после аварийного обрыва'
);
updateNotificationAssert(
    str_contains($maintenanceMiddleware, '$afterRecovery = $this->maintenance->state()'),
    'После recovery middleware не перепроверяет durable maintenance-state'
);
updateNotificationAssert(
    str_contains($bootRecoveryGate, 'final class UpdateBootRecoveryGate')
        && str_contains($bootRecoveryGate, 'UpdateAutomaticRecovery'),
    'Отсутствует ранний recovery до запуска БД и модулей'
);
$bootRecoveryOffset = strpos($entrypoint, '\\Core\\UpdateBootRecoveryGate::enforce(SITEPATH)');
$schemaReadinessOffset = strpos($entrypoint, '\\Core\\SchemaReadiness::inspect(SITEPATH)');
$coreBootstrapOffset = strpos($entrypoint, "require_once SITEPATH . '/core.php';");
updateNotificationAssert(
    $bootRecoveryOffset !== false
        && $schemaReadinessOffset !== false
        && $coreBootstrapOffset !== false
        && $bootRecoveryOffset < $schemaReadinessOffset
        && $bootRecoveryOffset < $coreBootstrapOffset
        && !str_contains($coreBootstrap, 'UpdateBootRecoveryGate::enforce(SITEPATH)'),
    'Ранний recovery должен выполняться в index.php до проверки схемы и запуска core'
);
updateNotificationAssert(
    str_contains($adminUpdateE2e, 'tests/e2e/admin-update-rollback.mjs'),
    'Сквозной релизный тест не запускает браузерную проверку автоматического отката'
);
updateNotificationAssert(
    str_contains($adminUpdateE2e, '1.0.6-broken-e2e')
        && str_contains($adminUpdateE2e, 'намеренный отказ миграции'),
    'Сквозной релизный тест не содержит намеренно падающий подписанный пакет'
);
updateNotificationAssert(
    str_contains($adminUpdateE2e, '1.0.6-health-broken-e2e')
        && str_contains($adminUpdateE2e, 'намеренный отказ post-health'),
    'Сквозной релизный тест не проверяет автоматический откат после ошибки post-health'
);
updateNotificationAssert(
    str_contains($adminUpdateE2e, 'rollback_verified'),
    'Сквозной релизный тест не требует подтверждённый rollback_verified'
);
updateNotificationAssert(
    str_contains($adminUpdateE2e, 'Доказать раннее самовосстановление на следующем HTTP-запросе')
        && str_contains($adminUpdateE2e, 'update-boot-recovery-e2e'),
    'Сквозной релизный тест не доказывает recovery на следующем HTTP-запросе после обрыва процесса'
);
updateNotificationAssert(
    str_contains($rollbackBrowserE2e, 'Рабочая версия автоматически восстановлена и проверена'),
    'Браузерный тест не подтверждает автоматическое восстановление пользователю'
);

updateNotificationAssert(
    str_contains($liveApplyContract, 'crash-recover-001')
        && str_contains($liveApplyContract, "'recover' => true")
        && str_contains($liveApplyContract, "'rollback_verified'"),
    'Не доказано реальное восстановление новой командой после обрыва за destructive boundary'
);

echo "[OK] автоматическое уведомление, одношаговое обновление и автовосстановление закреплены контрактом\n";
