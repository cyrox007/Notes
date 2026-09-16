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
            <span class="admin-page__eyebrow">Signed updater</span>
            <h1>Обновления Workspace</h1>
            <p>Проверка подписанного канала и безопасная загрузка пакета во внешний staging. Эта страница не запускает maintenance, миграции или live apply.</p>
        </div>
        <div class="admin-user-actions">
            <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('admin_settings')) ?>">Системные настройки</a>
            <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>">Админпанель</a>
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
                <p>Эти данные читаются локально. Сеть используется только после явного запуска проверки.</p>
            </div>
            <?php if ($canCheck): ?>
                <a class="admin-action admin-action--primary" href="<?= $view->e($view->route('admin_updates_check')) ?>">
                    <i class="fa fa-refresh" aria-hidden="true"></i> Проверить обновления
                </a>
            <?php else: ?>
                <button class="admin-action admin-action--primary" type="button" disabled>Проверка недоступна</button>
            <?php endif; ?>
        </div>

        <div class="custom-field__grid">
            <div class="custom-field__control"><label>Установленная версия</label><strong><?= $view->e($state['installed_version'] ?? '—') ?> (<?= $view->e($state['installed_version_code'] ?? '—') ?>)</strong></div>
            <div class="custom-field__control"><label>Канал</label><strong><?= $view->e($state['channel'] ?? '—') ?></strong></div>
            <div class="custom-field__control"><label>Signed feed</label><strong><?= $view->e(($state['feed_label'] ?? '') !== '' ? $state['feed_label'] : 'Не настроен') ?></strong></div>
            <div class="custom-field__control"><label>Update trust root</label><strong><?= !empty($state['trust_configured']) ? 'Настроен' : 'Не настроен' ?></strong></div>
            <div class="custom-field__control"><label>HTTPS runtime</label><strong><?= !empty($state['openssl_available']) ? 'OpenSSL доступен' : 'OpenSSL недоступен' ?></strong></div>
            <div class="custom-field__control"><label>External staging</label><strong><?= !empty($state['stage_configured']) ? 'Настроен' : 'Не настроен' ?></strong></div>
        </div>

        <?php if ($trustedKeys !== []): ?>
            <p><strong>Доверенные update key ID:</strong> <?= $view->e(implode(', ', array_map('strval', $trustedKeys))) ?></p>
        <?php endif; ?>

        <?php if ($issues !== []): ?>
            <div class="admin-page__flash admin-page__flash--error" role="status">
                <strong>Updater пока не готов:</strong>
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
                    <span class="admin-panel-card__kicker"><?= ($result['kind'] ?? '') === 'stage' ? 'Verified staging' : 'Signed feed result' ?></span>
                    <h2 id="updates-result-title"><?= $view->e($statusLabels[$resultStatus] ?? $resultStatus) ?></h2>
                    <p>Результат относится к подписанному manifest. Feed сам по себе не является источником доверия.</p>
                </div>
            </div>

            <div class="custom-field__grid">
                <div class="custom-field__control"><label>Целевая версия</label><strong><?= $view->e($result['target_version'] ?? '—') ?> (<?= $view->e($result['target_version_code'] ?? '—') ?>)</strong></div>
                <div class="custom-field__control"><label>Канал</label><strong><?= $view->e($result['channel'] ?? '—') ?></strong></div>
                <div class="custom-field__control"><label>Ключ подписи</label><strong><?= $view->e($result['key_id'] ?? '—') ?></strong></div>
                <div class="custom-field__control"><label>Source commit</label><strong><code><?= $view->e($result['source_commit'] ?? '—') ?></code></strong></div>
                <?php if (($result['kind'] ?? '') === 'check'): ?>
                    <div class="custom-field__control"><label>Пакет</label><strong><?= $view->e($result['package_filename'] ?? '—') ?></strong></div>
                    <div class="custom-field__control"><label>Размер</label><strong><?= $view->e($formatBytes($result['package_size'] ?? 0)) ?></strong></div>
                    <div class="custom-field__control"><label>Требуемый PHP</label><strong><?= $view->e($result['requires_php'] ?? '—') ?>+</strong></div>
                    <div class="custom-field__control"><label>Минимальная исходная версия</label><strong><?= $view->e($result['min_source_version_code'] ?? '—') ?></strong></div>
                <?php else: ?>
                    <div class="custom-field__control"><label>ZIP entries</label><strong><?= $view->e($result['archive_entries'] ?? 0) ?></strong></div>
                    <div class="custom-field__control"><label>ZIP files</label><strong><?= $view->e($result['archive_files'] ?? 0) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (($result['package_sha256'] ?? '') !== ''): ?>
                <p><strong>SHA-256:</strong> <code><?= $view->e($result['package_sha256']) ?></code></p>
            <?php endif; ?>

            <?php if (($result['notes'] ?? null) !== null && trim((string) $result['notes']) !== ''): ?>
                <p><strong>Release notes:</strong> <?= nl2br($view->e((string) $result['notes'])) ?></p>
            <?php endif; ?>

            <?php if (($result['compatibility_message'] ?? null) !== null && trim((string) $result['compatibility_message']) !== ''): ?>
                <div class="admin-page__flash admin-page__flash--error" role="status">
                    <?= $view->e((string) $result['compatibility_message']) ?>
                </div>
            <?php endif; ?>

            <?php if (($result['kind'] ?? '') === 'stage'): ?>
                <div class="admin-page__flash admin-page__flash--success" role="status">
                    Пакет прошёл подпись, signed size/SHA-256, ZIP audit и опубликован в immutable external staging. Live-файлы не менялись.
                </div>
            <?php endif; ?>

            <?php if ($checkedUpdateAvailable): ?>
                <?php if ($canStage): ?>
                    <form action="<?= $view->e($view->route('admin_updates_stage')) ?>" method="post" class="custom-fields-form" onsubmit="return confirm('Скачать подписанный пакет и поместить его во внешний staging? Рабочая версия приложения изменена не будет.');">
                        <?= $view->csrfInput() ?>
                        <div class="custom-fields-form__footer">
                            <small>Действие только скачивает, повторно проверяет и сохраняет пакет. Maintenance, backup, миграции и live apply здесь не запускаются.</small>
                            <button class="admin-action admin-action--primary" type="submit"><i class="fa fa-download" aria-hidden="true"></i> Проверить и подготовить пакет</button>
                        </div>
                    </form>
                <?php elseif (!$canManageStage): ?>
                    <p>Проверка доступна, но installation-wide staging разрешён только суперадминистратору.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Safety boundary</span>
                <h2>Что эта страница не делает</h2>
                <p>Admin UI пока намеренно не пересекает destructive boundary.</p>
            </div>
        </div>
        <ul>
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
], $content);
