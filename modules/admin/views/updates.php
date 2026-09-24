<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$state = isset($update_state) && is_array($update_state) ? $update_state : [];
$result = isset($update_result) && is_array($update_result) ? $update_result : null;
$flash = isset($updates_flash) && is_array($updates_flash) ? $updates_flash : null;
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$issues = isset($state['issues']) && is_array($state['issues']) ? $state['issues'] : [];
$trustedKeys = isset($state['trusted_key_ids']) && is_array($state['trusted_key_ids']) ? $state['trusted_key_ids'] : [];
$canCheck = !empty($state['can_check']);
$canStage = !empty($state['can_stage']);
$canManageStage = !empty($state['can_manage_stage']);
$operatorReady = !empty($state['operator_ready']);
$updateAccessReady = !empty($state['update_access_ready']);
$updateAccessAutomatic = !empty($state['update_access_automatic']);
$updateAccessMode = (string) ($state['update_access_mode'] ?? 'auto');
$updateAccessLabel = $updateAccessReady
    ? 'Готов'
    : ($updateAccessAutomatic ? 'Настроится автоматически' : ($updateAccessMode === 'offline' ? 'Отключён' : 'Не готов'));
$operatorIssues = isset($state['operator_issues']) && is_array($state['operator_issues']) ? $state['operator_issues'] : [];
$operatorCommand = isset($state['operator_command']) ? (string) $state['operator_command'] : 'php bin/update_run.php --yes --json';
$doctorCommand = isset($state['doctor_command']) ? (string) $state['doctor_command'] : 'php bin/update_doctor.php --json';
$checkedUpdateAvailable = $result !== null
    && ($result['kind'] ?? '') === 'check'
    && ($result['status'] ?? '') === 'update_available'
    && !empty($result['update_available']);
$formatBytes = static function (mixed $bytes): string {
    $value = max(0, (int) $bytes);
    if ($value >= 1073741824) {
        return round($value / 1073741824, 2) . ' ГБ';
    }
    if ($value >= 1048576) {
        return round($value / 1048576, 2) . ' МБ';
    }
    if ($value >= 1024) {
        return round($value / 1024, 2) . ' КБ';
    }
    return $value . ' Б';
};
$statusLabels = [
    'update_available' => 'Доступно обновление',
    'up_to_date' => 'Актуальная версия',
    'ahead_of_feed' => 'Установка новее feed',
    'update_incompatible' => 'Обновление несовместимо',
    'staged' => 'Пакет подготовлен',
];

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Обновления</span>
            <h1>Обновления Workspace</h1>
            <p>Проверка подписанного канала и безопасная подготовка обновления. Доступ к серверу обновлений настраивается автоматически по действующей лицензии.</p>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status">
            <?= $view->e($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <section class="admin-panel-card" aria-labelledby="updates-local-state-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Локальное состояние</span>
                <h2 id="updates-local-state-title">Установка и канал</h2>
                <p>Основные параметры определяются автоматически. Сеть используется только после запуска проверки обновлений.</p>
            </div>
            <?php if ($canCheck): ?>
                <a class="admin-action admin-action--primary" href="<?= $view->e($view->route('admin_updates_check')) ?>">
                    <i class="fa fa-refresh" aria-hidden="true"></i> Проверить обновления
                </a>
            <?php else: ?>
                <button class="admin-action admin-action--primary" type="button" disabled>Проверка недоступна</button>
            <?php endif; ?>
        </div>

        <div class="admin-status-grid">
            <div class="admin-status-card"><label>Установленная версия</label><strong><?= $view->e($state['installed_version'] ?? '—') ?> (<?= $view->e($state['installed_version_code'] ?? '—') ?>)</strong></div>
            <div class="admin-status-card"><label>Канал</label><strong><?= $view->e($state['channel'] ?? '—') ?></strong></div>
            <div class="admin-status-card"><label>Канал обновлений</label><strong><?= $view->e(($state['feed_label'] ?? '') !== '' ? $state['feed_label'] : 'Не настроен') ?></strong></div>
            <div class="admin-status-card"><label>Доступ к обновлениям</label><strong><?= $view->e($updateAccessLabel) ?></strong></div>
            <div class="admin-status-card"><label>Проверка подписи</label><strong><?= !empty($state['trust_configured']) ? 'Настроена' : 'Не настроена' ?></strong></div>
            <div class="admin-status-card"><label>HTTPS</label><strong><?= !empty($state['openssl_available']) ? 'Доступен' : 'Недоступен' ?></strong></div>
            <div class="admin-status-card"><label>Подготовка пакета</label><strong><?= !empty($state['stage_configured']) ? 'Готова' : 'Не настроена' ?></strong></div>
            <div class="admin-status-card"><label>Установка</label><strong><?= $operatorReady ? 'Готова' : 'Требует настройки' ?></strong></div>
        </div>

        <?php if ($trustedKeys !== []): ?>
            <p class="admin-update-keys"><strong>Доверенные ключи обновлений:</strong> <?= $view->e(implode(', ', array_map('strval', $trustedKeys))) ?></p>
        <?php endif; ?>

        <?php if ($issues !== []): ?>
            <div class="admin-page__flash admin-page__flash--error admin-update-alert" role="status">
                <strong>Обновлятор пока не готов:</strong>
                <ul>
                    <?php foreach ($issues as $issue): ?>
                        <li><?= $view->e((string) $issue) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($result !== null): ?>
        <?php $resultStatus = (string) ($result['status'] ?? 'unknown'); ?>
        <section class="admin-panel-card" aria-labelledby="updates-result-title">
            <div class="admin-panel-card__header">
                <div>
                    <span class="admin-panel-card__kicker"><?= ($result['kind'] ?? '') === 'stage' ? 'Проверенный пакет' : 'Результат проверки канала' ?></span>
                    <h2 id="updates-result-title"><?= $view->e($statusLabels[$resultStatus] ?? $resultStatus) ?></h2>
                    <p>Результат подтверждён подписью манифеста. Сам канал используется только для доставки указателей.</p>
                </div>
            </div>

            <div class="admin-status-grid">
                <div class="admin-status-card"><label>Целевая версия</label><strong><?= $view->e($result['target_version'] ?? '—') ?> (<?= $view->e($result['target_version_code'] ?? '—') ?>)</strong></div>
                <div class="admin-status-card"><label>Канал</label><strong><?= $view->e($result['channel'] ?? '—') ?></strong></div>
                <div class="admin-status-card"><label>Ключ подписи</label><strong><?= $view->e($result['key_id'] ?? '—') ?></strong></div>
                <div class="admin-status-card"><label>Исходный commit</label><strong><code><?= $view->e($result['source_commit'] ?? '—') ?></code></strong></div>
                <?php if (($result['kind'] ?? '') === 'check'): ?>
                    <div class="admin-status-card"><label>Пакет</label><strong><?= $view->e($result['package_filename'] ?? '—') ?></strong></div>
                    <div class="admin-status-card"><label>Размер</label><strong><?= $view->e($formatBytes($result['package_size'] ?? 0)) ?></strong></div>
                    <div class="admin-status-card"><label>Требуемый PHP</label><strong><?= $view->e($result['requires_php'] ?? '—') ?>+</strong></div>
                    <div class="admin-status-card"><label>Минимальная исходная версия</label><strong><?= $view->e($result['min_source_version_code'] ?? '—') ?></strong></div>
                <?php else: ?>
                    <div class="admin-status-card"><label>Записей в ZIP</label><strong><?= $view->e($result['archive_entries'] ?? 0) ?></strong></div>
                    <div class="admin-status-card"><label>Файлов в ZIP</label><strong><?= $view->e($result['archive_files'] ?? 0) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (($result['package_sha256'] ?? '') !== ''): ?>
                <p class="admin-update-detail"><strong>SHA-256</strong><code><?= $view->e($result['package_sha256']) ?></code></p>
            <?php endif; ?>

            <?php if (($result['notes'] ?? null) !== null && trim((string) $result['notes']) !== ''): ?>
                <div class="admin-update-detail"><strong>Release notes</strong><div><?= nl2br($view->e((string) $result['notes'])) ?></div></div>
            <?php endif; ?>

            <?php if (($result['compatibility_message'] ?? null) !== null && trim((string) $result['compatibility_message']) !== ''): ?>
                <div class="admin-page__flash admin-page__flash--error admin-update-alert" role="status">
                    <?= $view->e((string) $result['compatibility_message']) ?>
                </div>
            <?php endif; ?>

            <?php if (($result['kind'] ?? '') === 'stage'): ?>
                <div class="admin-page__flash admin-page__flash--success admin-update-alert" role="status">
                    Пакет прошёл проверку подписи, размера, SHA-256 и структуры ZIP и сохранён во внешнем неизменяемом staging. Рабочие файлы не менялись.
                </div>
            <?php endif; ?>

            <?php if ($checkedUpdateAvailable): ?>
                <?php if ($canStage): ?>
                    <form action="<?= $view->e($view->route('admin_updates_stage')) ?>" method="post" class="custom-fields-form" data-confirm-message="Скачать подписанный пакет и поместить его во внешний staging? Рабочая версия приложения изменена не будет." data-confirm-title="Подготовка обновления" data-confirm-danger="false" data-confirm-text="Продолжить">
                        <?= $view->csrfInput() ?>
                        <div class="custom-fields-form__footer">
                            <small>Действие только скачивает, повторно проверяет и сохраняет пакет. Maintenance, backup, миграции и live apply здесь не запускаются.</small>
                            <button class="admin-action admin-action--primary" type="submit"><i class="fa fa-download" aria-hidden="true"></i> Проверить и подготовить пакет</button>
                        </div>
                    </form>
                <?php elseif (!$canManageStage): ?>
                    <p class="admin-update-detail">Проверка доступна, но installation-wide staging разрешён только суперадминистратору.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageStage): ?>
        <section class="admin-panel-card" aria-labelledby="updates-operator-title">
            <div class="admin-panel-card__header">
                <div>
                    <span class="admin-panel-card__kicker">Установка 1.0.2</span>
                    <h2 id="updates-operator-title">Проверка и установка через CLI</h2>
                    <p>Web-интерфейс не меняет рабочий код. Полная установка выполняется одной проверенной CLI-командой с maintenance, резервной копией, кандидатом релиза и возможностью восстановления.</p>
                </div>
            </div>

            <?php if ($operatorReady): ?>
                <div class="admin-page__flash admin-page__flash--success admin-update-alert" role="status">
                    Локальная среда готова к установке. Перед установкой выполните диагностику, затем команду обновления.
                </div>
            <?php else: ?>
                <div class="admin-page__flash admin-page__flash--error admin-update-alert" role="status">
                    <strong>Установка пока не готова:</strong>
                    <ul>
                        <?php foreach ($operatorIssues as $issue): ?>
                            <li><?= $view->e((string) $issue) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="admin-update-detail">
                <strong>Диагностика</strong>
                <code><?= $view->e($doctorCommand) ?></code>
            </div>
            <div class="admin-update-detail">
                <strong>Установка следующего подписанного обновления</strong>
                <code><?= $view->e($operatorCommand) ?></code>
            </div>
            <p class="admin-update-detail">При прерывании после начала live mutation используйте сохранённый transaction id с <code>php bin/update_run.php --recover --transaction=&lt;id&gt; --yes --json</code>. Не удаляйте maintenance marker вручную.</p>
        </section>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Границы безопасности</span>
                <h2>Что эта страница не делает</h2>
                <p>Админ-панель намеренно не выполняет действия, меняющие рабочую версию приложения.</p>
            </div>
        </div>
        <ul class="admin-safety-list">
            <li>не включает maintenance mode;</li>
            <li>не создаёт rollback backup или transaction journal;</li>
            <li>не извлекает release candidate;</li>
            <li>не переключает live-код и не запускает миграции;</li>
            <li>не выполняет apply/recover.</li>
        </ul>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Обновления Workspace',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('admin', 'style.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('admin', 'admin-settings-nav.js'),
    ],
], $content);
