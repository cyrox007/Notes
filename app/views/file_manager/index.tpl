{extends file="core/base.tpl"}

{block name="title"}Файловый менеджер{/block}

{block name="body"}
	<div class="file-manager" {if $current_folder} data-current-folder-id="{$current_folder.id}" {/if}>
		<div class="file-manager__toolbar">
			<div class="file-manager__breadcrumb" aria-label="Путь к папке">
				{foreach $breadcrumb as $i => $crumb}
					{if $i > 0}<span class="file-manager__separator" aria-hidden="true">/</span>{/if}
					<a href="{if $crumb.id == 0}/files/{else}/files/folder/{$crumb.id}/{/if}"
						class="file-manager__breadcrumb-item{if $i == count($breadcrumb) - 1} file-manager__breadcrumb-item--active{/if}"
						{if $i == count($breadcrumb) - 1}aria-current="page"{/if}>
						{$crumb.name}
					</a>
				{/foreach}
			</div>

			<div class="file-manager__actions">
				<button id="btn-create-folder" type="button" class="file-manager__btn file-manager__btn--primary">
					<i class="fa fa-folder-o" aria-hidden="true"></i> Новая папка
				</button>
				<button id="btn-upload-file" type="button" class="file-manager__btn file-manager__btn--success">
					<i class="fa fa-upload" aria-hidden="true"></i> Загрузить файл
				</button>
				<input type="file" id="file-input" hidden multiple>
			</div>
		</div>

		<section id="file-manager-quota" class="file-manager__quota" data-url="{route_path('files_quota')}" aria-label="Использование хранилища">
			<div class="file-manager__quota-header">
				<div>
					<strong>Хранилище</strong>
					<span data-quota-status>Загрузка данных…</span>
				</div>
				<div class="file-manager__quota-values">
					<span>Использовано <strong data-quota-used>—</strong></span>
					<span>Лимит <strong data-quota-total>—</strong></span>
					<span>Осталось <strong data-quota-remaining>—</strong></span>
				</div>
			</div>
			<div class="file-manager__quota-track" role="progressbar" aria-label="Заполненность хранилища" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="file-manager__quota-bar" data-quota-bar></div>
			</div>
		</section>

		<div class="file-manager__content" aria-live="polite">
			{if empty($files)}
				<div class="file-manager__empty">
					<i class="fa fa-folder-open-o" aria-hidden="true"></i>
					<strong>Папка пуста</strong>
					<p>Создайте папку или загрузите файл, чтобы начать.</p>
				</div>
			{else}
				<div class="file-manager__grid">
					{foreach $files as $file}
						<div class="file-manager__item" data-id="{$file.id}" data-type="{$file.type}" data-name="{$file.name}"
							data-extension="{$file.extension}" tabindex="0">
							<div class="file-manager__item-icon">
								{if $file.type == 'folder'}
									<i class="fa fa-folder" aria-hidden="true"></i>
								{else}
									{assign var="icon" value="fa-file-o"}
									{if $file.mime_type|strpos:'image' !== false}
										{assign var="icon" value="fa-file-image-o"}
									{elseif $file.mime_type|strpos:'audio' !== false}
										{assign var="icon" value="fa-file-audio-o"}
									{elseif $file.mime_type|strpos:'video' !== false}
										{assign var="icon" value="fa-file-video-o"}
									{elseif $file.extension == 'pdf'}
										{assign var="icon" value="fa-file-pdf-o"}
									{elseif $file.extension|in_array:['doc', 'docx']}
										{assign var="icon" value="fa-file-word-o"}
									{elseif $file.extension|in_array:['ppt', 'pptx']}
										{assign var="icon" value="fa-file-powerpoint-o"}
									{elseif $file.extension|in_array:['zip', 'rar', '7z', 'tar', 'gz']}
										{assign var="icon" value="fa-file-archive-o"}
									{elseif $file.extension|in_array:['xls', 'xlsx']}
										{assign var="icon" value="fa-file-excel-o"}
									{elseif $file.extension|in_array:['php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css', 'json', 'xml', 'sql', 'md', 'txt']}
										{assign var="icon" value="fa-file-code-o"}
									{/if}
									<i class="fa {$icon}" aria-hidden="true"></i>
								{/if}
							</div>
							<div class="file-manager__item-name">{$file.name}{if $file.type == 'file'}.{$file.extension}{/if}</div>
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
									<a href="/files/folder/{$file.id}/" class="file-manager__action-btn" title="Открыть" aria-label="Открыть {$file.name}">
										<i class="fa fa-folder-open-o" aria-hidden="true"></i>
									</a>
								{else}
									<a href="/files/get/{$file.id}/" class="file-manager__action-btn" title="Открыть" aria-label="Открыть {$file.name}" target="_blank" rel="noopener">
										<i class="fa fa-eye" aria-hidden="true"></i>
									</a>
								{/if}
								<button type="button" class="file-manager__action-btn file-manager__action-btn--rename btn-rename" title="Переименовать" aria-label="Переименовать {$file.name}">
									<i class="fa fa-pencil" aria-hidden="true"></i>
								</button>
								<button type="button" class="file-manager__action-btn file-manager__action-btn--delete btn-delete" title="Удалить" aria-label="Удалить {$file.name}">
									<i class="fa fa-trash" aria-hidden="true"></i>
								</button>
							</div>
						</div>
					{/foreach}
				</div>
			{/if}
		</div>
	</div>

	<div id="modal-create-folder" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="create-folder-title">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3 id="create-folder-title">Новая папка</h3>
				<button type="button" class="file-manager__modal-close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<label class="visually-hidden" for="folder-name-input">Название папки</label>
				<input type="text" id="folder-name-input" maxlength="190" placeholder="Название папки">
			</div>
			<div class="file-manager__modal-footer">
				<button type="button" class="file-manager__btn file-manager__btn--secondary modal-cancel">Отмена</button>
				<button type="button" class="file-manager__btn file-manager__btn--primary modal-ok">Создать</button>
			</div>
		</div>
	</div>

	<div id="modal-rename" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="rename-title">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3 id="rename-title">Переименовать</h3>
				<button type="button" class="file-manager__modal-close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<label class="visually-hidden" for="rename-input">Новое название</label>
				<input type="text" id="rename-input" maxlength="190" placeholder="Новое название">
				<input type="hidden" id="rename-id">
			</div>
			<div class="file-manager__modal-footer">
				<button type="button" class="file-manager__btn file-manager__btn--secondary modal-cancel">Отмена</button>
				<button type="button" class="file-manager__btn file-manager__btn--primary modal-ok">Переименовать</button>
			</div>
		</div>
	</div>

	<div id="media-player-modal" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="player-title">
		<div class="file-manager__modal-content file-manager__modal-content--large">
			<div class="file-manager__modal-header">
				<h3 id="player-title">Просмотр файла</h3>
				<button type="button" class="file-manager__modal-close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<div id="player-container"></div>
			</div>
		</div>
	</div>

	<div id="text-preview-modal" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="text-preview-title">
		<div class="file-manager__modal-content file-manager__modal-content--xl">
			<div class="file-manager__modal-header">
				<h3 id="text-preview-title">Просмотр текста</h3>
				<button type="button" class="file-manager__modal-close" aria-label="Закрыть">&times;</button>
			</div>
			<div class="file-manager__modal-body">
				<pre id="text-preview-content" class="file-manager__text-preview" tabindex="0"></pre>
				<div class="file-manager__editor-info">
					<small>Режим только для чтения. Код на странице не выполняется.</small>
				</div>
			</div>
		</div>
	</div>

	<div id="modal-upload-progress" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="upload-title">
		<div class="file-manager__modal-content">
			<div class="file-manager__modal-header">
				<h3 id="upload-title">Загрузка файла</h3>
			</div>
			<div class="file-manager__modal-body">
				<div class="upload-progress-item" id="upload-progress-container">
					<div class="upload-file-name" id="upload-file-name">Файл...</div>
					<div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<div class="progress-bar-fill" id="progress-bar-fill"></div>
					</div>
					<div class="progress-percent" id="progress-percent">0%</div>
				</div>
			</div>
		</div>
	</div>
	<script src="/assets/js/file_manager/script.js"></script>
	<script src="/assets/js/file_manager/quota.js"></script>
{/block}