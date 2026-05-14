{extends file="core/base.tpl"}
{block name=title}
	Блокнот: Редактируем > {$note.notename}
{/block}
{block name=body}
<section class="note-header">
	<h1 class="note__title">
		{$note.notename}
	</h1>
	<p class="note__info">Автор: {$note.author.username}</p>
	<p class="note__info">Дата создания: {$note.created_note}</p>
	<p class="note__info">Дата редактирования: {$note.updated_note}</p>
</section>

<section class="note-content">
	<form class="note__edit" action="{route_path name="update_note" uid=$note.uid}" method="post">
		{csrf_token}
		<div class="note__text">
			<textarea name="content" class="textarea" placeholder="Введите текст заметки...">{$note.content}</textarea>
		</div>
		<div class="note__submit">
			<button type="submit" class="btn btn-primary">Сохранить текст</button>
		</div>
	</form>
</section>

<section class="note-attachments">
	<h3>Вложения (медиа, аудио, файлы)</h3>
	
	<!-- Форма загрузки файлов -->
	<div class="attachment-upload">
		<form id="uploadForm" enctype="multipart/form-data">
			<input type="file" name="attachment" id="attachmentInput" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.txt" required>
			<input type="hidden" name="is_voice" id="isVoice" value="false">
			<button type="submit" class="btn btn-secondary">Загрузить файл</button>
		</form>
		<button id="recordVoiceBtn" class="btn btn-voice">🎤 Записать голосовое</button>
	</div>
	
	<!-- Список вложений -->
	<div id="attachmentsList" class="attachments-list">
		{if $attachments}
			{foreach $attachments as $attachment}
				<div class="attachment-item" data-id="{$attachment.id}">
					{if isset($attachment.type) && $attachment.type == 'voice'}
						<div class="attachment-preview">
							<span class="attachment-icon">🎤</span>
							<audio controls>
								<source src="{$attachment.file_url}" type="{$attachment.mime_type}">
								Ваш браузер не поддерживает аудио
							</audio>
						</div>
					{elseif isset($attachment.type) && $attachment.type == 'image'}
						<div class="attachment-preview">
							<img src="{$attachment.file_url}" alt="{$attachment.file_name}" style="max-width: 200px;">
						</div>
					{elseif isset($attachment.type) && $attachment.type == 'media'}
						<div class="attachment-preview">
							<span class="attachment-icon">🎬</span>
							<video controls style="max-width: 300px;">
								<source src="{$attachment.file_url}" type="{$attachment.mime_type}">
								Ваш браузер не поддерживает видео
							</video>
						</div>
					{else}
						<div class="attachment-preview">
							<span class="attachment-icon">📄</span>
							<span>{$attachment.file_name}</span>
						</div>
					{/if}
					<div class="attachment-info">
						<span class="attachment-name">{$attachment.file_name}</span>
						<span class="attachment-size">{$attachment.formatted_size}</span>
						{if $attachment.duration}
							<span class="attachment-duration">⏱ {$attachment.duration} сек</span>
						{/if}
					</div>
					<button class="btn btn-danger delete-attachment" data-id="{$attachment.id}">Удалить</button>
				</div>
			{/foreach}
		{else}
			<p class="no-attachments">Нет вложений</p>
		{/if}
	</div>
</section>

<section class="note-sharing">
	<h3>Поделиться заметкой</h3>
	{if $shareInfo}
		<div class="share-active">
			<p>Заметка доступна по ссылке:</p>
			<div class="share-url-box">
				<input type="text" id="shareUrl" value="{$shareUrl}" readonly>
				<button id="copyShareUrl" class="btn btn-secondary">Копировать</button>
			</div>
			<p>Тип доступа: <strong>{if $shareInfo.access_type == 'edit'}Редактирование{else}Просмотр{/if}</strong></p>
			{if $shareInfo.expires_at}
				<p>Действует до: {$shareInfo.expires_at}</p>
			{/if}
			<button id="unshareNote" class="btn btn-danger">Деактивировать ссылку</button>
		</div>
	{else}
		<form id="shareForm" class="share-form">
			<div class="share-options">
				<label>
					Тип доступа:
					<select name="access_type" id="accessType">
						<option value="view">Только просмотр</option>
						<option value="edit">Редактирование</option>
					</select>
				</label>
				<label>
					Срок действия (часы, 0 = бессрочно):
					<input type="number" name="expires_in" id="expiresIn" value="0" min="0">
				</label>
			</div>
			<button type="submit" class="btn btn-primary">Создать ссылку</button>
		</form>
	{/if}
</section>

<!-- Модальное окно записи голоса -->
<div id="voiceRecordModal" class="modal" style="display:none;">
	<div class="modal-content">
		<h4>Запись голосового сообщения</h4>
		<div class="recorder-controls">
			<button id="startRecord" class="btn btn-primary">Начать запись</button>
			<button id="stopRecord" class="btn btn-danger" disabled>Остановить</button>
			<button id="cancelRecord" class="btn btn-secondary">Отмена</button>
		</div>
		<div id="recordingStatus">Готов к записи</div>
		<audio id="voicePreview" controls style="display:none; margin-top: 10px;"></audio>
		<button id="sendVoice" class="btn btn-success" style="display:none;">Отправить</button>
	</div>
</div>

<style>
.note-attachments, .note-sharing {
	margin-top: 30px;
	padding: 20px;
	background: #f9f9f9;
	border-radius: 8px;
}

.attachments-list {
	margin-top: 15px;
}

.attachment-item {
	display: flex;
	align-items: center;
	gap: 15px;
	padding: 10px;
	margin-bottom: 10px;
	background: white;
	border: 1px solid #ddd;
	border-radius: 6px;
}

.attachment-preview {
	flex-shrink: 0;
}

.attachment-info {
	flex-grow: 1;
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.attachment-name {
	font-weight: bold;
}

.attachment-size, .attachment-duration {
	font-size: 0.85em;
	color: #666;
}

.share-url-box {
	display: flex;
	gap: 10px;
	margin: 10px 0;
}

.share-url-box input {
	flex-grow: 1;
	padding: 8px;
	border: 1px solid #ccc;
	border-radius: 4px;
}

.share-options {
	display: flex;
	gap: 20px;
	margin-bottom: 15px;
}

.share-options label {
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.modal {
	position: fixed;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background: rgba(0,0,0,0.5);
	z-index: 1000;
}

.modal-content {
	background: white;
	max-width: 400px;
	margin: 100px auto;
	padding: 20px;
	border-radius: 8px;
}

.recorder-controls {
	display: flex;
	gap: 10px;
	margin: 15px 0;
}

#recordingStatus {
	padding: 10px;
	background: #eef;
	border-radius: 4px;
}
</style>

<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
	const noteUid = '{/literal}{$note.uid}{literal}';
	
	// Загрузка файлов
	document.getElementById('uploadForm').addEventListener('submit', async (e) => {
		e.preventDefault();
		const formData = new FormData(e.target);
		
		try {
			const response = await fetch('/notes/upload/' + noteUid, {
				method: 'POST',
				body: formData
			});
			const result = await response.json();
			
			if (result.success) {
				location.reload();
			} else {
				alert('Ошибка: ' + result.error);
			}
		} catch (err) {
			alert('Ошибка загрузки: ' + err.message);
		}
	});
	
	// Удаление вложений
	document.querySelectorAll('.delete-attachment').forEach(btn => {
		btn.addEventListener('click', async () => {
			if (!confirm('Удалить вложение?')) return;
			
			const attachmentId = btn.dataset.id;
			try {
				const response = await fetch('/notes/attachment/delete/' + attachmentId, {
					method: 'POST'
				});
				const result = await response.json();
				
				if (result.success) {
					location.reload();
				}
			} catch (err) {
				alert('Ошибка удаления');
			}
		});
	});
	
	// Шаринг заметки
	document.getElementById('shareForm')?.addEventListener('submit', async (e) => {
		e.preventDefault();
		const formData = new FormData(e.target);
		
		try {
			const response = await fetch('/notes/share/' + noteUid, {
				method: 'POST',
				body: formData
			});
			const result = await response.json();
			
			if (result.success) {
				alert('Ссылка создана: ' + result.share_url);
				location.reload();
			}
		} catch (err) {
			alert('Ошибка создания ссылки');
		}
	});
	
	// Копирование ссылки
	document.getElementById('copyShareUrl')?.addEventListener('click', () => {
		const urlInput = document.getElementById('shareUrl');
		urlInput.select();
		document.execCommand('copy');
		alert('Ссылка скопирована!');
	});
	
	// Деактивация шаринга
	document.getElementById('unshareNote')?.addEventListener('click', async () => {
		if (!confirm('Деактивировать ссылку?')) return;
		
		try {
			const response = await fetch('/notes/unshare/' + noteUid, {
				method: 'POST'
			});
			const result = await response.json();
			
			if (result.success) {
				location.reload();
			}
		} catch (err) {
			alert('Ошибка');
		}
	});
	
	// Запись голоса (Web Audio API)
	let mediaRecorder;
	let audioChunks = [];
	
	document.getElementById('recordVoiceBtn')?.addEventListener('click', () => {
		document.getElementById('voiceRecordModal').style.display = 'block';
	});
	
	document.getElementById('cancelRecord')?.addEventListener('click', () => {
		document.getElementById('voiceRecordModal').style.display = 'none';
	});
	
	document.getElementById('startRecord')?.addEventListener('click', async () => {
		try {
			const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
			mediaRecorder = new MediaRecorder(stream);
			audioChunks = [];
			
			mediaRecorder.ondataavailable = (e) => {
				audioChunks.push(e.data);
			};
			
			mediaRecorder.onstop = () => {
				const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
				const audioUrl = URL.createObjectURL(audioBlob);
				const preview = document.getElementById('voicePreview');
				preview.src = audioUrl;
				preview.style.display = 'block';
				document.getElementById('sendVoice').style.display = 'inline-block';
				document.getElementById('sendVoice').dataset.blob = audioBlob;
			};
			
			mediaRecorder.start();
			document.getElementById('startRecord').disabled = true;
			document.getElementById('stopRecord').disabled = false;
			document.getElementById('recordingStatus').textContent = 'Идет запись...';
		} catch (err) {
			alert('Ошибка доступа к микрофону: ' + err.message);
		}
	});
	
	document.getElementById('stopRecord')?.addEventListener('click', () => {
		mediaRecorder.stop();
		document.getElementById('startRecord').disabled = false;
		document.getElementById('stopRecord').disabled = true;
		document.getElementById('recordingStatus').textContent = 'Запись завершена';
	});
	
	document.getElementById('sendVoice')?.addEventListener('click', async () => {
		const blob = document.getElementById('sendVoice').dataset.blob;
		const file = new File([blob], 'voice_' + Date.now() + '.webm', { type: 'audio/webm' });
		
		const formData = new FormData();
		formData.append('attachment', file);
		formData.append('is_voice', 'true');
		
		try {
			const response = await fetch('/notes/upload/' + noteUid, {
				method: 'POST',
				body: formData
			});
			const result = await response.json();
			
			if (result.success) {
				document.getElementById('voiceRecordModal').style.display = 'none';
				location.reload();
			}
		} catch (err) {
			alert('Ошибка отправки');
		}
	});
});
{/literal}
</script>
{/block}