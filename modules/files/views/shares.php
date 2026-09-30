<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$shareItems = isset($shares) && is_array($shares) ? $shares : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$statusLabels = [
    'active' => 'Активна',
    'expired' => 'Истекла',
    'revoked' => 'Отозвана',
    'missing' => 'Объект удалён',
];

ob_start();
?>
<section class="module-page-header module-page-header--files">
    <div>
        <h1>Общий доступ</h1>
        <p>Все публичные ссылки на файлы и папки в одном месте.</p>
    </div>
    <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($view->route('files')) ?>">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> К файлам
    </a>
</section>

<section class="file-shares">
    <?php if ($shareItems === []): ?>
        <div class="file-manager__empty">
            <i class="fa fa-link" aria-hidden="true"></i>
            <strong>Публичных ссылок пока нет</strong>
            <p>Создайте ссылку из файлового менеджера. Здесь можно будет изменить срок действия или отозвать доступ.</p>
        </div>
    <?php else: ?>
        <div class="file-shares__table-wrap">
            <table class="file-shares__table">
                <thead>
                    <tr>
                        <th>Объект</th>
                        <th>Создана</th>
                        <th>Действует до</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($shareItems as $share): ?>
                    <?php
                        if (!is_array($share)) { continue; }
                        $type = (string) ($share['type'] ?? 'file');
                        $name = (string) ($share['name'] ?? '');
                        $extension = (string) ($share['extension'] ?? '');
                        $displayName = $name . ($type !== 'folder' && $extension !== '' ? '.' . $extension : '');
                        $status = (string) ($share['status'] ?? 'revoked');
                        $url = (string) ($share['share_url'] ?? '');
                        $expiresAt = (string) ($share['expires_at'] ?? '');
                        $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt) : false;
                        $datetimeValue = $expiresTimestamp !== false ? date('Y-m-d\TH:i', $expiresTimestamp) : '';
                    ?>
                    <tr>
                        <td data-label="Объект">
                            <div class="file-shares__object">
                                <i class="fa <?= $type === 'folder' ? 'fa-folder' : 'fa-file-o' ?>" aria-hidden="true"></i>
                                <span><strong><?= $view->e($displayName) ?></strong><small><?= $type === 'folder' ? 'Папка' : 'Файл' ?></small></span>
                            </div>
                        </td>
                        <td data-label="Создана"><?= $view->e($share['created_at'] ?? '') ?></td>
                        <td data-label="Действует до"><?= $expiresAt !== '' ? $view->e($expiresAt) : 'Без срока' ?></td>
                        <td data-label="Статус"><span class="file-shares__status file-shares__status--<?= $view->e($status) ?>"><?= $view->e($statusLabels[$status] ?? $status) ?></span></td>
                        <td data-label="Действия">
                            <div class="file-shares__actions">
                                <?php if ($status === 'active'): ?>
                                    <button type="button" class="file-manager__btn file-manager__btn--secondary" data-copy-share="<?= $view->e($url) ?>">Копировать</button>
                                    <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($url) ?>" target="_blank" rel="noopener noreferrer">Открыть</a>
                                    <form action="<?= $view->e($view->route('files_share_expiry', ['shareId' => (int) ($share['id'] ?? 0)])) ?>" method="post" class="file-shares__expiry">
                                        <?= $view->csrfInput() ?>
                                        <label>
                                            <span class="visually-hidden">Новый срок действия</span>
                                            <input type="datetime-local"
                                                   name="expires_at"
                                                   value="<?= $view->e($datetimeValue) ?>"
                                                   data-expiry-timestamp="<?= $expiresTimestamp !== false ? $view->e((string) $expiresTimestamp) : '' ?>">
                                        </label>
                                        <button type="submit" class="file-manager__btn file-manager__btn--secondary">Срок</button>
                                    </form>
                                    <form action="<?= $view->e($view->route('files_share_revoke', ['shareId' => (int) ($share['id'] ?? 0)])) ?>" method="post" data-revoke-share>
                                        <?= $view->csrfInput() ?>
                                        <button type="submit" class="file-manager__btn file-shares__revoke">Отозвать</button>
                                    </form>
                                <?php else: ?>
                                    <span class="file-shares__muted">Ссылка больше не выдаёт доступ</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Общий доступ',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('files', 'style.css'),
        $view->moduleAsset('files', 'polish.css'),
        $view->moduleAsset('files', 'shares.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('files', 'shares.js'),
    ],
], $content);
