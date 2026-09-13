<div class="notes__list_item">
    <div class="notes__name">
        {$note.notename|default:'Без названия'|escape}
    </div>
    {if isset($showAuthor) && $showAuthor}
        <div class="notes__author">
            {$note.author_username|default:'Неизвестно'|escape}
        </div>
    {/if}
    <div class="notes__date">
        Создано: {$note.created_note|escape} <br>
        {if $note.created_note != $note.updated_note}
            Редактировано: {$note.updated_note|escape}
        {/if}
    </div>
    <div class="notes__btn">
        {if !isset($readOnly) || !$readOnly}
            <a class="notes__btn--edit" href="{route_path name="edit_page" uid=$note.uid}" title="Редактировать">
                <i class="fa fa-pencil" aria-hidden="true"></i>
            </a>
            <form action="{route_path name='delete_note' uid=$note.uid}" method="post" class="notes__delete-form" onsubmit="return confirm('Вы уверены, что хотите удалить эту заметку?')">
                {csrf_token}
                <button class="notes__btn--delete" type="submit" title="Удалить">
                    <i class="fa fa-trash-o" aria-hidden="true"></i>
                </button>
            </form>
        {else}
            <span title="Для чужих заметок доступен только просмотр метаданных" aria-label="Только метаданные">
                <i class="fa fa-lock" aria-hidden="true"></i>
            </span>
        {/if}
    </div>
</div>
