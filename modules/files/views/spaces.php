<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) ? $user : null;
$catalog = isset($catalog) && is_array($catalog) ? $catalog : [];
$space = isset($catalog['space']) && is_array($catalog['space']) ? $catalog['space'] : [];
$parent = isset($catalog['parent']) && is_array($catalog['parent']) ? $catalog['parent'] : null;
$items = isset($catalog['items']) && is_array($catalog['items']) ? $catalog['items'] : [];
$trashItems = isset($trash_items) && is_array($trash_items) ? $trash_items : [];
$rules = isset($acl_rules) && is_array($acl_rules) ? $acl_rules : [];
$roles = isset($available_roles) && is_array($available_roles) ? $available_roles : [];
$groups = isset($available_groups) && is_array($available_groups) ? $available_groups : [];
$users = isset($available_users) && is_array($available_users) ? $available_users : [];
$folderVersion = (int) ($catalog['version'] ?? 1);
$workspaceVersion = isset($version) ? (string) $version : '';
$canCreate = !empty($catalog['can_create']);
$canUpload = !empty($catalog['can_upload']);
$canManage = !empty($catalog['can_manage']);
$folderId = $parent !== null ? (int) ($parent['id'] ?? 0) : null;
$rootLink = $view->route('files_global');
$folderLink = static fn (int $id): string => $rootLink . '?folder=' . $id;
$command = static fn (): string => 'files:web:' . bin2hex(random_bytes(16));

ob_start();
?>
<section class="module-page-header module-page-header--files">
    <div><h1>Общие файлы</h1><p>Общее файловое пространство с правами доступа к каждой папке.</p></div>
</section>
<div class="file-manager file-spaces-page">
    <nav class="file-manager__toolbar" aria-label="Файловые пространства">
        <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($view->route('files')) ?>">Мои файлы</a>
        <span class="file-manager__btn file-manager__btn--primary">Общие файлы</span>
        <?php if (!empty($can_admin_files)): ?>
            <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($view->route('files_admin')) ?>">Управление доступом</a>
        <?php endif; ?>
    </nav>
    <div class="file-manager__toolbar">
        <div class="file-manager__breadcrumb">
            <a class="file-manager__breadcrumb-item" href="<?= $view->e($rootLink) ?>">Общие файлы</a>
            <?php if ($parent !== null): ?>
                <span class="file-manager__separator">/</span>
                <span class="file-manager__breadcrumb-item file-manager__breadcrumb-item--active"><?= $view->e($parent['name'] ?? '') ?></span>
                <?php if ($parent['parent_id'] !== null): ?>
                    <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($folderLink((int) $parent['parent_id'])) ?>">Уровнем выше</a>
                <?php else: ?>
                    <a class="file-manager__btn file-manager__btn--secondary" href="<?= $view->e($rootLink) ?>">К корню</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="file-manager__content">
        <?php if ($items === []): ?>
            <div class="file-manager__empty">В этом каталоге пока нет файлов и папок.</div>
        <?php else: ?>
            <div class="file-manager__grid">
                <?php foreach ($items as $item): ?>
                    <?php if (!is_array($item)) { continue; } ?>
                    <div class="file-manager__item">
                        <div class="file-manager__item-icon"><i class="fa <?= ($item['type'] ?? '') === 'folder' ? 'fa-folder' : 'fa-file-o' ?>" aria-hidden="true"></i></div>
                        <div class="file-manager__item-name">
                            <?php if (($item['type'] ?? '') === 'folder'): ?>
                                <a href="<?= $view->e($folderLink((int) $item['id'])) ?>"><?= $view->e($item['name']) ?></a>
                            <?php elseif (!empty($item['can_download'])): ?>
                                <a href="<?= $view->e($view->route('files_global_download', ['fileId' => (int) $item['id']])) ?>"><?= $view->e($item['name']) ?></a>
                            <?php else: ?>
                                <?= $view->e($item['name']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="file-manager__item-meta">
                            <?php if (($item['type'] ?? '') === 'file'): ?>
                                <?= number_format(((int) ($item['byte_size'] ?? 0)) / 1024, 1, ',', ' ') ?> КБ
                            <?php else: ?>
                                <?= !empty($item['inherit_acl']) ? 'Наследует права' : 'Собственные правила' ?>
                            <?php endif; ?>
                        </div>
                        <?php $objectAction = $view->route('files_global_object_mutate', ['nodeId' => (int) $item['id']]); ?>
                        <?php if (!empty($item['can_rename'])): ?>
                            <form method="post" action="<?= $view->e($objectAction) ?>" data-filespace-action>
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="action" value="rename">
                                <input type="hidden" name="expected_version" value="<?= (int) $item['version'] ?>">
                                <input type="hidden" name="expected_parent_version" value="<?= $folderVersion ?>">
                                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                                <label>Новое название <input type="text" name="name" maxlength="255"
                                        value="<?= $view->e($item['name']) ?>" required></label>
                                <button type="submit" class="file-manager__btn file-manager__btn--secondary">Переименовать</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($item['can_move'])): ?>
                            <form method="post" action="<?= $view->e($objectAction) ?>" data-filespace-action>
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="expected_version" value="<?= (int) $item['version'] ?>">
                                <input type="hidden" name="expected_parent_version" value="<?= $folderVersion ?>">
                                <input type="hidden" name="expected_destination_version" value="<?= (int) ($space['version'] ?? 1) ?>">
                                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                                <label>Переместить в
                                    <select name="destination_id" data-filespace-destination>
                                        <option value="" data-version="<?= (int) ($space['version'] ?? 1) ?>">Корень общих файлов</option>
                                        <?php foreach ($items as $destination): ?>
                                            <?php if (($destination['type'] ?? '') !== 'folder' || (int) $destination['id'] === (int) $item['id']) { continue; } ?>
                                            <option value="<?= (int) $destination['id'] ?>"
                                                    data-version="<?= (int) $destination['version'] ?>"><?= $view->e($destination['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button type="submit" class="file-manager__btn file-manager__btn--secondary">Переместить</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($item['can_replace'])): ?>
                            <form method="post" enctype="multipart/form-data"
                                  action="<?= $view->e($view->route('files_global_object_replace', ['fileId' => (int) $item['id']])) ?>"
                                  data-filespace-action>
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="expected_version" value="<?= (int) $item['version'] ?>">
                                <input type="hidden" name="expected_parent_version" value="<?= $folderVersion ?>">
                                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                                <label>Заменить содержимое <input type="file" name="file" required></label>
                                <button type="submit" class="file-manager__btn file-manager__btn--secondary">Заменить</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($item['can_delete'])): ?>
                            <form method="post" action="<?= $view->e($objectAction) ?>"
                                  data-filespace-action data-filespace-confirm="Переместить объект в корзину?">
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="expected_version" value="<?= (int) $item['version'] ?>">
                                <input type="hidden" name="expected_parent_version" value="<?= $folderVersion ?>">
                                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                                <button type="submit" class="file-manager__btn file-manager__btn--secondary">В корзину</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($catalog['next_cursor'])): ?>
        <nav class="file-manager__toolbar" aria-label="Следующая страница общих файлов">
            <?php $query = ['cursor' => (string) $catalog['next_cursor']]; ?>
            <?php if ($folderId !== null) { $query['folder'] = $folderId; } ?>
            <a class="file-manager__btn file-manager__btn--secondary"
               href="<?= $view->e($rootLink . '?' . http_build_query($query)) ?>">
                Продолжить просмотр каталога
            </a>
        </nav>
    <?php endif; ?>

    <?php if ($canUpload): ?>
        <section class="file-manager__content">
            <h2>Загрузить файл в общий каталог</h2>
            <form action="<?= $view->e($view->route('files_global_upload')) ?>" method="post"
                  enctype="multipart/form-data" data-filespace-action>
                <?= $view->csrfInput() ?>
                <input type="hidden" name="parent_id" value="<?= $folderId === null ? '' : (int) $folderId ?>">
                <input type="hidden" name="expected_version" value="<?= $folderVersion ?>">
                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                <label>Файл <input type="file" name="file" required></label>
                <button type="submit" class="file-manager__btn file-manager__btn--primary">Загрузить</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($canCreate): ?>
        <section class="file-manager__content">
            <h2>Создать папку</h2>
            <form action="<?= $view->e($view->route('files_global_create_folder')) ?>" method="post" data-filespace-action>
                <?= $view->csrfInput() ?>
                <input type="hidden" name="parent_id" value="<?= $folderId === null ? '' : (int) $folderId ?>">
                <input type="hidden" name="expected_version" value="<?= $folderVersion ?>">
                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                <label for="filespace-folder-name">Название</label>
                <input id="filespace-folder-name" name="name" type="text" maxlength="255" required>
                <button class="file-manager__btn file-manager__btn--primary" type="submit">Создать</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($trashItems !== []): ?>
        <section class="file-manager__content">
            <h2>Корзина общих файлов</h2>
            <p>Удалённые папки скрывают всё содержимое. Восстановление возвращает папку вместе с файлами.</p>
            <?php foreach ($trashItems as $discarded): ?>
                <div class="file-manager__item">
                    <div class="file-manager__item-name"><?= $view->e($discarded['name']) ?></div>
                    <div class="file-manager__item-meta">
                        Удалено: <?= $view->e($discarded['deleted_at'] ?? '') ?>
                    </div>
                    <form method="post"
                          action="<?= $view->e($view->route('files_global_object_mutate', ['nodeId' => (int) $discarded['id']])) ?>"
                          data-filespace-action>
                        <?= $view->csrfInput() ?>
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="expected_version" value="<?= (int) $discarded['version'] ?>">
                        <input type="hidden" name="expected_parent_version" value="<?= (int) $discarded['parent_version'] ?>">
                        <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                        <button type="submit" class="file-manager__btn file-manager__btn--secondary">Восстановить</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <section class="file-manager__content">
            <h2>Права доступа <?= $parent === null ? 'к общему пространству' : 'к этой папке' ?></h2>
            <p>Запрет имеет приоритет над разрешением на одном уровне. Если локального правила нет, применяются правила родителя.</p>
            <?php if ($rules !== []): ?>
                <ul>
                    <?php foreach ($rules as $rule): ?>
                        <li><?= $view->e($rule['subject_type'] . ':' . $rule['subject_id'] . ' — ' . $rule['permission_key'] . ' — ' . $rule['effect']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>Собственных правил пока нет. По умолчанию доступ запрещён, если не наследуется разрешение.</p>
            <?php endif; ?>

            <form action="<?= $view->e($view->route('files_global_acl')) ?>" method="post" data-filespace-action>
                <?= $view->csrfInput() ?>
                <input type="hidden" name="folder_id" value="<?= $folderId === null ? '' : (int) $folderId ?>">
                <input type="hidden" name="expected_version" value="<?= $folderVersion ?>">
                <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                <label>Кому
                    <select name="subject_type" data-filespace-subject-type>
                        <option value="everyone">Все авторизованные</option>
                        <option value="role">Роль</option>
                        <option value="group">Группа</option>
                        <option value="user">Пользователь</option>
                        <?php if ($parent !== null): ?><option value="author">Автор папки</option><?php endif; ?>
                    </select>
                </label>
                <label>Кого выбрать
                    <select name="subject_id" data-filespace-subject-id>
                        <option value="0" data-for="everyone">Все авторизованные</option>
                        <option value="0" data-for="author">Автор папки</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>" data-for="role"><?= $view->e(($role['name'] ?? '') . ' (' . ($role['code'] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" data-for="group"><?= $view->e($group['name']) ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($users as $member): ?>
                            <option value="<?= (int) $member['id'] ?>" data-for="user"><?= $view->e($member['username'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Действие
                    <select name="permission">
                        <?php foreach (\App\Services\Files\FileSpaceAccessService::PERMISSIONS as $permission): ?>
                            <option value="<?= $view->e($permission) ?>"><?= $view->e($permission) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Правило
                    <select name="effect">
                        <option value="allow">Разрешить</option>
                        <option value="deny">Запретить</option>
                        <option value="remove">Убрать правило</option>
                    </select>
                </label>
                <?php if ($parent !== null): ?>
                    <label>Наследование
                        <select name="inherit_acl">
                            <option value="">Не менять</option>
                            <option value="1">Наследовать родительские правила</option>
                            <option value="0">Только собственные правила</option>
                        </select>
                    </label>
                <?php endif; ?>
                <button type="submit" class="file-manager__btn file-manager__btn--primary">Сохранить правило</button>
            </form>
        </section>
    <?php endif; ?>

    <p class="file-manager__empty">Перемещение, изменение и удаление общих файлов подключаются следующим этапом. Личное хранилище работает без изменений.</p>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Общие файлы',
    'sitename' => $sitename ?? 'Workspace Organizer',
    'version' => $workspaceVersion,
    'base_url' => $base_url ?? '',
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $workspaceAccess ?? [],
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('files', 'style.css'),
        $view->moduleAsset('files', 'polish.css'),
    ],
    'module_scripts' => [$view->moduleAsset('files', 'spaces.js')],
], $content);
