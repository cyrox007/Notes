{extends file="core/base.tpl"}
{block name=title}
	Просмотр заметки: {$note.notename}
{/block}
{block name=body}
<section class="shared-note">
	<div class="note-header">
		<h1>{$note.notename}</h1>
		<p class="note-meta">
			Автор: <strong>{$note.owner}</strong> | 
			Создано: {$note.created_note} | 
			Обновлено: {$note.updated_note}
		</p>
		{if $canEdit}
			<p class="edit-notice">📝 У вас есть доступ на редактирование этой заметки</p>
		{/if}
	</div>

	<div class="note-content-display">
		<h3>Содержимое:</h3>
		<div class="note-text">
			{if $note.content}
				{$note.content|nl2br}
			{else}
				<p><em>Текст отсутствует</em></p>
			{/if}
		</div>
	</div>

	{if $attachments}
	<div class="note-attachments-display">
		<h3>Вложения ({$attachments|@count}):</h3>
		{foreach $attachments as $attachment}
			<div class="attachment-item">
				{if $attachment.file_type == 'voice' || $attachment.file_type == 'audio'}
					<div class="attachment-preview">
						<span class="attachment-icon">🎤</span>
						<audio controls>
							<source src="/uploads/notes/{$note.uid}/{$attachment.file_name}" type="{$attachment.mime_type}">
							Ваш браузер не поддерживает аудио
						</audio>
					</div>
				{elseif $attachment.file_type == 'image'}
					<div class="attachment-preview">
						<img src="/uploads/notes/{$note.uid}/{$attachment.file_name}" alt="{$attachment.file_name}" style="max-width: 300px;">
					</div>
				{elseif $attachment.file_type == 'video'}
					<div class="attachment-preview">
						<span class="attachment-icon">🎬</span>
						<video controls style="max-width: 400px;">
							<source src="/uploads/notes/{$note.uid}/{$attachment.file_name}" type="{$attachment.mime_type}">
							Ваш браузер не поддерживает видео
						</video>
					</div>
				{else}
					<div class="attachment-preview">
						<span class="attachment-icon">📄</span>
						<a href="/uploads/notes/{$note.uid}/{$attachment.file_name}" download>{$attachment.file_name}</a>
					</div>
				{/if}
				<div class="attachment-info">
					<span class="attachment-name">{$attachment.file_name}</span>
					<span class="attachment-size">{$attachment.getFormattedSize()}</span>
				</div>
			</div>
		{/foreach}
	</div>
	{/if}

	<div class="share-actions">
		<a href="/" class="btn btn-secondary">← Вернуться на главную</a>
	</div>
</section>

<style>
.shared-note {
	max-width: 900px;
	margin: 0 auto;
	padding: 20px;
}

.note-header {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white;
	padding: 30px;
	border-radius: 12px;
	margin-bottom: 30px;
}

.note-header h1 {
	margin: 0 0 15px 0;
	font-size: 2em;
}

.note-meta {
	opacity: 0.9;
	font-size: 0.95em;
}

.edit-notice {
	margin-top: 15px;
	padding: 10px;
	background: rgba(255,255,255,0.2);
	border-radius: 6px;
	display: inline-block;
}

.note-content-display {
	background: white;
	padding: 25px;
	border-radius: 8px;
	box-shadow: 0 2px 10px rgba(0,0,0,0.1);
	margin-bottom: 25px;
}

.note-text {
	line-height: 1.8;
	font-size: 1.05em;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.note-attachments-display {
	background: #f8f9fa;
	padding: 25px;
	border-radius: 8px;
	margin-bottom: 25px;
}

.attachment-item {
	display: flex;
	align-items: center;
	gap: 15px;
	padding: 15px;
	margin-bottom: 15px;
	background: white;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
}

.attachment-preview {
	flex-shrink: 0;
}

.attachment-icon {
	font-size: 2em;
	margin-right: 10px;
}

.attachment-info {
	flex-grow: 1;
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.attachment-name {
	font-weight: bold;
	color: #333;
}

.attachment-size {
	font-size: 0.85em;
	color: #666;
}

.share-actions {
	text-align: center;
	margin-top: 30px;
}

@media (max-width: 768px) {
	.attachment-item {
		flex-direction: column;
		align-items: flex-start;
	}
}
</style>
{/block}
