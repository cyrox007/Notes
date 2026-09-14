{extends file="core/base.tpl"}
{block name=title}
    {$user.firstname|escape} {$user.lastname|escape}
{/block}
{block name=body}
<section class="profile profile--hub">
    <header class="profile__hub-heading">
        <div>
            <span class="ux-kicker">Мой workspace</span>
            <h1>{$user.firstname|escape} {$user.lastname|escape}</h1>
            <p>Профиль, быстрый доступ к рабочим разделам и настройки аккаунта.</p>
        </div>
        <a class="profile__public-preview" href="{route_path name='profile-public' uid=$user.uid}">
            <i class="fa fa-eye" aria-hidden="true"></i>
            Посмотреть как другой пользователь
        </a>
    </header>

    {if !empty($errors)}
        <div class="profile__errors" role="alert">
            {foreach $errors as $error}
                <div class="profile__error">{$error.MESSAGE|escape}</div>
            {/foreach}
        </div>
    {/if}

    <div class="profile__column-left">
        <div class="profile__card-avatar">
            <span class="ux-kicker">Аккаунт</span>
            <p class="profile__user-login">@{$user.username|escape}</p>

            {if $avatar_url}
                {assign var=profileAvatarPath value=$avatar_url|regex_replace:'#^/+#':''}
                <img src="{$base_url}/{$profileAvatarPath|escape}" alt="{$user.firstname|escape} {$user.lastname|escape}" class="img-circle elevation-2" width="256" height="256">
            {else}
                <img src="{$base_url}/assets/img/default_avatar.png" alt="{$user.firstname|escape} {$user.lastname|escape}" class="img-circle elevation-2" width="256" height="256">
            {/if}

            <button class="profile__edit_user-info" type="button">
                <i class="fa fa-pencil" aria-hidden="true"></i>
                Редактировать профиль
            </button>

            {if $avatar_url}
                <form class="profile__avatar-delete" action="{route_path name='profile-avatar-delete'}" method="post" onsubmit="return confirm('Удалить фото профиля?');">
                    {csrf_token}
                    <button type="submit" class="profile__secondary-action">Удалить фото</button>
                </form>
            {/if}
        </div>
    </div>

    <div class="profile__column-right">
        <nav class="profile__workspace-links" aria-label="Мои разделы">
            <a class="profile__workspace-link profile__workspace-link--notes" href="{route_path name='notes'}">
                <span class="profile__workspace-icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                <span>
                    <strong>Мои заметки</strong>
                    <small>Идеи, записи, вложения и общий доступ</small>
                </span>
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </a>
            <a class="profile__workspace-link profile__workspace-link--tasks" href="{route_path name='tasks'}">
                <span class="profile__workspace-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                <span>
                    <strong>Мои задачи</strong>
                    <small>Планы, дедлайны и подзадачи</small>
                </span>
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </a>
            <a class="profile__workspace-link profile__workspace-link--files" href="{route_path name='files'}">
                <span class="profile__workspace-icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
                <span>
                    <strong>Мои файлы</strong>
                    <small>Приватное хранилище и загрузки</small>
                </span>
                <i class="fa fa-angle-right" aria-hidden="true"></i>
            </a>
        </nav>

        <div class="profile__card-info">
            <div class="profile__card-info--data visible">
                <div class="profile__info-heading">
                    <span class="ux-kicker">Профиль</span>
                    <div class="profile__user-fio">
                        {$user.firstname|escape} {$user.lastname|escape}
                    </div>
                </div>

                {if $user.phone}
                    <div class="profile__user-other-info">
                        <span class="profile__detail-label">Телефон</span>
                        <p class="profile__user-detals">{$user.phone|escape}</p>
                    </div>
                {/if}
                <div class="profile__user-other-info">
                    <span class="profile__detail-label">Email</span>
                    <p class="profile__user-detals">{$user.email|escape}</p>
                </div>

                {assign var="customData" value=[]}
                {if !empty($user.property)}
                    {jsonParse json=$user.property assign="customData"}
                    {foreach $customData as $props}
                        <div class="profile__user-other-info">
                            <span class="profile__detail-label">{$props.label|escape}</span>
                            <p class="profile__user-detals">{$props.value|escape}</p>
                        </div>
                    {/foreach}
                {/if}
            </div>

            <div class="profile__card-info--edit">
                <button id="close" type="button" aria-label="Закрыть редактирование">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>

                <form name="changeProfile" action="{route_path name='profile-set'}" method="post" enctype="multipart/form-data">
                    {csrf_token}
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-name">Имя:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-name"
                               id="user-name" value="{$user.firstname|escape}" maxlength="80" required autocomplete="given-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-patronymic">Отчество:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-patronymic"
                               id="user-patronymic" value="{$user.patronymic|default:''|escape}" maxlength="80" autocomplete="additional-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-surname">Фамилия:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-surname"
                               id="user-surname" value="{$user.lastname|escape}" maxlength="80" required autocomplete="family-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-phone">Телефон:</label>
                        <input class="profile__card-info--edit--set-input" type="tel" name="set-user-phone"
                               id="user-phone" value="{$user.phone|default:''|escape}" maxlength="20" autocomplete="tel">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-email">Email:</label>
                        <input class="profile__card-info--edit--set-input" type="email" name="set-user-email"
                               id="user-email" value="{$user.email|escape}" maxlength="190" required autocomplete="email">
                    </div>

                    <div class="profile__card-info--edit--form-group">
                        <label for="user-avatar">Изменить изображение пользователя:</label>
                        <input class="set-files" type="file" name="set-user-avatar" id="user-avatar"
                               accept="image/jpeg,image/png,image/webp">
                        <small>JPEG, PNG или WebP. Максимальный размер задаётся администратором.</small>
                    </div>

                    {assign var="userCustomData" value=[]}
                    {if !empty($user.property)}
                        {jsonParse json=$user.property assign="parsedData"}
                        {foreach from=$parsedData item=item}
                            {assign var="tempArray" value=$userCustomData}
                            {assign var="tempArray_item" value=[ $item.name => $item ]}
                            {assign var="userCustomData" value=$tempArray + $tempArray_item}
                        {/foreach}
                    {/if}

                    {foreach from=$fields item=field}
                        <div class="profile__card-info--edit--form-group">
                            <label for="{$field.field_name|escape}">{$field.field_label|escape}:</label>
                            {if $field.field_type == 'textarea'}
                                <textarea class="profile__card-info--edit--set-input"
                                          name="custom[{$field.field_name|escape}][value]"
                                          id="{$field.field_name|escape}"
                                          maxlength="5000"
                                          {if $field.is_required}required{/if}>{$userCustomData[$field.field_name].value|default:''|escape}</textarea>
                            {elseif $field.field_type == 'checkbox'}
                                <input type="checkbox"
                                       name="custom[{$field.field_name|escape}][value]"
                                       id="{$field.field_name|escape}"
                                       value="1"
                                       {if $userCustomData[$field.field_name].value|default:'' == '1'}checked{/if}>
                            {else}
                                <input class="profile__card-info--edit--set-input"
                                       type="{if $field.field_type == 'number'}number{elseif $field.field_type == 'date'}date{else}text{/if}"
                                       name="custom[{$field.field_name|escape}][value]"
                                       id="{$field.field_name|escape}"
                                       value="{$userCustomData[$field.field_name].value|default:''|escape}"
                                       {if $field.is_required}required{/if}>
                            {/if}
                        </div>
                    {/foreach}

                    <button class="profile__card-info--edit--set-save" type="submit">Сохранить изменения</button>
                </form>

                <hr>
                <form name="changePassword" action="{route_path name='profile-password-set'}" method="post">
                    {csrf_token}
                    <div class="profile__card-info--edit--form-group">
                        <label for="old-password">Текущий пароль:</label>
                        <input class="profile__card-info--edit--set-input" type="password" name="old-password"
                               id="old-password" required autocomplete="current-password">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="new-password">Новый пароль:</label>
                        <input class="profile__card-info--edit--set-input" type="password" name="new-password"
                               id="new-password" minlength="10" required autocomplete="new-password">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="repeat-new-password">Повторите пароль:</label>
                        <input class="profile__card-info--edit--set-input" type="password" name="repeat-new-password"
                               id="repeat-new-password" minlength="10" required autocomplete="new-password">
                    </div>
                    <span id="error-repeat" aria-live="polite"></span>
                    <button id="change-password-btn" class="profile__card-info--edit--set-save" type="submit">Изменить пароль</button>
                </form>

                <hr>
                <section class="profile__danger-zone">
                    <h3>Деактивация аккаунта</h3>
                    <p>Данные аккаунта сохранятся, но вход и действия в системе будут заблокированы. Если вы владелец группы, сначала передайте владение другому участнику.</p>
                    <form name="deleteUser" action="{route_path name='profile-delete'}" method="post" onsubmit="return confirm('Деактивировать аккаунт? Вход в систему будет заблокирован.');">
                        {csrf_token}
                        <div class="profile__card-info--edit--form-group">
                            <label for="deactivate-password">Текущий пароль:</label>
                            <input class="profile__card-info--edit--set-input" type="password" name="current_password"
                                   id="deactivate-password" required autocomplete="current-password">
                        </div>
                        <label class="profile__confirm-deactivate">
                            <input type="checkbox" name="confirm_delete" value="yes" required>
                            Я понимаю, что аккаунт будет деактивирован.
                        </label>
                        <button class="profile__card-info--edit--delete" type="submit">Деактивировать аккаунт</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</section>
<script>
    {include file="profile_page/script.js"}
</script>
{/block}
