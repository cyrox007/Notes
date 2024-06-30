{extends file='core/base.tpl'}
{block name=title}
    Блокнот
{/block}
{block name=body}
<section class="content-header">
	<h1>
		Блокнот
	</h1>
</section>

<section class="notes">
	<form class="notes__create" action="" method="post">
		<div class="notes__input">
			<input type="text" name="note-name" placeholder="Введите название новой заметки...">
		</div>
		<div class="notes__submit">
			<button type="submit">Создать</button>
		</div>
	</form>
	{if $user['role'] >= 900}
		<div id="show-all-notes">
			<p>Показать заметки всех пользователей</p>
			<div class="switch-btn"></div>
		</div>	
	{/if}
	<div class="notes__content">
		<h3 class="notes__title">Список записей</h3>
		<div class="notes__list_head">
			<div class="notes__list_head--name">Название</div>
			<div class="notes__list_head--date">Дата создания</div>
			<div class="notes__list_head--btn"></div>
		</div>
		<div id="personal" class="notes__list visible">
			{if $personalNotes}
				{foreach $personalNotes as $note}
					<div class="notes__list_item">
						<div class="notes__name">
							{$note['notename']}
						</div>
						<div class="notes__date">
							Создано:
							{$note['created_note']} <br>
							{if $note['created_note'] != $note['updated_note']}
							Редактировано:
							{$note['updated_note']}
							{/if}
						</div>
						<div class="notes__btn">
							<a class="notes__btn--edit" href="{route_path name="edit_page" uid=$note['uid']}">
								<i class="fa fa-pencil" aria-hidden="true"></i>
							</a>
							<a class="notes__btn--delete" href="{route_path name="delete_note" uid=$note['uid']}">
								<i class="fa fa-trash" aria-hidden="true"></i>
							</a>
						</div>
					</div>
				{/foreach}
			{else}
				<div class="notes__list_item">
					<p>Здесь ничего нет</p>
				</div>
			{/if}
		</div>
		{if $user['role'] >= 900}
			<div id="all-user" class="notes__list">
			
				{foreach $allNotes as $anote}
					<div class="notes__list_item">
						<div class="notes__name">
							{$anote['notename']}
						</div>
						<div class="notes__date">
							Создано:
							{$anote['created_note']} <br>
							{if $anote['created_note'] != $anote['updated_note']}
							Редактировано:
							{$anote['updated_note']}
							{/if}
						</div>
						<div class="notes__btn">
							<a class="notes__btn--edit" href="{route_path name="edit_page" uid=$anote['uid']}">
								<i class="fa fa-pencil" aria-hidden="true"></i>
							</a>
							<a class="notes__btn--delete" href="{route_path name="delete_note" uid=$anote['uid']}">
								<i class="fa fa-trash" aria-hidden="true"></i>
							</a>
						</div>
					</div>
				{/foreach}
			</div>
		{/if}
	</div>
</section>
<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
	let btnShowAllNotes = document.querySelector('.switch-btn');
	btnShowAllNotes.addEventListener('click', (e) => {
		e.preventDefault();
		btnShowAllNotes.classList.toggle('switch-on');
		document.getElementById('personal').classList.toggle('visible');
		document.getElementById('all-user').classList.toggle('visible');
	});
});
{/literal}
</script>
{/block}
