{extends file="core/base.tpl"}
{block name=title}Роли и ограничения{/block}
{block name=body}
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">RBAC и политики модулей</span>
            <h1>Роли и ограничения</h1>
            <p>Разрешения отвечают за доступ к функциям, а политики модулей — за лимиты, типы ресурсов и отдельные возможности.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="{route_path name='adminpanel'}">
            <i class="fa fa-arrow-left" aria-hidden="true"></i> Назад в админпанель
        </a>
    </header>

    {if $admin_roles_flash}
        <div class="admin-page__flash admin-page__flash--{$admin_roles_flash.type|escape}" role="status">
            {$admin_roles_flash.message|escape}
        </div>
    {/if}

    <section class="admin-panel-card" aria-labelledby="admin-create-role-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Новая роль</span>
                <h2 id="admin-create-role-title">Создать прикладную роль</h2>
                <p>Системные роли сохраняются как базовый контракт. Для сотрудников создавайте отдельные прикладные роли с нужными правами и лимитами.</p>
            </div>
        </div>
        <form action="{route_path name='admin_roles_create'}" method="post" class="custom-fields-form">
            {csrf_token}
            <div class="custom-fields-list">
                <div class="custom-field">
                    <div class="custom-field__grid">
                        <div class="custom-field__control">
                            <label for="role_code">Код роли</label>
                            <input id="role_code" name="code" type="text" minlength="2" maxlength="64" pattern="[a-z][a-z0-9_.-]+" placeholder="manager" required autocomplete="off">
                        </div>
                        <div class="custom-field__control">
                            <label for="role_name">Название</label>
                            <input id="role_name" name="name" type="text" maxlength="120" placeholder="Менеджер" required>
                        </div>
                        <div class="custom-field__control">
                            <label for="role_description">Описание</label>
                            <input id="role_description" name="description" type="text" maxlength="255" placeholder="Доступ к рабочим модулям по политике отдела">
                        </div>
                    </div>
                </div>
            </div>
            <div class="custom-fields-form__footer">
                <small>После создания роли отдельно настройте разрешения и ограничения модулей.</small>
                <button type="submit" class="admin-action admin-action--primary"><i class="fa fa-plus" aria-hidden="true"></i> Создать роль</button>
            </div>
        </form>
    </section>

    {foreach $roles as $role}
        <section class="admin-panel-card" aria-labelledby="role-title-{$role.id}">
            <div class="admin-panel-card__header">
                <div>
                    <span class="admin-panel-card__kicker">{if $role.is_system}Системная роль{else}Прикладная роль{/if}</span>
                    <h2 id="role-title-{$role.id}">{$role.name|escape} <small>@{$role.code|escape}</small></h2>
                    <p>{$role.description|default:'Без описания'|escape} · назначено пользователям: {$role.assigned_users}</p>
                </div>
                {if !$role.is_system}
                    <form action="{route_path name='admin_roles_delete'}" method="post" onsubmit="return confirm('Удалить роль {$role.name|escape:'javascript'}?');">
                        {csrf_token}
                        <input type="hidden" name="role_id" value="{$role.id}">
                        <button type="submit" class="admin-action admin-action--danger"><i class="fa fa-trash" aria-hidden="true"></i> Удалить</button>
                    </form>
                {/if}
            </div>

            {if $role.code == 'superadmin'}
                <div class="custom-fields-list">
                    <div class="custom-fields-empty">Суперадминистратор всегда имеет полный доступ. Его разрешения и политики модулей намеренно не редактируются.</div>
                </div>
            {else}
                <form action="{route_path name='admin_roles_update'}" method="post" class="custom-fields-form">
                    {csrf_token}
                    <input type="hidden" name="role_id" value="{$role.id}">
                    <div class="custom-fields-list">
                        <div class="custom-field">
                            <div class="custom-field__grid">
                                <div class="custom-field__control">
                                    <label for="role_name_{$role.id}">Название</label>
                                    <input id="role_name_{$role.id}" name="name" type="text" maxlength="120" value="{$role.name|escape}" required {if $role.is_system}readonly{/if}>
                                </div>
                                <div class="custom-field__control">
                                    <label for="role_description_{$role.id}">Описание</label>
                                    <input id="role_description_{$role.id}" name="description" type="text" maxlength="255" value="{$role.description|escape}" {if $role.is_system}readonly{/if}>
                                </div>
                            </div>
                        </div>

                        <div class="custom-field">
                            <strong>Разрешения</strong>
                            <div class="admin-user-actions" style="margin-top:12px">
                                {foreach $role.permission_items as $permission}
                                    <label class="custom-field__required" title="{$permission.description|escape}">
                                        <input type="checkbox" name="permission_codes[]" value="{$permission.code|escape}" {if $permission.granted}checked{/if} {if $permission.locked}disabled{/if}>
                                        {$permission.code|escape}
                                    </label>
                                {/foreach}
                            </div>
                        </div>
                    </div>
                    <div class="custom-fields-form__footer">
                        <small>Изменения разрешений начинают действовать при следующей серверной проверке доступа.</small>
                        <button type="submit" class="admin-action admin-action--primary"><i class="fa fa-save" aria-hidden="true"></i> Сохранить разрешения</button>
                    </div>
                </form>

                <form action="{route_path name='admin_roles_policies'}" method="post" class="custom-fields-form">
                    {csrf_token}
                    <input type="hidden" name="role_id" value="{$role.id}">
                    <div class="custom-fields-list">
                        {foreach $role.policy_sections as $policySection}
                            <div class="custom-field">
                                <strong>{$policySection.module_label|escape}</strong>
                                <div class="custom-field__grid" style="margin-top:12px">
                                    {foreach $policySection.items as $policy}
                                        <div class="custom-field__control">
                                            <label for="policy_{$role.id}_{$policySection.module_id|escape}_{$policy.key|escape}">{$policy.label|escape}</label>
                                            {if $policy.type == 'bool'}
                                                <select id="policy_{$role.id}_{$policySection.module_id|escape}_{$policy.key|escape}" name="policies[{$policySection.module_id|escape}][{$policy.key|escape}]">
                                                    <option value="__inherit__" {if !$policy.is_explicit}selected{/if}>По умолчанию</option>
                                                    <option value="1" {if $policy.is_explicit && $policy.value == '1'}selected{/if}>Разрешено</option>
                                                    <option value="0" {if $policy.is_explicit && $policy.value == '0'}selected{/if}>Запрещено</option>
                                                </select>
                                            {elseif $policy.type == 'int'}
                                                <input id="policy_{$role.id}_{$policySection.module_id|escape}_{$policy.key|escape}" name="policies[{$policySection.module_id|escape}][{$policy.key|escape}]" type="number" min="0" step="1" value="{if $policy.is_explicit}{$policy.value|escape}{/if}" placeholder="наследовать">
                                            {else}
                                                <input id="policy_{$role.id}_{$policySection.module_id|escape}_{$policy.key|escape}" name="policies[{$policySection.module_id|escape}][{$policy.key|escape}]" type="text" value="{if $policy.is_explicit}{$policy.value|escape}{/if}" placeholder="наследовать">
                                            {/if}
                                            <small>{$policy.help|escape}</small>
                                        </div>
                                    {/foreach}
                                </div>
                            </div>
                        {/foreach}
                    </div>
                    <div class="custom-fields-form__footer">
                        <small>Пустое значение означает наследование платформенного значения. Для числовых политик 0 означает отсутствие дополнительного ограничения роли.</small>
                        <button type="submit" class="admin-action admin-action--primary"><i class="fa fa-sliders" aria-hidden="true"></i> Сохранить ограничения</button>
                    </div>
                </form>
            {/if}
        </section>
    {/foreach}

    <section class="admin-panel-card" aria-labelledby="role-assignments-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Назначения</span>
                <h2 id="role-assignments-title">Роли пользователей</h2>
                <p>Пользователь может иметь несколько ролей. Эффективные разрешения объединяются, а политики рассчитываются по правилам RolePolicyService.</p>
            </div>
        </div>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Пользователь</th><th>Статус</th><th>Роли</th><th>Действие</th></tr></thead>
                <tbody>
                    {foreach $roleUsers as $listedUser}
                        <tr>
                            <td data-label="Пользователь"><strong>@{$listedUser.username|escape}</strong><small>{$listedUser.email|escape}</small></td>
                            <td data-label="Статус">{$listedUser.status_label|escape}</td>
                            <td data-label="Роли">
                                <form action="{route_path name='admin_roles_assign'}" method="post" class="admin-user-actions">
                                    {csrf_token}
                                    <input type="hidden" name="user_id" value="{$listedUser.id}">
                                    {foreach $listedUser.role_choices as $assignableRole}
                                        <label class="custom-field__required">
                                            <input type="checkbox" name="role_ids[]" value="{$assignableRole.id}" {if $assignableRole.checked}checked{/if} {if $listedUser.is_self}disabled{/if}>
                                            {$assignableRole.name|escape}
                                        </label>
                                    {/foreach}
                                    {if !$listedUser.is_self}
                                        <button type="submit" class="admin-action admin-action--secondary">Применить</button>
                                    {else}
                                        <span class="admin-user-actions__locked">Текущий аккаунт защищён</span>
                                    {/if}
                                </form>
                            </td>
                            <td data-label="Действие">{if $listedUser.is_self}—{else}Сохранение в строке{/if}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </section>
</section>
{/block}