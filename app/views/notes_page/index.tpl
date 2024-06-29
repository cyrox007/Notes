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
	{if $user['role'] > 900}
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
				
			{else}
				
			{/if}
			<? foreach($data['notes'] as $note): ?>
			<div class="notes__list_item">
				<div class="notes__name">
					<? echo $note['name_note'];?>
				</div>
				<div class="notes__date">
					Создано:
					<? echo $note['date_create']; ?> <br>
					<? if ($note['date_create'] != $note['date_edit']): ?>
					Редактировано:
					<? echo $note['date_edit']; ?>
					<? endif; ?>
				</div>
				<div class="notes__btn">
					<a class="notes__btn--edit" href="/Notes/edit/<? echo $note['id']?>">
						<i class="fa fa-pencil" aria-hidden="true"></i>
					</a>
					<a class="notes__btn--delete" href="/Notes/delete/<? echo $note['id']?>">
						<i class="fa fa-trash" aria-hidden="true"></i>
					</a>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<? if ($data['admin']): ?>
		<div id="all-user" class="notes__list">
			<? foreach($data['all-notes'] as $note): ?>
			<div class="notes__list_item">
				<div class="notes__name">
					<? echo $note['name_note'];?>
				</div>
				<div class="notes__date">
					Создано:
					<? echo $note['date_create']; ?> <br>
					<? if ($note['date_create'] != $note['date_edit']): ?>
					Редактировано:
					<? echo $note['date_edit']; ?>
					<? endif; ?>
				</div>
				<div class="notes__btn">
					<a class="notes__btn--edit" href="/Notes/edit/<? echo $note['id']?>">
						<i class="fa fa-pencil" aria-hidden="true"></i>
					</a>
					<a class="notes__btn--delete" href="/Notes/delete/<? echo $note['id']?>">
						<i class="fa fa-trash" aria-hidden="true"></i>
					</a>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<? endif; ?>
	</div>
</section>
<script>
	document.addEventListener('DOMContentLoaded', () => {
		let btnShowAllNotes = document.querySelector('.switch-btn');
		btnShowAllNotes.addEventListener('click', (e) => {
			e.preventDefault();
			btnShowAllNotes.classList.toggle('switch-on');
			document.getElementById('personal').classList.toggle('visible');
			document.getElementById('all-user').classList.toggle('visible');
		});
	});
</script>
{/block}
