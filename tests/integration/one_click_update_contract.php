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

echo "[OK] автоматическое уведомление, одношаговое обновление и автовосстановление закреплены контрактом\n";
