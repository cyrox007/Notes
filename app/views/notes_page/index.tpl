{extends file='core/base.tpl'}
{block name=title}Блокнот{/block}
{block name=body}
<section class="content-header notes-page-header">
    <div>
        <span class="ux-kicker">Заметки</span>
        <h1>Блокнот</h1>
        <p>Личное пространство для быстрых записей, файлов и голосовых заметок.</p>
    </div>
</section>

<section class="notes notes--013">
    <section class="notes-create-card" aria-labelledby="notes-create-title">
        <div class="notes-create-card__intro">
            <span class="notes-create-card__icon" aria-hidden="true"><i class="fa fa-plus"></i></span>
            <div>
                <h2 id="notes-create-title">Новая заметка</h2>
                <p>Создайте запись и сразу продолжите работу в редакторе.</p>
            </div>
        </div>
        <form class="notes__create" action="{route_path name='note_create'}" method="post">
            {csrf_token}
            <label class="notes__input">
                <span class="sr-only">Название новой заметки</span>
                <input type="text" name="notename" maxlength="255" required autocomplete="off" placeholder="Например: План на неделю">
            </label>
            <div class="notes__submit">
                <button type="submit">
                    <i class="fa fa-plus" aria-hidden="true"></i>
                    Создать
                </button>
            </div>
        </form>
    </section>

    {if $isAdmin}
        <section id="show-all-notes" class="notes-scope" aria-label="Режим просмотра заметок">
            <div>
                <strong>Администраторский просмотр</strong>
                <span>Показывать метаданные заметок всех пользователей без доступа к их содержимому.</span>
            </div>
            <button class="switch-btn" type="button" aria-pressed="false" aria-label="Показать метаданные заметок всех пользователей"></button>
        </section>
    {/if}

    <section class="notes__content" aria-labelledby="notes-list-title">
        <header class="notes-list-header">
            <div>
                <span class="ux-kicker">Библиотека</span>
                <h2 id="notes-list-title">Мои заметки</h2>
                <p class="notes-list-header__summary">
                    {if !empty($pagination.q)}Результаты поиска по «{$pagination.q|escape}»{else}Недавние и сохранённые записи{/if}
                </p>
            </div>

            <form class="notes-sort" method="get" action="{route_path name='notes'}" aria-label="Сортировка заметок">
                {if !empty($pagination.q)}<input type="hidden" name="q" value="{$pagination.q|escape}">{/if}
                <input type="hidden" name="limit" value="{$pagination.limit|default:20|escape}">
                <input type="hidden" name="page" value="1">
                <label>
                    <span>Сортировка</span>
                    <select name="sort">
                        <option value="updated_note"{if $pagination.sort|default:'' == 'updated_note'} selected{/if}>По изменению</option>
                        <option value="created_note"{if $pagination.sort|default:'created_note' == 'created_note'} selected{/if}>По созданию</option>
                        <option value="notename"{if $pagination.sort|default:'' == 'notename'} selected{/if}>По названию</option>
                    </select>
                </label>
                <label>
                    <span class="sr-only">Направление сортировки</span>
                    <select name="direction" aria-label="Направление сортировки">
                        <option value="desc"{if $pagination.direction|default:'desc' == 'desc'} selected{/if}>Сначала новые</option>
                        <option value="asc"{if $pagination.direction|default:'desc' == 'asc'} selected{/if}>Сначала старые</option>
                    </select>
                </label>
                <button type="submit" aria-label="Применить сортировку"><i class="fa fa-sort" aria-hidden="true"></i></button>
            </form>
        </header>

        <div id="personal" class="notes__list visible" aria-live="polite">
            {if $personalNotes}
                {foreach $personalNotes as $note}
                    {include file="^elements/note_item/index.tpl" note=$note user=$user readOnly=false}
                {/foreach}
            {else}
                <div class="notes-empty-state">
                    <span class="notes-empty-state__icon" aria-hidden="true"><i class="fa fa-sticky-note-o"></i></span>
                    {if !empty($pagination.q)}
                        <h3>Ничего не найдено</h3>
                        <p>Попробуйте изменить запрос или сбросить поиск.</p>
                        <a href="{route_path name='notes'}">Показать все заметки</a>
                    {else}
                        <h3>Заметок пока нет</h3>
                        <p>Создайте первую заметку — она появится здесь и будет доступна только вам.</p>
                    {/if}
                </div>
            {/if}
        </div>

        {if $isAdmin}
            <div id="all-user" class="notes__list" aria-live="polite" hidden>
                {foreach $allNotes as $anote}
                    {include file="^elements/note_item/index.tpl" note=$anote user=$user showAuthor=true readOnly=true}
                {foreachelse}
                    <div class="notes-empty-state">
                        <span class="notes-empty-state__icon" aria-hidden="true"><i class="fa fa-users"></i></span>
                        <h3>Нет заметок пользователей</h3>
                        <p>Для текущего фильтра метаданные не найдены.</p>
                    </div>
                {/foreach}
            </div>
        {/if}
    </section>
</section>

<script src="{$base_url}/assets/js/notes-list-013.js" defer></script>
{/block}
