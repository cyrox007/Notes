{extends file="core/base.tpl"}

{block name="title"}Файловый менеджер*{/block}

{block name="body"}
	<div class="file-manager"{if $current_folder} data-current-folder-id="{$current_folder.id}"{/if}>
		<!-- Верхняя панель -->
		<div class="file-manager__toolbar">
			<div class="file-manager__breadcrumb">
				{foreach $breadcrumb as $i => $crumb}
					{if $i > 0}<span class="file-manager__separator">/</span>{/if}
					<a href="{if $crumb.id == 0}/files/{else}/files/folder/{$crumb.id}/{/if}"
						class="file-manager__breadcrumb-item{if $i == count($breadcrumb) - 1} file-manager__breadcrumb-item--active{/if}">
						{$crumb.name}
					</a>
				{/foreach}
			</div>

			<div class="file-manager__actions">
				<button id="btn-create-folder" class="file-manager__btn file-manager__btn--primary">
					<i class="fa fa-folder-plus"></i> Новая папка
				</button>
				<button id="btn-upload-file" class="file-manager__btn file-manager__btn--success">
					<i class="fa fa-upload"></i> Загрузить файл
				</button>
				<input type="file" id="file-input" style="display: none;" multiple>
			</div>
		</div>

		<!-- Список файлов -->
		<div class="file-manager__content">
			{if empty($files)}
				<div class="file-manager__empty">
					<i class="fa fa-folder-open"></i>
					<p>Папка пуста</p>
					<p style="margin-top: 10px; font-size: 14px; color: #999;">Создайте папку или загрузите файл, чтобы начать
					</p>
				</div>
			{else}
				<div class="file-manager__grid">
					{foreach $files as $file}
						<div class="file-manager__item" data-id="{$file.id}" data-type="{$file.type}" data-name="{$file.name}"
							data-extension="{$file.extension}">
							<div class="file-manager__item-icon">
								{if $file.type == 'folder'}
									<i class="fa fa-folder"></i>
								{else}
									{assign var="icon" value="fa-file"}
									{if $file.mime_type|strpos:'image' !== false}
										{assign var="icon" value="fa-file-image"}
									{elseif $file.mime_type|strpos:'audio' !== false}
										{assign var="icon" value="fa-file-audio"}
									{elseif $file.mime_type|strpos:'video' !== false}
										{assign var="icon" value="fa-file-video"}
									{elseif $file.extension == 'pdf'}
										{assign var="icon" value="fa-file-pdf"}
									{elseif $file.extension|in_array:['doc', 'docx']}
										{assign var="icon" value="fa-file-word"}
									{elseif $file.extension|in_array:['xls', 'xlsx']}
										{assign var="icon" value="fa-file-excel"}
									{elseif $file.extension|in_array:['php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css']}
										{assign var="icon" value="fa-file-code"}
									{/if}
									<i class="fa {$icon}"></i>
								{/if}
							</div>
							<div class="file-manager__item-name">{$file.name}{if $file.type == 'file'}.{$file.extension}{/if}
							</div>
							<div class="file-manager__item-meta">
								{if $file.type == 'file'}
									{if $file.size < 1024}
										{$file.size} Б
									{elseif $file.size < 1048576}
										{$file.size|round:2|round:1} КБ
									{else}
										{$file.size|round:6|round:1} МБ
									{/if}
								{else}
									Папка
								{/if}
							</div>
							<div class="file-manager__item-actions">
								{if $file.type == 'folder'}
									<a href="/files/folder/{$file.id}/" class="file-manager__action-btn" title="Открыть">
										<i class="fa fa-folder-open"></i>
									</a>
								{else}
									<a href="/files/get/{$file.id}/" class="file-manager__action-btn" title="Открыть" target="_blank">
										<i class="fa fa-eye"></i>
									</a>
								{/if}
								<button class="file-manager__action-btn file-manager__action-btn--rename btn-rename"
									title="Переименовать">
									<i class="fa fa-edit"></i>
								</button>
								<button class="file-manager__action-btn file-manager__action-btn--delete btn-delete"
									title="Удалить">
									<i class="fa fa-trash"></i>
								</button>
							</div>
						</div>
					{/foreach}
				</div>
			{/if}
		</div>
	</div>

	<!-- Модальное окно создания папки -->
	<div id="modal-create-folder" class="file-manager__modal">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3>Новая папка</h3>
				<button class="file-manager__modal-close">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<input type="text" id="folder-name-input" placeholder="Название папки" autofocus>
			</div>
			<div class="file-manager__modal-footer">
				<button class="file-manager__btn file-manager__btn--secondary modal-cancel">Отмена</button>
				<button class="file-manager__btn file-manager__btn--primary modal-ok">Создать</button>
			</div>
		</div>
	</div>

	<!-- Модальное окно переименования -->
	<div id="modal-rename" class="file-manager__modal">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3>Переименовать</h3>
				<button class="file-manager__modal-close">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<input type="text" id="rename-input" placeholder="Новое название">
				<input type="hidden" id="rename-id">
			</div>
			<div class="file-manager__modal-footer">
				<button class="file-manager__btn file-manager__btn--secondary modal-cancel">Отмена</button>
				<button class="file-manager__btn file-manager__btn--primary modal-ok">Переименовать</button>
			</div>
		</div>
	</div>

	<!-- Медиа плеер -->
	<div id="media-player-modal" class="file-manager__modal">
		<div class="file-manager__modal-content file-manager__modal-content--large">
			<div class="file-manager__modal-header">
				<h3 id="player-title">Плеер</h3>
				<button class="file-manager__modal-close">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<div id="player-container"></div>
			</div>
		</div>
	</div>

	<!-- Code Editor Modal -->
	<div id="code-editor-modal" class="file-manager__modal">
		<div class="file-manager__modal-content file-manager__modal-content--xl">
			<div class="file-manager__modal-header">
				<h3 id="editor-title">Редактор кода</h3>
				<button class="file-manager__modal-close">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<div id="editor-container">
					<textarea id="code-editor"></textarea>
				</div>
				<div class="file-manager__editor-info">
					<small>⚠️ Код выполняется в изолированной среде (песочнице)</small>
				</div>
			</div>
			<div class="file-manager__modal-footer">
				<button class="file-manager__btn file-manager__btn--secondary" id="btn-run-code">Запустить</button>
				<button class="file-manager__btn file-manager__btn--primary" id="btn-save-code">Сохранить</button>
			</div>
		</div>
	</div>

	<!-- Upload Progress Modal -->
	<div id="modal-upload-progress" class="file-manager__modal">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3>Загрузка файла</h3>
			</div>
			<div class="file-manager__modal-body">
				<div class="upload-progress-item" id="upload-progress-container">
					<div class="upload-file-name" id="upload-file-name">Файл...</div>
					<div class="progress-bar">
						<div class="progress-bar-fill" id="progress-bar-fill"></div>
					</div>
					<div class="progress-percent" id="progress-percent">0%</div>
				</div>
			</div>
		</div>
	</div>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.14/ace.js"></script>
	<script src="/assets/js/file_manager/script.js"></script>
{/block}