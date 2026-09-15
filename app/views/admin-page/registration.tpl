{extends file="core/base.tpl"}
{block name=title}Регистрация пользователей{/block}
{block name=body}
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Доступ к Workspace</span>
            <h1>Регистрация пользователей</h1>
            <p>Управляйте публичной регистрацией и приглашениями. Создание пользователей администратором доступно в основном разделе админпанели.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="{route_path name='adminpanel'}">Назад в админпанель</a>
    </header>

    {if $registration_flash}
        <div class="admin-page__flash admin-page__flash--{$registration_flash.type|escape}" role="status">
            {$registration_flash.message|escape}
            {if $registration_flash.invite_code}
                <div class="custom-field__control" style="margin-top:12px">
                    <label for="new_invite_code">Новый код приглашения</label>
                    <input id="new_invite_code" type="text" readonly value="{$registration_flash.invite_code|escape}" onclick="this.select()">
                    <small>Код хранится в системе только в виде SHA-256 hash и повторно показан не будет.</small>
                </div>
            {/if}
        </div>
    {/if}

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Политика доступа</span>
                <h2>Режим регистрации</h2>
                <p>По умолчанию регистрация закрыта. Изменение режима не влияет на уже созданные аккаунты.</p>
            </div>
        </div>

        <form action="{route_path name='admin_registration_mode'}" method="post" class="custom-fields-form">
            {csrf_token}
            <div class="custom-field__control">
                <label for="registration_mode">Режим</label>
                <select id="registration_mode" name="registration_mode" required>
                    <option value="disabled" {if $registration_mode == 'disabled'}selected{/if}>Закрыта — пользователей создаёт администратор</option>
                    <option value="open" {if $registration_mode == 'open'}selected{/if}>Свободная регистрация</option>
                    <option value="invite" {if $registration_mode == 'invite'}selected{/if}>Только по инвайтам</option>
                </select>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить режим</button>
        </form>

        {if $legacy_invite_configured}
            <p class="form-hint">Обнаружен legacy `REGISTRATION_INVITE_CODE` в `.env`. Он используется только пока новый режим ещё не сохранён в системных настройках. После сохранения режима управляйте приглашениями здесь.</p>
        {/if}
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Invite-only</span>
                <h2>Создать приглашение</h2>
                <p>Код показывается один раз. В базе хранится только hash, срок действия и счётчик использований.</p>
            </div>
        </div>

        <form action="{route_path name='admin_registration_invite_create'}" method="post" class="custom-fields-form">
            {csrf_token}
            <div class="custom-field__grid">
                <div class="custom-field__control">
                    <label for="invite_label">Название</label>
                    <input id="invite_label" name="label" type="text" maxlength="100" placeholder="Например, команда разработки">
                </div>
                <div class="custom-field__control">
                    <label for="invite_max_uses">Использований</label>
                    <input id="invite_max_uses" name="max_uses" type="number" min="1" max="1000" value="1" required>
                </div>
                <div class="custom-field__control">
                    <label for="invite_expires_at">Действует до</label>
                    <input id="invite_expires_at" name="expires_at" type="datetime-local">
                </div>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Создать инвайт</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Приглашения</span>
                <h2>Управляемые инвайты</h2>
                <p>Отозванные, просроченные и полностью использованные коды больше не допускают регистрацию.</p>
            </div>
        </div>

        {if $registration_invites}
            <div class="admin-users-table-wrap">
                <table class="admin-users-table">
                    <thead>
                        <tr><th>Название</th><th>Использовано</th><th>Срок</th><th>Статус</th><th>Действие</th></tr>
                    </thead>
                    <tbody>
                        {foreach $registration_invites as $invite}
                            <tr>
                                <td data-label="Название"><strong>{$invite.label|escape}</strong><br><small>{$invite.created_at|escape}</small></td>
                                <td data-label="Использовано">{$invite.used_count}/{$invite.max_uses}</td>
                                <td data-label="Срок">{if $invite.expires_at}{$invite.expires_at|escape}{else}Без срока{/if}</td>
                                <td data-label="Статус">
                                    {if $invite.status == 'active'}Активен
                                    {elseif $invite.status == 'revoked'}Отозван
                                    {elseif $invite.status == 'expired'}Просрочен
                                    {else}Исчерпан{/if}
                                </td>
                                <td data-label="Действие">
                                    {if $invite.status == 'active'}
                                        <form action="{route_path name='admin_registration_invite_revoke'}" method="post">
                                            {csrf_token}
                                            <input type="hidden" name="invite_id" value="{$invite.id|escape}">
                                            <button type="submit" class="admin-action admin-action--danger">Отозвать</button>
                                        </form>
                                    {else}—{/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {else}
            <p>Управляемых инвайтов пока нет.</p>
        {/if}
    </section>
</section>
{/block}
