{assign var=profileMetrics value=$publication_items.metrics|default:[]}
{if !empty($profileMetrics)}
<section class="profile-metrics ux-section" aria-labelledby="profile-metrics-title">
    <div class="profile-metrics__heading">
        <div>
            <span class="ux-kicker">Сводка</span>
            <h2 id="profile-metrics-title">Мой workspace</h2>
            <p>Быстрый обзор личных данных без отдельного тяжёлого dashboard.</p>
        </div>
        <button type="button" class="profile-metrics__settings" data-profile-edit aria-controls="profile-account-settings" aria-expanded="false">
            <i class="fa fa-cog" aria-hidden="true"></i>
            Настройки аккаунта
        </button>
    </div>

    <div class="profile-metrics__grid">
        <a class="profile-metric profile-metric--notes" href="{route_path name='notes'}">
            <span class="profile-metric__icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
            <span class="profile-metric__body">
                <span class="profile-metric__value">{$profileMetrics.notes_count|default:0|escape}</span>
                <span class="profile-metric__label">Заметок</span>
            </span>
            <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
        </a>

        <a class="profile-metric profile-metric--tasks" href="{route_path name='tasks'}">
            <span class="profile-metric__icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
            <span class="profile-metric__body">
                <span class="profile-metric__value">{$profileMetrics.tasks_count|default:0|escape}</span>
                <span class="profile-metric__label">Задач · {$profileMetrics.open_tasks_count|default:0|escape} активных</span>
            </span>
            <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
        </a>

        <a class="profile-metric profile-metric--files" href="{route_path name='files'}">
            <span class="profile-metric__icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
            <span class="profile-metric__body">
                <span class="profile-metric__value">{$profileMetrics.files_count|default:0|escape}</span>
                <span class="profile-metric__label">Файлов</span>
            </span>
            <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
        </a>

        <a class="profile-metric profile-metric--storage" href="{route_path name='files'}">
            <span class="profile-metric__icon"><i class="fa fa-hdd-o" aria-hidden="true"></i></span>
            <span class="profile-metric__body">
                <span class="profile-metric__value">{$profileMetrics.storage.percent|default:0|escape}%</span>
                <span class="profile-metric__label">{$profileMetrics.storage.used_label|default:'0 Б'|escape} из {$profileMetrics.storage.quota_label|default:'—'|escape}</span>
                <progress class="profile-metric__progress" value="{$profileMetrics.storage.percent|default:0|escape}" max="100" aria-label="Использовано {$profileMetrics.storage.percent|default:0|escape}% хранилища"></progress>
            </span>
            <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
        </a>
    </div>
</section>
{/if}

<section class="profile-publication ux-section" aria-labelledby="profile-publication-title">
    <div class="ux-section__heading profile-publication__heading">
        <div>
            <span class="ux-kicker">Публичный профиль</span>
            <h2 id="profile-publication-title">Что видят другие пользователи</h2>
            <p>Публикация здесь отдельна от share-ссылок. По умолчанию все заметки, задачи и файлы остаются приватными.</p>
        </div>
        <a class="profile-publication__preview" href="{route_path name='profile-public' uid=$user.uid}">
            <i class="fa fa-eye" aria-hidden="true"></i>
            Предпросмотр
        </a>
    </div>

    <div class="profile-publication__columns">
        <section class="profile-publication__group" aria-labelledby="publication-notes-title">
            <div class="profile-publication__group-title">
                <i class="fa fa-sticky-note-o" aria-hidden="true"></i>
                <h3 id="publication-notes-title">Заметки</h3>
            </div>
            {if !empty($publication_items.notes)}
                <div class="profile-publication__list">
                    {foreach $publication_items.notes as $item}
                        <article class="profile-publication__item{if $item.is_profile_public} is-public{/if}">
                            <div>
                                <strong>{$item.title|escape}</strong>
                                <span>{if $item.is_profile_public}Виден в профиле{else}Приватно{/if}</span>
                            </div>
                            <form action="{route_path name='profile-publication'}" method="post">
                                {csrf_token}
                                <input type="hidden" name="type" value="note">
                                <input type="hidden" name="uid" value="{$item.uid|escape}">
                                <input type="hidden" name="public" value="{if $item.is_profile_public}0{else}1{/if}">
                                <button type="submit" class="profile-publication__toggle">
                                    {if $item.is_profile_public}Скрыть{else}Опубликовать{/if}
                                </button>
                            </form>
                        </article>
                    {/foreach}
                </div>
            {else}
                <p class="profile-publication__empty">Нет заметок для публикации.</p>
            {/if}
        </section>

        <section class="profile-publication__group" aria-labelledby="publication-tasks-title">
            <div class="profile-publication__group-title">
                <i class="fa fa-check-square-o" aria-hidden="true"></i>
                <h3 id="publication-tasks-title">Задачи</h3>
            </div>
            {if !empty($publication_items.tasks)}
                <div class="profile-publication__list">
                    {foreach $publication_items.tasks as $item}
                        <article class="profile-publication__item{if $item.is_profile_public} is-public{/if}">
                            <div>
                                <strong>{$item.title|escape}</strong>
                                <span>{if $item.is_profile_public}Виден в профиле{else}Приватно{/if} · {$item.status|escape}</span>
                            </div>
                            <form action="{route_path name='profile-publication'}" method="post">
                                {csrf_token}
                                <input type="hidden" name="type" value="task">
                                <input type="hidden" name="uid" value="{$item.uid|escape}">
                                <input type="hidden" name="public" value="{if $item.is_profile_public}0{else}1{/if}">
                                <button type="submit" class="profile-publication__toggle">
                                    {if $item.is_profile_public}Скрыть{else}Опубликовать{/if}
                                </button>
                            </form>
                        </article>
                    {/foreach}
                </div>
            {else}
                <p class="profile-publication__empty">Нет задач для публикации.</p>
            {/if}
        </section>

        <section class="profile-publication__group" aria-labelledby="publication-files-title">
            <div class="profile-publication__group-title">
                <i class="fa fa-folder-open-o" aria-hidden="true"></i>
                <h3 id="publication-files-title">Файлы</h3>
            </div>
            {if !empty($publication_items.files)}
                <div class="profile-publication__list">
                    {foreach $publication_items.files as $item}
                        <article class="profile-publication__item{if $item.is_profile_public} is-public{/if}">
                            <div>
                                <strong>{$item.name|escape}{if $item.extension}.{$item.extension|escape}{/if}</strong>
                                <span>{if $item.is_profile_public}Виден в профиле{else}Приватно{/if}</span>
                            </div>
                            <form action="{route_path name='profile-publication'}" method="post">
                                {csrf_token}
                                <input type="hidden" name="type" value="file">
                                <input type="hidden" name="uid" value="{$item.uid|escape}">
                                <input type="hidden" name="public" value="{if $item.is_profile_public}0{else}1{/if}">
                                <button type="submit" class="profile-publication__toggle">
                                    {if $item.is_profile_public}Скрыть{else}Опубликовать{/if}
                                </button>
                            </form>
                        </article>
                    {/foreach}
                </div>
            {else}
                <p class="profile-publication__empty">Нет файлов для публикации.</p>
            {/if}
        </section>
    </div>
</section>
