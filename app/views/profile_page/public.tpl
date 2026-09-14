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
                    <p>Здесь показываются только объекты, которые владелец явно разрешил публиковать в профиле.</p>
                </div>
            </div>

            {if !empty($public_content)}
                <div class="profile-public__items">
                    {foreach $public_content as $item}
                        <a class="profile-public__item" href="{$item.url|escape}">
                            <strong>{$item.title|escape}</strong>
                            <span>{$item.type|escape}</span>
                        </a>
                    {/foreach}
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
