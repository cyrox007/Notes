<div class="notes__list_item">
    <div class="notes__name">
        {$note.notename}
    </div>
    {if isset($showAuthor) && $showAuthor}
        <div class="notes__author">
            {$note.username}
        </div>
    {/if}
    <div class="notes__date">
        Создано: {$note.created_note} <br>
        {if $note.created_note != $note.updated_note}
            Редактировано: {$note.updated_note}
        {/if}
    </div>
    <div class="notes__btn">
        <a class="notes__btn--edit" href="{route_path name="edit_page" uid=$note.uid}">
            <i class="fa fa-pencil" aria-hidden="true"></i>
        </a>
        <a class="notes__btn--delete" href="{route_path name="delete_note" uid=$note.uid}">
            <i class="fa fa-trash" aria-hidden="true"></i>
        </a>
    </div>
</div>