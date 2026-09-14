{extends file="core/base.tpl"}
{block name=title}Админпанель{/block}
{block name=body}
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Управление Workspace</span>
            <h1>Админпанель</h1>
            <p>Управление аккаунтами и дополнительными полями профиля без физического удаления рабочих данных.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="{route_path name='admin_settings'}">
            <i class="fa fa-sliders" aria-hidden="true"></i>
            Настройки и квоты
        </a>
    </header>

    {if $admin_flash}
        <div class="admin-page__flash admin-page__flash--{$admin_flash.type|escape}" role="status">
            {$admin_flash.message|escape}
        </div>
    {/if}

    <section class="admin-panel-card" aria-labelledby="admin-users-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Аккаунты</span>
                <h2 id="admin-users-title">Пользователи</h2>
                <p>Блокировка запрещает вход. Деактивация сохраняет Notes, Tasks, Messenger и другие связанные данные.</p>
            </div>
        </div>

        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead>
                    <tr>
                        <th scope="col">Пользователь</th>
                        <th scope="col">Роль</th>
                        <th scope="col">Статус</th>
                        <th scope="col">Создан</th>
                        <th scope="col">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $users as $listedUser}
                        <tr>
                            <td data-label="Пользователь">
                                <strong>{$listedUser.firstname|escape} {$listedUser.lastname|escape}</strong>
                                <span>@{$listedUser.username|escape}</span>
                                <small>{$listedUser.email|escape}</small>
                            </td>
                            <td data-label="Роль">{$listedUser.role_label|escape}</td>
                            <td data-label="Статус"><span class="admin-status admin-status--{$listedUser.status_code|escape}">{$listedUser.status_label|escape}</span></td>
                            <td data-label="Создан">{$listedUser.created_at|escape}</td>
                            <td data-label="Действия">
                                <div class="admin-user-actions">
                                    {if $listedUser.can_manage}
                                        {if $listedUser.status_code == 'inactive' || $listedUser.status_code == 'blocked'}
                                            <form action="{route_path name='admin_toggle_user'}" method="post">
                                                {csrf_token}<input type="hidden" name="user_id" value="{$listedUser.id}"><input type="hidden" name="new_status" value="active">
                                                <button type="submit" class="admin-action admin-action--secondary"><i class="fa fa-check-circle" aria-hidden="true"></i> Активировать</button>
                                            </form>
                                        {else}
                                            <form action="{route_path name='admin_toggle_user'}" method="post">
                                                {csrf_token}<input type="hidden" name="user_id" value="{$listedUser.id}"><input type="hidden" name="new_status" value="blocked">
                                                <button type="submit" class="admin-action admin-action--secondary"><i class="fa fa-ban" aria-hidden="true"></i> Блокировать</button>
                                            </form>
                                        {/if}
                                        {if $listedUser.status_code != 'inactive'}
                                            <form action="{route_path name='admin_delete_user'}" method="post" data-confirm-deactivate="@{$listedUser.username|escape:'htmlall'}">
                                                {csrf_token}<input type="hidden" name="user_id" value="{$listedUser.id}">
                                                <button type="submit" class="admin-action admin-action--danger"><i class="fa fa-user-times" aria-hidden="true"></i> Деактивировать</button>
                                            </form>
                                        {/if}
                                    {else}<span class="admin-user-actions__locked">{if $listedUser.id == $user.id}Текущий аккаунт{else}Защищённая роль{/if}</span>{/if}
                                </div>
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </section>

    <section class="admin-panel-card" aria-labelledby="admin-fields-title">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Профиль</span><h2 id="admin-fields-title">Дополнительные поля</h2><p>Техническое имя используется в сохранённых данных профиля. После запуска в production меняйте его только осознанно.</p></div>
            <button type="button" id="add-field-btn" class="admin-action admin-action--primary"><i class="fa fa-plus" aria-hidden="true"></i> Добавить поле</button>
        </div>
        <form action="{route_path name='save_custom_fields'}" method="post" class="custom-fields-form">
            {csrf_token}
            <div id="custom-fields-container" class="custom-fields-list">
                {foreach $customFields as $field}
                    <div class="custom-field" data-field-key="{$field.id}">
                        <div class="custom-field__grid">
                            <div class="custom-field__control"><label for="field_name_{$field.id}">Техническое имя</label><input type="text" id="field_name_{$field.id}" name="fields[{$field.id}][field_name]" value="{$field.field_name|escape}" maxlength="50" pattern="[a-z][a-z0-9_]{0,49}" required autocomplete="off"></div>
                            <div class="custom-field__control"><label for="field_label_{$field.id}">Метка</label><input type="text" id="field_label_{$field.id}" name="fields[{$field.id}][field_label]" value="{$field.field_label|escape}" maxlength="100" required autocomplete="off"></div>
                            <div class="custom-field__control"><label for="field_type_{$field.id}">Тип</label><select id="field_type_{$field.id}" name="fields[{$field.id}][field_type]" required><option value="text" {if $field.field_type == 'text'}selected{/if}>Текст</option><option value="textarea" {if $field.field_type == 'textarea'}selected{/if}>Многострочный текст</option><option value="number" {if $field.field_type == 'number'}selected{/if}>Число</option><option value="date" {if $field.field_type == 'date'}selected{/if}>Дата</option><option value="checkbox" {if $field.field_type == 'checkbox'}selected{/if}>Флажок</option><option value="select" {if $field.field_type == 'select'}selected{/if}>Select (legacy, без вариантов)</option></select></div>
                            <label class="custom-field__required" for="is_required_{$field.id}"><input type="checkbox" id="is_required_{$field.id}" name="fields[{$field.id}][is_required]" {if $field.is_required}checked{/if}> Обязательное</label>
                            <button type="button" class="remove-field admin-action admin-action--danger" aria-label="Удалить поле {$field.field_label|escape}"><i class="fa fa-trash" aria-hidden="true"></i> Удалить</button>
                        </div>
                    </div>
                {/foreach}
            </div>
            <div class="custom-fields-form__footer"><small>Удаление поля из списка удаляет его определение после сохранения, но не выполняет физическое удаление аккаунтов пользователей.</small><button type="submit" class="admin-action admin-action--primary"><i class="fa fa-save" aria-hidden="true"></i> Сохранить поля</button></div>
        </form>
    </section>
</section>
<script src="{$base_url}/assets/js/admin-page.js"></script>
{/block}