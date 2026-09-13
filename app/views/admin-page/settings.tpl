{extends file="core/base.tpl"}
{block name=title}Системные настройки{/block}
{block name=body}
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Хранилище Workspace</span>
            <h1>Системные настройки</h1>
            <p>Общий лимит файлового менеджера и персональные квоты пользователей.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="{route_path name='adminpanel'}">Назад в админпанель</a>
    </header>

    {if $settings_flash}
        <div class="admin-page__flash admin-page__flash--{$settings_flash.type|escape}" role="status">
            {$settings_flash.message|escape}
        </div>
    {/if}

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">По умолчанию</span>
                <h2>Лимит хранилища</h2>
                <p>Используется для пользователей без персонального override.</p>
            </div>
        </div>
        <form action="{route_path name='admin_settings_default_quota'}" method="post" class="custom-fields-form">
            {csrf_token}
            <div class="custom-field__control">
                <label for="default_quota_mb">Лимит, МБ</label>
                <input id="default_quota_mb" name="default_quota_mb" type="number" min="10" max="10485760" step="1" value="{($default_quota_bytes / 1048576)|round}" required>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить лимит</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Пользователи</span>
                <h2>Использование хранилища</h2>
                <p>Фактический объём считается из активных файлов, отдельный счётчик usage не хранится.</p>
            </div>
        </div>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead>
                    <tr><th>Пользователь</th><th>Использовано</th><th>Лимит</th><th>Заполнение</th><th>Персональный лимит</th></tr>
                </thead>
                <tbody>
                    {foreach $storage_users as $storageUser}
                        <tr>
                            <td data-label="Пользователь"><strong>@{$storageUser.username|escape}</strong><br><small>{$storageUser.email|escape}</small></td>
                            <td data-label="Использовано">{($storageUser.used_bytes / 1048576)|round:2} МБ</td>
                            <td data-label="Лимит">{($storageUser.effective_quota / 1048576)|round:2} МБ</td>
                            <td data-label="Заполнение">{$storageUser.percent|escape}%</td>
                            <td data-label="Персональный лимит">
                                <form action="{route_path name='admin_settings_user_quota'}" method="post" class="admin-user-actions">
                                    {csrf_token}
                                    <input type="hidden" name="user_id" value="{$storageUser.id}">
                                    <input type="number" name="quota_mb" min="10" max="10485760" step="1"
                                           value="{if $storageUser.has_override}{($storageUser.override_quota / 1048576)|round}{/if}"
                                           placeholder="по умолчанию" aria-label="Персональная квота для {$storageUser.username|escape}">
                                    <button type="submit" class="admin-action admin-action--secondary">Применить</button>
                                </form>
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </section>
</section>
{/block}
