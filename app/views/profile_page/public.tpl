{extends file="core/base.tpl"}

{block name=title}
    {if $profile}{$profile.firstname|escape} {$profile.lastname|escape}{else}Профиль не найден{/if}
{/block}

{block name=body}
<section class="profile-public">
    {if !$profile}
        <div class="ux-empty">
            <div>
                <i class="fa fa-user-times" aria-hidden="true"></i>
                <h1>Профиль не найден</h1>
                <p>Пользователь не существует или больше недоступен.</p>
            </div>
        </div>
    {else}
        <header class="profile-public__hero ux-section">
            <div class="profile-public__identity">
                <div class="profile-public__avatar-wrap">
                    {if $avatar_url}
                        {assign var=publicAvatarPath value=$avatar_url|regex_replace:'#^/+#':''}
                        <img class="profile-public__avatar" src="{$base_url}/{$publicAvatarPath|escape}" alt="{$profile.firstname|escape} {$profile.lastname|escape}" width="112" height="112">
                    {else}
                        <img class="profile-public__avatar" src="{$base_url}/assets/img/default_avatar.png" alt="{$profile.firstname|escape} {$profile.lastname|escape}" width="112" height="112">
                    {/if}
                </div>
                <div class="profile-public__name">
                    <span class="ux-kicker">Профиль пользователя</span>
                    <h1>{$profile.firstname|escape} {$profile.lastname|escape}</h1>
                    <p>@{$profile.username|escape}</p>
                </div>
            </div>
        </header>

        <section class="profile-public__content ux-section" aria-labelledby="public-content-title">
            <div class="ux-section__heading">
                <div>
                    <span class="ux-kicker">Публично</span>
                    <h2 id="public-content-title">Материалы пользователя</h2>
                    <p>Здесь показываются только объекты, которые владелец явно опубликовал в своём профиле.</p>
                </div>
                {if $public_total|default:0 > 0}
                    <span class="profile-public__counter">{$public_total} опубликовано</span>
                {/if}
            </div>

            {if $public_total|default:0 > 0}
                <div class="profile-public__collections">
                    {if !empty($public_content.notes)}
                        <section class="profile-public__collection" aria-labelledby="public-notes-title">
                            <div class="profile-public__collection-title">
                                <i class="fa fa-sticky-note-o" aria-hidden="true"></i>
                                <h3 id="public-notes-title">Заметки</h3>
                            </div>
                            <div class="profile-public__items">
                                {foreach $public_content.notes as $item}
                                    <article class="profile-public__item">
                                        <strong>{$item.title|escape}</strong>
                                        <span>Публичная заметка</span>
                                    </article>
                                {/foreach}
                            </div>
                        </section>
                    {/if}

                    {if !empty($public_content.tasks)}
                        <section class="profile-public__collection" aria-labelledby="public-tasks-title">
                            <div class="profile-public__collection-title">
                                <i class="fa fa-check-square-o" aria-hidden="true"></i>
                                <h3 id="public-tasks-title">Задачи</h3>
                            </div>
                            <div class="profile-public__items">
                                {foreach $public_content.tasks as $item}
                                    <article class="profile-public__item">
                                        <strong>{$item.title|escape}</strong>
                                        <span>
                                            {if $item.status == 'completed'}Готово
                                            {elseif $item.status == 'in_progress'}В работе
                                            {elseif $item.status == 'cancelled'}Отменено
                                            {else}Новая{/if}
                                            · {$item.priority|escape}
                                        </span>
                                    </article>
                                {/foreach}
                            </div>
                        </section>
                    {/if}

                    {if !empty($public_content.files)}
                        <section class="profile-public__collection" aria-labelledby="public-files-title">
                            <div class="profile-public__collection-title">
                                <i class="fa fa-folder-open-o" aria-hidden="true"></i>
                                <h3 id="public-files-title">Файлы</h3>
                            </div>
                            <div class="profile-public__items">
                                {foreach $public_content.files as $item}
                                    <article class="profile-public__item">
                                        <strong>{$item.name|escape}{if $item.extension}.{$item.extension|escape}{/if}</strong>
                                        <span>Публичный файл · {$item.size|default:0} Б</span>
                                    </article>
                                {/foreach}
                            </div>
                        </section>
                    {/if}
                </div>
            {else}
                <div class="ux-empty profile-public__empty">
                    <div>
                        <i class="fa fa-lock" aria-hidden="true"></i>
                        <strong>Пользователь пока ничего не публиковал</strong>
                        <p>Share-ссылки и приватные заметки, задачи и файлы здесь автоматически не раскрываются.</p>
                    </div>
                </div>
            {/if}
        </section>
    {/if}
</section>
{/block}
