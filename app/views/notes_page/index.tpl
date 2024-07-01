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
			{if $user['role'] >= 900}<div class="notes__list_head--author" style="display: none;">Автор</div>{/if}
			<div class="notes__list_head--date">Дата создания</div>
			<div class="notes__list_head--btn"></div>
		</div>

		<div id="personal" class="notes__list visible">
			{if $personalNotes}
				{foreach $personalNotes as $note}
					{include file="^elements/note_item/index.tpl" note=$note user=$user}
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
					{include file="^elements/note_item/index.tpl" note=$anote user=$user showAuthor=true}
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

		if (btnShowAllNotes.classList.contains('switch-on')) {
			document.querySelector('.notes__list_head--author').style.display = 'block';
		} else {
			document.querySelector('.notes__list_head--author').style.display = 'none';
		}
	});
});
{/literal}
</script>
{/block}
