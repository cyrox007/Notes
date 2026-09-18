<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$roleRows = isset($roles) && is_array($roles) ? $roles : [];
$roleUserRows = isset($roleUsers) && is_array($roleUsers) ? $roleUsers : [];
$flash = isset($admin_roles_flash) && is_array($admin_roles_flash) ? $admin_roles_flash : null;
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">RBAC и политики модулей</span>
            <h1>Роли и ограничения</h1>
            <p>Разрешения отвечают за доступ к функциям, а политики модулей — за лимиты, типы ресурсов и отдельные возможности.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>"><i class="fa fa-arrow-left" aria-hidden="true"></i> Назад в админпанель</a>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status"><?= $view->e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="admin-panel-card" aria-labelledby="admin-create-role-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Новая роль</span>
                <h2 id="admin-create-role-title">Создать прикладную роль</h2>
                <p>Системные роли сохраняются как базовый контракт. Для сотрудников создавайте отдельные прикладные роли с нужными правами и лимитами.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_roles_create')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-fields-list"><div class="custom-field"><div class="custom-field__grid">
                <div class="custom-field__control"><label for="role_code">Код роли</label><input id="role_code" name="code" type="text" minlength="2" maxlength="64" pattern="[a-z][a-z0-9_.-]+" placeholder="manager" required autocomplete="off"></div>
                <div class="custom-field__control"><label for="role_name">Название</label><input id="role_name" name="name" type="text" maxlength="120" placeholder="Менеджер" required></div>
                <div class="custom-field__control"><label for="role_description">Описание</label><input id="role_description" name="description" type="text" maxlength="255" placeholder="Доступ к рабочим модулям по политике отдела"></div>
            </div></div></div>
            <div class="custom-fields-form__footer"><small>После создания роли отдельно настройте разрешения и ограничения модулей.</small><button type="submit" class="admin-action admin-action--primary"><i class="fa fa-plus" aria-hidden="true"></i> Создать роль</button></div>
        </form>
    </section>

    <?php foreach ($roleRows as $role): ?>
        <?php if (!is_array($role)) { continue; } ?>
        <?php
            $roleId = (int) ($role['id'] ?? 0);
            $roleCode = (string) ($role['code'] ?? '');
            $isSystem = !empty($role['is_system']);
            $permissionItems = isset($role['permission_items']) && is_array($role['permission_items']) ? $role['permission_items'] : [];
            $policySections = isset($role['policy_sections']) && is_array($role['policy_sections']) ? $role['policy_sections'] : [];
        ?>
        <section class="admin-panel-card" aria-labelledby="role-title-<?= $view->e($roleId) ?>">
            <div class="admin-panel-card__header">
                <div>
                    <span class="admin-panel-card__kicker"><?= $isSystem ? 'Системная роль' : 'Прикладная роль' ?></span>
                    <h2 id="role-title-<?= $view->e($roleId) ?>"><?= $view->e($role['name'] ?? '') ?> <small>@<?= $view->e($roleCode) ?></small></h2>
                    <p><?= $view->e(($role['description'] ?? '') !== '' ? $role['description'] : 'Без описания') ?> · назначено пользователям: <?= $view->e($role['assigned_users'] ?? 0) ?></p>
                </div>
                <?php if (!$isSystem): ?>
                    <form action="<?= $view->e($view->route('admin_roles_delete')) ?>" method="post" data-confirm-message="Удалить эту роль?" data-confirm-title="Удаление роли" data-confirm-text="Удалить">
                        <?= $view->csrfInput() ?>
                        <input type="hidden" name="role_id" value="<?= $view->e($roleId) ?>">
                        <button type="submit" class="admin-action admin-action--danger"><i class="fa fa-trash" aria-hidden="true"></i> Удалить</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($roleCode === 'superadmin'): ?>
                <div class="custom-fields-list"><div class="custom-fields-empty">Суперадминистратор всегда имеет полный доступ. Его разрешения и политики модулей намеренно не редактируются.</div></div>
            <?php else: ?>
                <form action="<?= $view->e($view->route('admin_roles_update')) ?>" method="post" class="custom-fields-form">
                    <?= $view->csrfInput() ?>
                    <input type="hidden" name="role_id" value="<?= $view->e($roleId) ?>">
                    <div class="custom-fields-list">
                        <div class="custom-field"><div class="custom-field__grid">
                            <div class="custom-field__control"><label for="role_name_<?= $view->e($roleId) ?>">Название</label><input id="role_name_<?= $view->e($roleId) ?>" name="name" type="text" maxlength="120" value="<?= $view->e($role['name'] ?? '') ?>" required<?= $isSystem ? ' readonly' : '' ?>></div>
                            <div class="custom-field__control"><label for="role_description_<?= $view->e($roleId) ?>">Описание</label><input id="role_description_<?= $view->e($roleId) ?>" name="description" type="text" maxlength="255" value="<?= $view->e($role['description'] ?? '') ?>"<?= $isSystem ? ' readonly' : '' ?>></div>
                        </div></div>
                        <div class="custom-field">
                            <strong>Разрешения</strong>
                            <div class="admin-user-actions admin-user-actions--spaced">
                                <?php foreach ($permissionItems as $permission): ?>
                                    <?php if (!is_array($permission)) { continue; } ?>
                                    <label class="custom-field__required" title="<?= $view->e($permission['description'] ?? '') ?>">
                                        <input type="checkbox" name="permission_codes[]" value="<?= $view->e($permission['code'] ?? '') ?>"<?= !empty($permission['granted']) ? ' checked' : '' ?><?= !empty($permission['locked']) ? ' disabled' : '' ?>>
                                        <?= $view->e($permission['code'] ?? '') ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="custom-fields-form__footer"><small>Изменения разрешений начинают действовать при следующей серверной проверке доступа.</small><button type="submit" class="admin-action admin-action--primary"><i class="fa fa-save" aria-hidden="true"></i> Сохранить разрешения</button></div>
                </form>

                <form action="<?= $view->e($view->route('admin_roles_policies')) ?>" method="post" class="custom-fields-form">
                    <?= $view->csrfInput() ?>
                    <input type="hidden" name="role_id" value="<?= $view->e($roleId) ?>">
                    <div class="custom-fields-list">
                        <?php foreach ($policySections as $policySection): ?>
                            <?php if (!is_array($policySection)) { continue; } ?>
                            <?php $moduleId = (string) ($policySection['module_id'] ?? ''); $policies = isset($policySection['items']) && is_array($policySection['items']) ? $policySection['items'] : []; ?>
                            <div class="custom-field">
                                <strong><?= $view->e($policySection['module_label'] ?? '') ?></strong>
                                <div class="custom-field__grid custom-field__grid--spaced">
                                    <?php foreach ($policies as $policy): ?>
                                        <?php if (!is_array($policy)) { continue; } ?>
                                        <?php
                                            $key = (string) ($policy['key'] ?? '');
                                            $type = (string) ($policy['type'] ?? 'string');
                                            $explicit = !empty($policy['is_explicit']);
                                            $value = $policy['value'] ?? '';
                                            $inputId = 'policy_' . $roleId . '_' . $moduleId . '_' . $key;
                                            $fieldName = 'policies[' . $moduleId . '][' . $key . ']';
                                        ?>
                                        <div class="custom-field__control">
                                            <label for="<?= $view->e($inputId) ?>"><?= $view->e($policy['label'] ?? $key) ?></label>
                                            <?php if ($type === 'bool'): ?>
                                                <select id="<?= $view->e($inputId) ?>" name="<?= $view->e($fieldName) ?>">
                                                    <option value="__inherit__"<?= !$explicit ? ' selected' : '' ?>>По умолчанию</option>
                                                    <option value="1"<?= $explicit && (string) $value === '1' ? ' selected' : '' ?>>Разрешено</option>
                                                    <option value="0"<?= $explicit && (string) $value === '0' ? ' selected' : '' ?>>Запрещено</option>
                                                </select>
                                            <?php elseif ($type === 'int'): ?>
                                                <input id="<?= $view->e($inputId) ?>" name="<?= $view->e($fieldName) ?>" type="number" min="0" step="1" value="<?= $explicit ? $view->e($value) : '' ?>" placeholder="наследовать">
                                            <?php else: ?>
                                                <input id="<?= $view->e($inputId) ?>" name="<?= $view->e($fieldName) ?>" type="text" value="<?= $explicit ? $view->e($value) : '' ?>" placeholder="наследовать">
                                            <?php endif; ?>
                                            <small><?= $view->e($policy['help'] ?? '') ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="custom-fields-form__footer"><small>Пустое значение означает наследование платформенного значения. Для числовых политик 0 означает отсутствие дополнительного ограничения роли.</small><button type="submit" class="admin-action admin-action--primary"><i class="fa fa-sliders" aria-hidden="true"></i> Сохранить ограничения</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="admin-panel-card" aria-labelledby="role-assignments-title">
        <div class="admin-panel-card__header"><div><span class="admin-panel-card__kicker">Назначения</span><h2 id="role-assignments-title">Роли пользователей</h2><p>Пользователь может иметь несколько ролей. Эффективные разрешения объединяются, а политики рассчитываются по правилам RolePolicyService.</p></div></div>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Пользователь</th><th>Статус</th><th>Роли</th><th>Действие</th></tr></thead>
                <tbody>
                <?php foreach ($roleUserRows as $listedUser): ?>
                    <?php if (!is_array($listedUser)) { continue; } ?>
                    <?php $choices = isset($listedUser['role_choices']) && is_array($listedUser['role_choices']) ? $listedUser['role_choices'] : []; $isSelf = !empty($listedUser['is_self']); ?>
                    <tr>
                        <td data-label="Пользователь"><strong>@<?= $view->e($listedUser['username'] ?? '') ?></strong><small><?= $view->e($listedUser['email'] ?? '') ?></small></td>
                        <td data-label="Статус"><?= $view->e($listedUser['status_label'] ?? '') ?></td>
                        <td data-label="Роли">
                            <form action="<?= $view->e($view->route('admin_roles_assign')) ?>" method="post" class="admin-user-actions">
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="user_id" value="<?= $view->e($listedUser['id'] ?? '') ?>">
                                <?php foreach ($choices as $assignableRole): ?>
                                    <?php if (!is_array($assignableRole)) { continue; } ?>
                                    <label class="custom-field__required"><input type="checkbox" name="role_ids[]" value="<?= $view->e($assignableRole['id'] ?? '') ?>"<?= !empty($assignableRole['checked']) ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>> <?= $view->e($assignableRole['name'] ?? '') ?></label>
                                <?php endforeach; ?>
                                <?php if (!$isSelf): ?><button type="submit" class="admin-action admin-action--secondary">Применить</button><?php else: ?><span class="admin-user-actions__locked">Текущий аккаунт защищён</span><?php endif; ?>
                            </form>
                        </td>
                        <td data-label="Действие"><?= $isSelf ? '—' : 'Сохранение в строке' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Роли и ограничения',
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
