<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$listedUsers = isset($users) && is_array($users) ? $users : [];
$customFieldRows = isset($customFields) && is_array($customFields) ? $customFields : [];
$flash = isset($admin_flash) && is_array($admin_flash) ? $admin_flash : null;
$canManageRoles = !empty($canManageRoles);
$canViewAudit = !empty($canViewAudit);
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$listState = isset($pagination) && is_array($pagination) ? $pagination : [];
$listQ = trim((string) ($listState['q'] ?? ''));
$listPage = max(1, (int) ($listState['page'] ?? 1));
$listLimit = in_array((int) ($listState['limit'] ?? 20), [10, 20, 50], true) ? (int) $listState['limit'] : 20;
$listTotal = max(0, (int) ($listState['total'] ?? count($listedUsers)));
$listTotalPages = max(1, (int) ($listState['total_pages'] ?? 1));
$listSort = in_array((string) ($listState['sort'] ?? 'id'), ['id', 'username', 'email', 'created_at', 'role'], true)
    ? (string) $listState['sort']
    : 'id';
$listDirection = in_array((string) ($listState['direction'] ?? 'desc'), ['asc', 'desc'], true)
    ? (string) $listState['direction']
    : 'desc';
$adminListUrl = static function (int $page) use ($view, $listQ, $listLimit, $listSort, $listDirection): string {
    $query = http_build_query([
        'q' => $listQ,
        'page' => max(1, $page),
        'limit' => $listLimit,
        'sort' => $listSort,
        'direction' => $listDirection,
    ]);

    return $view->route('adminpanel') . ($query !== '' ? '?' . $query : '');
};
$fieldTypes = [
    'text' => 'Текст',
    'textarea' => 'Многострочный текст',
    'number' => 'Число',
    'date' => 'Дата',
    'checkbox' => 'Флажок',
    'select' => 'Select (legacy, без вариантов)',
];

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <h1>Админпанель</h1>
        </div>
        <?php if ($canViewAudit): ?>
            <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('admin_audit')) ?>"><i class="fa fa-history" aria-hidden="true"></i> Журнал действий</a>
        <?php endif; ?>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status">
            <?= $view->e($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <details class="admin-panel-card admin-create-user" aria-labelledby="admin-create-user-title">
        <summary class="admin-panel-card__header">
            <div>

                <h2 id="admin-create-user-title"><i class="fa fa-user-plus" aria-hidden="true"></i> Добавить пользователя</h2>

            </div>
        </summary>
        <form action="<?= $view->e($view->route('admin_create_user')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-field__grid">
                <div class="custom-field__control"><label for="new_user_login">Логин *</label><input id="new_user_login" name="login" type="text" minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]+" required autocomplete="off"></div>
                <div class="custom-field__control"><label for="new_user_email">Email *</label><input id="new_user_email" name="email" type="email" maxlength="190" required autocomplete="off"></div>
                <div class="custom-field__control"><label for="new_user_password">Временный пароль *</label><input id="new_user_password" name="password" type="password" minlength="10" maxlength="200" required autocomplete="new-password"></div>
                <div class="custom-field__control"><label for="new_user_first_name">Имя *</label><input id="new_user_first_name" name="first_name" type="text" maxlength="80" required></div>
                <div class="custom-field__control"><label for="new_user_patronymic">Отчество</label><input id="new_user_patronymic" name="patronymic" type="text" maxlength="80"></div>
                <div class="custom-field__control"><label for="new_user_surname">Фамилия *</label><input id="new_user_surname" name="surname" type="text" maxlength="80" required></div>
                <div class="custom-field__control"><label for="new_user_phone">Телефон</label><input id="new_user_phone" name="user_phone" type="tel" maxlength="32"></div>
            </div>
            <div class="custom-fields-form__footer">
                <small>Новый аккаунт получает базовую роль User и статус «Активен». Пароль не отправляется по email — передайте его пользователю безопасным каналом.</small>
                <button type="submit" class="admin-action admin-action--primary"><i class="fa fa-user-plus" aria-hidden="true"></i> Создать пользователя</button>
            </div>
        </form>
    </details>

    <section class="admin-panel-card" aria-labelledby="admin-users-title">
        <div class="admin-panel-card__header">
            <div>
                <h2 id="admin-users-title">Пользователи <span class="admin-result-count"><?= count($listedUsers) ?> на странице</span></h2>
            </div>
        </div>
        <form action="<?= $view->e($view->route('adminpanel')) ?>" method="get" class="admin-toolbar" role="search" aria-label="Поиск и сортировка пользователей">
            <input type="hidden" name="page" value="1">

            <label class="admin-toolbar__search" for="admin-search">
                <span>Поиск</span>
                <input
                    id="admin-search"
                    type="search"
                    name="q"
                    value="<?= $view->e($listQ) ?>"
                    maxlength="100"
                    placeholder="Имя, логин или email"
                    autocomplete="off"
                >
            </label>

            <div class="admin-toolbar__options">
                <label class="admin-toolbar__field" for="admin-limit">
                    <span>На странице</span>
                    <select id="admin-limit" name="limit">
                        <?php foreach ([10, 20, 50] as $value): ?>
                            <option value="<?= $value ?>"<?= $listLimit === $value ? ' selected' : '' ?>><?= $value ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="admin-toolbar__field admin-toolbar__field--sort" for="admin-sort">
                    <span>Сортировка</span>
                    <select id="admin-sort" name="sort">
                        <option value="id"<?= $listSort === 'id' ? ' selected' : '' ?>>ID</option>
                        <option value="username"<?= $listSort === 'username' ? ' selected' : '' ?>>Логин</option>
                        <option value="email"<?= $listSort === 'email' ? ' selected' : '' ?>>Email</option>
                        <option value="created_at"<?= $listSort === 'created_at' ? ' selected' : '' ?>>Дата создания</option>
                        <option value="role"<?= $listSort === 'role' ? ' selected' : '' ?>>Роль</option>
                    </select>
                </label>

                <label class="admin-toolbar__field admin-toolbar__field--direction" for="admin-direction">
                    <span>Порядок</span>
                    <select id="admin-direction" name="direction">
                        <option value="asc"<?= $listDirection === 'asc' ? ' selected' : '' ?>>↑</option>
                        <option value="desc"<?= $listDirection === 'desc' ? ' selected' : '' ?>>↓</option>
                    </select>
                </label>
            </div>

            <div class="admin-toolbar__actions">
                <button type="submit" class="admin-action admin-action--primary"><i class="fa fa-search" aria-hidden="true"></i> Найти</button>
                <?php if ($listQ !== '' || $listLimit !== 20 || $listSort !== 'id' || $listDirection !== 'desc'): ?>
                    <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>">Сбросить</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th scope="col">Пользователь</th><th scope="col">Роль</th><th scope="col">Статус</th><th scope="col">Создан</th><th scope="col">Действия</th></tr></thead>
                <tbody>
                <?php foreach ($listedUsers as $listedUser): ?>
                    <?php if (!is_array($listedUser)) { continue; } ?>
                    <?php
                        $statusCode = preg_match('/^[a-z_]+$/', (string) ($listedUser['status_code'] ?? '')) === 1
                            ? (string) $listedUser['status_code']
                            : 'inactive';
                        $canManage = !empty($listedUser['can_manage']);
                        $isSelf = (int) ($listedUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
                    ?>
                    <tr>
                        <td data-label="Пользователь">
                            <strong><?= $view->e(trim((string) ($listedUser['firstname'] ?? '') . ' ' . (string) ($listedUser['lastname'] ?? ''))) ?></strong>
                            <span>@<?= $view->e($listedUser['username'] ?? '') ?></span>
                            <small><?= $view->e($listedUser['email'] ?? '') ?></small>
                        </td>
                        <td data-label="Роль"><span class="admin-role"><?= $view->e($listedUser['role_label'] ?? '') ?></span></td>
                        <td data-label="Статус"><span class="admin-status admin-status--<?= $view->e($statusCode) ?>"><?= $view->e($listedUser['status_label'] ?? '') ?></span></td>
                        <td data-label="Создан"><?= $view->e($listedUser['created_at'] ?? '') ?></td>
                        <td data-label="Действия">
                            <div class="admin-user-actions">
                                <?php if ($canManage): ?>
                                    <form action="<?= $view->e($view->route('admin_toggle_user')) ?>" method="post">
                                        <?= $view->csrfInput() ?>
                                        <input type="hidden" name="user_id" value="<?= $view->e($listedUser['id'] ?? '') ?>">
                                        <?php if (in_array($statusCode, ['inactive', 'blocked'], true)): ?>
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" class="admin-action admin-action--secondary"><i class="fa fa-check-circle" aria-hidden="true"></i> Активировать</button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="blocked">
                                            <button type="submit" class="admin-action admin-action--secondary"><i class="fa fa-ban" aria-hidden="true"></i> Блокировать</button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if ($statusCode !== 'inactive'): ?>
                                        <form action="<?= $view->e($view->route('admin_delete_user')) ?>" method="post" data-confirm-deactivate="@<?= $view->e($listedUser['username'] ?? '') ?>">
                                            <?= $view->csrfInput() ?>
                                            <input type="hidden" name="user_id" value="<?= $view->e($listedUser['id'] ?? '') ?>">
                                            <button type="submit" class="admin-action admin-action--danger"><i class="fa fa-user-times" aria-hidden="true"></i> Деактивировать</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="admin-user-actions__locked"><?= $isSelf ? 'Текущий аккаунт' : 'Защищённая роль' ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="admin-pagination" aria-label="Пагинация пользователей">
            <a class="admin-pagination__link" href="<?= $view->e($adminListUrl(max(1, $listPage - 1))) ?>"<?= $listPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>← Назад</a>
            <span class="admin-pagination__summary">Страница <?= $view->e($listPage) ?> из <?= $view->e($listTotalPages) ?> · найдено <?= $view->e($listTotal) ?></span>
            <a class="admin-pagination__link" href="<?= $view->e($adminListUrl(min($listTotalPages, $listPage + 1))) ?>"<?= $listPage >= $listTotalPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Вперёд →</a>
        </nav>
    </section>

    <section class="admin-panel-card" aria-labelledby="admin-fields-title">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Профиль</span><h2 id="admin-fields-title">Дополнительные поля</h2><p>Техническое имя используется в сохранённых данных профиля. После запуска в production меняйте его только осознанно.</p></div>
            <button type="button" id="add-field-btn" class="admin-action admin-action--primary"><i class="fa fa-plus" aria-hidden="true"></i> Добавить поле</button>
        </div>
        <form action="<?= $view->e($view->route('save_custom_fields')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div id="custom-fields-container" class="custom-fields-list">
                <?php foreach ($customFieldRows as $field): ?>
                    <?php if (!is_array($field)) { continue; } ?>
                    <?php $fieldId = (int) ($field['id'] ?? 0); $fieldType = (string) ($field['field_type'] ?? 'text'); ?>
                    <div class="custom-field" data-field-key="<?= $view->e($fieldId) ?>">
                        <div class="custom-field__grid">
                            <div class="custom-field__control"><label for="field_name_<?= $view->e($fieldId) ?>">Техническое имя</label><input type="text" id="field_name_<?= $view->e($fieldId) ?>" name="fields[<?= $view->e($fieldId) ?>][field_name]" value="<?= $view->e($field['field_name'] ?? '') ?>" maxlength="50" pattern="[a-z][a-z0-9_]{0,49}" required autocomplete="off"></div>
                            <div class="custom-field__control"><label for="field_label_<?= $view->e($fieldId) ?>">Метка</label><input type="text" id="field_label_<?= $view->e($fieldId) ?>" name="fields[<?= $view->e($fieldId) ?>][field_label]" value="<?= $view->e($field['field_label'] ?? '') ?>" maxlength="100" required autocomplete="off"></div>
                            <div class="custom-field__control"><label for="field_type_<?= $view->e($fieldId) ?>">Тип</label><select id="field_type_<?= $view->e($fieldId) ?>" name="fields[<?= $view->e($fieldId) ?>][field_type]" required><?php foreach ($fieldTypes as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $fieldType === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
                            <label class="custom-field__required" for="is_required_<?= $view->e($fieldId) ?>"><input type="checkbox" id="is_required_<?= $view->e($fieldId) ?>" name="fields[<?= $view->e($fieldId) ?>][is_required]"<?= !empty($field['is_required']) ? ' checked' : '' ?>> Обязательное</label>
                            <button type="button" class="remove-field admin-action admin-action--danger" aria-label="Удалить поле <?= $view->e($field['field_label'] ?? '') ?>"><i class="fa fa-trash" aria-hidden="true"></i> Удалить</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="custom-fields-form__footer"><small>Удаление поля из списка удаляет его определение после сохранения, но не выполняет физическое удаление аккаунтов пользователей.</small><button type="submit" class="admin-action admin-action--primary"><i class="fa fa-save" aria-hidden="true"></i> Сохранить поля</button></div>
        </form>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Админпанель',
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
        $view->moduleAsset('admin', 'admin-page.js'),
    ],
], $content);
