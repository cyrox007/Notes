{extends file="core/base.tpl"}
{block name=title}
	Блокнот: Редактируем > {$note['notename']}
{/block}
{block name=body}
<section class="note-header">
	<h1 class="note__title">
		{$note.notename}
	</h1>
	<p class="note__info">Автор:
		{$note.author.username}
	</p>
	<p class="note__info">Дата создания:
		{$note.created_note}
	</p>
	<p class="note__info">Дата редактирования:
		{$note.updated_note}
	</p>
</section>

<section class="note-content">
	<form class="note__edit" action="{route_path name="update_note" uid="{$note['uid']}"}" method="post">
		{csrf_token}
		<div class="note__text">
			<textarea name="content" class="textarea"
				placeholder="Place some text here">{$note.content}</textarea>
		</div>
		<div class="note__submit">
			<button type="submit" class="btn btn-primary">Submit</button>
		</div>
	</form>
</section>
{/block}