<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$admin = is_array($admin_state ?? null) ? $admin_state : [];
$catalog = is_array($catalog ?? null) ? $catalog : [];
$groups = is_array($admin['groups'] ?? null) ? $admin['groups'] : [];
$members = is_array($admin['members'] ?? null) ? $admin['members'] : [];
$users = is_array($admin['users'] ?? null) ? $admin['users'] : [];
$roles = is_array($admin['roles'] ?? null) ? $admin['roles'] : [];
$rules = is_array($acl_rules ?? null) ? $acl_rules : [];
$decisions = is_array($diagnostic_decisions ?? null) ? $diagnostic_decisions : [];
$parent = is_array($catalog['parent'] ?? null) ? $catalog['parent'] : null;
$folderId = $parent === null ? null : (int) $parent['id'];
$folderVersion = (int) ($catalog['version'] ?? 1);
$selectedUser = isset($diagnostic_user_id) ? (int) $diagnostic_user_id : 0;
$rootUrl = $view->route('files_admin');
$command = static fn (): string => 'files:admin:' . bin2hex(random_bytes(16));
$rights = [
    'view' => 'Видеть папку', 'list' => 'Просматривать список',
    'download' => 'Скачивать', 'upload' => 'Загружать',
    'create_folder' => 'Создавать папки',
    'rename_own' => 'Переименовывать свои', 'rename_others' => 'Переименовывать чужие',
    'move_own' => 'Перемещать свои', 'move_others' => 'Перемещать чужие',
    'replace_own' => 'Заменять свои', 'replace_others' => 'Заменять чужие',
    'delete_own' => 'Удалять свои', 'delete_others' => 'Удалять чужие',
    'share' => 'Управлять ссылками', 'history' => 'Просматривать историю',
    'restore' => 'Восстанавливать', 'manage_acl' => 'Управлять правами',
];
$subjectNames = ['everyone' => 'Все', 'role' => 'Роль', 'group' => 'Группа', 'user' => 'Пользователь', 'author' => 'Автор'];
$subjectLabel = static function (array $rule) use ($subjectNames, $groups, $roles, $users): string {
    $type = (string) ($rule['subject_type'] ?? '');
    $id = (int) ($rule['subject_id'] ?? 0);
    if (in_array($type, ['everyone', 'author'], true)) return $subjectNames[$type] ?? $type;
    $collection = match ($type) {
        'role' => $roles, 'group' => $groups, 'user' => $users, default => [],
    };
    foreach ($collection as $item) {
        if ((int) $item['id'] !== $id) continue;
        return ($subjectNames[$type] ?? $type) . ': ' . ($item['name'] ?? $item['username'] ?? ('#' . $id));
    }
    return ($subjectNames[$type] ?? $type) . ': #' . $id;
};
$basePath = isset($base_path) ? (string) $base_path : '';
$workspaceVersion = isset($version) ? (string) $version : '';
ob_start();
?>
<section class="admin-page file-spaces-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Файловые пространства · 1.2</span>
            <h1>Управление общими файлами</h1>
            <p>Группы, наследуемые разрешения, проверка доступа и срок хранения удалённых файлов.</p>
        </div>
        <a class="admin-action admin-action--secondary"
           href="<?= $view->e($view->route('files_global')) ?>">Открыть общие файлы</a>
    </header>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header"><h2>Группы доступа</h2></div>
        <p>Группы назначают файловые права, но не изменяют системные роли участников.</p>
        <form action="<?= $view->e($view->route('files_admin_group_create')) ?>"
              method="post" data-filespace-action>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
            <label>Название группы
                <input type="text" name="name" maxlength="120" required placeholder="Например, Бухгалтерия">
            </label>
            <button type="submit" class="admin-action admin-action--primary">Создать группу</button>
        </form>
        <?php foreach ($groups as $group): ?>
            <?php $gid = (int) $group['id']; ?>
            <details class="admin-panel-card">
                <summary class="admin-panel-card__header">
                    <strong><?= $view->e($group['name']) ?></strong>
                    <small>Участников: <?= (int) $group['members'] ?></small>
                </summary>
                <p>
                    <?php foreach ($members as $member): ?>
                        <?php if ((int) $member['group_id'] === $gid): ?>
                            <span class="admin-action admin-action--secondary"><?= $view->e($member['username']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
                <form action="<?= $view->e($view->route('files_admin_group_member')) ?>"
                      method="post" data-filespace-action>
                    <?= $view->csrfInput() ?>
                    <input type="hidden" name="group_id" value="<?= $gid ?>">
                    <input type="hidden" name="expected_version" value="<?= (int) $group['version'] ?>">
                    <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                    <label>Участник
                        <select name="member_id" required>
                            <?php foreach ($users as $listed): ?>
                                <option value="<?= (int) $listed['id'] ?>"><?= $view->e($listed['username']) ?><?= (int) ($listed['is_active'] ?? 1) !== 1 || ($listed['account_status'] ?? 'active') !== 'active' ? ' (заблокирован)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Действие
                        <select name="operation">
                            <option value="add">Добавить</option>
                            <option value="remove">Исключить</option>
                        </select>
                    </label>
                    <button type="submit" class="admin-action admin-action--primary">Применить</button>
                </form>
                <form action="<?= $view->e($view->route('files_admin_group_delete')) ?>"
                      method="post" data-filespace-action
                      data-filespace-confirm="Удалить группу и все назначенные ей файловые правила?">
                    <?= $view->csrfInput() ?>
                    <input type="hidden" name="group_id" value="<?= $gid ?>">
                    <input type="hidden" name="expected_version" value="<?= (int) $group['version'] ?>">
                    <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
                    <button type="submit" class="admin-action admin-action--secondary">Удалить группу</button>
                </form>
            </details>
        <?php endforeach; ?>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header"><h2>Права на каталоги</h2></div>
        <p>Настраивайте общий корень или откройте нужную папку. При равном уровне явный запрет важнее разрешения.</p>
        <nav aria-label="Выбор папки">
            <a class="admin-action admin-action--secondary" href="<?= $view->e($rootUrl) ?>">Корень</a>
            <?php if ($parent !== null): ?>
                <strong><?= $view->e($parent['name']) ?></strong>
                <a class="admin-action admin-action--secondary"
                   href="<?= $view->e($parent['parent_id'] === null ? $rootUrl
                       : $rootUrl . '?folder=' . (int) $parent['parent_id']) ?>">Назад</a>
            <?php endif; ?>
            <?php foreach (($catalog['items'] ?? []) as $item): ?>
                <?php if (($item['type'] ?? '') !== 'folder') continue; ?>
                <a class="admin-action admin-action--secondary"
                   href="<?= $view->e($rootUrl . '?folder=' . (int) $item['id']) ?>">
                    <?= $view->e($item['name']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php if (!empty($catalog['next_cursor'])): ?>
            <?php $nextQuery = ['cursor' => (string) $catalog['next_cursor']]; ?>
            <?php if ($folderId !== null) { $nextQuery['folder'] = $folderId; } ?>
            <?php if ($selectedUser > 0) { $nextQuery['user'] = $selectedUser; } ?>
            <a class="admin-action admin-action--secondary"
               href="<?= $view->e($rootUrl . '?' . http_build_query($nextQuery)) ?>">
                Следующие папки каталога
            </a>
        <?php endif; ?>
        <h3><?= $parent === null ? 'Права глобального пространства' : 'Права папки: ' . $view->e($parent['name']) ?></h3>
        <?php if ($rules): ?>
            <table>
                <thead><tr><th>Кому</th><th>Право</th><th>Решение</th></tr></thead>
                <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <tr>
                            <td><?= $view->e($subjectLabel($rule)) ?></td>
                            <td><?= $view->e($rights[$rule['permission_key']] ?? $rule['permission_key']) ?></td>
                            <td><?= ($rule['effect'] ?? '') === 'deny' ? 'Запрещено' : 'Разрешено' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Локальных правил нет. По умолчанию действует наследование, а для корня — запрет.</p>
        <?php endif; ?>
        <form action="<?= $view->e($view->route('files_admin_acl')) ?>"
              method="post" data-filespace-action>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="folder_id" value="<?= $folderId ?? '' ?>">
            <input type="hidden" name="expected_version" value="<?= $folderVersion ?>">
            <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
            <label>Получатель
                <select name="subject_type" data-filespace-subject-type>
                    <option value="everyone">Все авторизованные</option>
                    <option value="role">Роль</option>
                    <option value="group">Группа</option>
                    <option value="user">Пользователь</option>
                    <?php if ($folderId !== null): ?><option value="author">Автор папки</option><?php endif; ?>
                </select>
            </label>
            <label>Выбрать
                <select name="subject_id" data-filespace-subject-id>
                    <option data-for="everyone" value="0">Все</option>
                    <option data-for="author" value="0">Автор</option>
                    <?php foreach ($roles as $role): ?>
                        <option data-for="role" value="<?= (int) $role['id'] ?>"><?= $view->e($role['name']) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($groups as $group): ?>
                        <option data-for="group" value="<?= (int) $group['id'] ?>"><?= $view->e($group['name']) ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($users as $listed): ?>
                        <option data-for="user" value="<?= (int) $listed['id'] ?>"><?= $view->e($listed['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Разрешение
                <select name="permission">
                    <?php foreach ($rights as $permission => $label): ?>
                        <option value="<?= $view->e($permission) ?>"><?= $view->e($label) ?></option>
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
            <?php if ($folderId !== null): ?>
                <label>Наследование
                    <select name="inherit_acl">
                        <option value="">Не изменять</option>
                        <option value="1">Наследовать права родителя</option>
                        <option value="0">Использовать собственные правила</option>
                    </select>
                </label>
            <?php endif; ?>
            <button type="submit" class="admin-action admin-action--primary">Сохранить правило</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header"><h2>Проверка итоговых прав пользователя</h2></div>
        <form action="<?= $view->e($rootUrl) ?>" method="get">
            <?php if ($folderId !== null): ?>
                <input type="hidden" name="folder" value="<?= $folderId ?>">
            <?php endif; ?>
            <label>Пользователь
                <select name="user">
                    <?php foreach ($users as $listed): ?>
                        <option value="<?= (int) $listed['id'] ?>"
                            <?= $selectedUser === (int) $listed['id'] ? 'selected' : '' ?>>
                            <?= $view->e($listed['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="admin-action admin-action--secondary">Показать права</button>
        </form>
        <?php if ($decisions): ?>
            <table>
                <thead><tr><th>Операция</th><th>Результат</th><th>Источник политики</th><th>Сработавшее правило</th></tr></thead>
                <tbody>
                    <?php foreach ($decisions as $decision): ?>
                        <tr>
                            <td><?= $view->e($rights[$decision['permission']] ?? $decision['permission']) ?></td>
                            <td><?= $decision['allowed'] ? 'Разрешено' : 'Запрещено' ?></td>
                            <td><?= $view->e($decision['source'] === 'administrator' ? 'Администратор'
                                : ($decision['source'] === 'default_deny' ? 'Запрет по умолчанию'
                                : ($decision['source'] === 'ancestor' ? 'Закрытая родительская папка'
                                : ($decision['source'] === 'space' ? 'Глобальное пространство'
                                : 'Правило папки ' . $decision['source'])))) ?></td>
                            <td><?= $view->e($decision['subject'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header"><h2>Политика корзины</h2></div>
        <p>Срок хранения определяет период до разрешённой окончательной очистки.
           Автоматическое безвозвратное удаление на этом этапе не запускается.</p>
        <form action="<?= $view->e($view->route('files_admin_retention')) ?>"
              method="post" data-filespace-action>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="expected_version" value="<?= (int) ($admin['settings_version'] ?? 1) ?>">
            <input type="hidden" name="command_id" value="<?= $view->e($command()) ?>">
            <label>Хранить в корзине (дней)
                <input type="number" name="days" min="1" max="3650"
                       value="<?= (int) ($admin['retention_days'] ?? 30) ?>" required>
            </label>
            <button type="submit" class="admin-action admin-action--primary">Сохранить срок</button>
        </form>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Общие файлы — управление',
    'sitename' => $sitename ?? 'Workspace Organizer',
    'version' => $workspaceVersion,
    'base_url' => $base_url ?? '',
    'base_path' => $basePath,
    'user' => $user ?? null,
    'workspaceAccess' => $workspaceAccess ?? [],
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('admin', 'style.css'),
        $view->moduleAsset('files', 'style.css'),
    ],
    'module_scripts' => [$view->moduleAsset('files', 'spaces.js')],
], $content);
