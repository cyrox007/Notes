
<div class="notes__list_item">
    <div class="notes__name">
        {$note.notename|default:'Без названия'}
    </div>
    {if isset($showAuthor) && $showAuthor}
        <div class="notes__author">
            {$note.author_username|default:'Неизвестно'}
        </div>
    {/if}
    <div class="notes__date">
        Создано: {$note.created_note} <br>
        {if $note.created_note != $note.updated_note}
            Редактировано: {$note.updated_note}
        {/if}
    </div>
    <div class="notes__btn">
        <a class="notes__btn--edit" href="{route_path name="edit_page" uid=$note.uid}" title="Редактировать">
            <i class="fa fa-pencil" aria-hidden="true"></i>
        </a>
        <a class="notes__btn--delete" href="{route_path name="delete_note" uid=$note.uid}" title="Удалить" onclick="return confirm('Вы уверены, что хотите удалить эту заметку?')">
            <i class="fa fa-trash-o" aria-hidden="true"></i>
        </a>
    </div>
</div>