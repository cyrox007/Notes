{extends file='core/base.tpl'}
{block name=title}Блокнот{/block}
{block name=body}
<section class="content-header">
    <h1>Блокнот</h1>
</section>

<section class="notes">
    <form class="notes__create" action="{route_path name="note_create"}" method="post">
        {csrf_token}
        <div class="notes__input">
            <input type="text" name="notename" maxlength="255" placeholder="Введите название новой заметки...">
        </div>
        <div class="notes__submit">
            <button type="submit">Создать</button>
        </div>
    </form>

    {if $isAdmin}
        <div id="show-all-notes">
            <p>Показать метаданные заметок всех пользователей</p>
            <button class="switch-btn" type="button" aria-pressed="false" aria-label="Показать все заметки"></button>
        </div>
    {/if}

    <div class="notes__content">
        <h3 class="notes__title">Список записей</h3>
        <div class="notes__list_head">
            <div class="notes__list_head--name">
                {if isset($smarty.get.sort) && $smarty.get.sort == 'notename' && isset($smarty.get.direction) && $smarty.get.direction == 'asc'}
                    <a href="?sort=notename&direction=desc">Название ▲</a>
                {else}
                    <a href="?sort=notename&direction=asc">Название ▼</a>
                {/if}
            </div>
            {if $isAdmin}<div class="notes__list_head--author" style="display:none;">Автор</div>{/if}
            <div class="notes__list_head--date">
                {if isset($smarty.get.sort) && $smarty.get.sort == 'created_note' && isset($smarty.get.direction) && $smarty.get.direction == 'asc'}
                    <a href="?sort=created_note&direction=desc">Дата создания ▲</a>
                {else}
                    <a href="?sort=created_note&direction=asc">Дата создания ▼</a>
                {/if}
            </div>
            <div class="notes__list_head--btn"></div>
        </div>

        <div id="personal" class="notes__list visible">
            {if $personalNotes}
                {foreach $personalNotes as $note}
                    {include file="^elements/note_item/index.tpl" note=$note user=$user readOnly=false}
                {/foreach}
            {else}
                <div class="notes__list_item"><p>Здесь ничего нет</p></div>
            {/if}
        </div>

        {if $isAdmin}
            <div id="all-user" class="notes__list">
                {foreach $allNotes as $anote}
                    {include file="^elements/note_item/index.tpl" note=$anote user=$user showAuthor=true readOnly=true}
                {foreachelse}
                    <div class="notes__list_item"><p>Заметок нет</p></div>
                {/foreach}
            </div>
        {/if}
    </div>
</section>

<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('.switch-btn');
    const personal = document.getElementById('personal');
    const allUsers = document.getElementById('all-user');
    const authorColumn = document.querySelector('.notes__list_head--author');
    if (!toggle || !personal || !allUsers) return;

    toggle.addEventListener('click', () => {
        const enabled = !toggle.classList.contains('switch-on');
        toggle.classList.toggle('switch-on', enabled);
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        personal.classList.toggle('visible', !enabled);
        allUsers.classList.toggle('visible', enabled);
        if (authorColumn) authorColumn.style.display = enabled ? 'block' : 'none';
    });
});
{/literal}
</script>
{/block}
