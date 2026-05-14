{extends file="^shared/layout.tpl"}

{block name="title"}Файловый менеджер{/block}

{block name="styles"}
<link rel="stylesheet" href="/assets/css/file_manager/style.css">
{/block}

{block name="content"}
<div class="file-manager-container">
    <!-- Верхняя панель -->
    <div class="fm-toolbar">
        <div class="fm-breadcrumb">
            {foreach $breadcrumb as $i => $crumb}
                {if $i > 0}<span class="separator">/</span>{/if}
                <a href="{if $crumb.id == 0}/files/{else}/files/folder/{$crumb.id}/{/if}" 
                   class="breadcrumb-item {if $i == count($breadcrumb) - 1}active{/if}">
                    {$crumb.name}
                </a>
            {/foreach}
        </div>
        
        <div class="fm-actions">
            <button id="btn-create-folder" class="fm-btn fm-btn-primary">
                <i class="fa fa-folder-plus"></i> Новая папка
            </button>
            <button id="btn-upload-file" class="fm-btn fm-btn-success">
                <i class="fa fa-upload"></i> Загрузить файл
            </button>
            <input type="file" id="file-input" style="display: none;" multiple>
        </div>
    </div>
    
    <!-- Список файлов -->
    <div class="fm-content">
        {if empty($files)}
            <div class="fm-empty">
                <i class="fa fa-folder-open"></i>
                <p>Папка пуста</p>
            </div>
        {else}
            <div class="fm-grid">
                {foreach from=$files item=file}
                    <div class="fm-item" data-id="{$file->id}" data-type="{$file->type}" data-name="{$file->name}" data-extension="{$file->extension}">
                        <div class="fm-item-icon">
                            {if $file->type == 'folder'}
                                <i class="fa fa-folder"></i>
                            {else}
                                {assign var="icon" value="fa-file"}
                                {if strpos($file->mime_type, 'image') !== false}
                                    {assign var="icon" value="fa-file-image"}
                                {elseif strpos($file->mime_type, 'audio') !== false}
                                    {assign var="icon" value="fa-file-audio"}
                                {elseif strpos($file->mime_type, 'video') !== false}
                                    {assign var="icon" value="fa-file-video"}
                                {elseif $file->extension == 'pdf'}
                                    {assign var="icon" value="fa-file-pdf"}
                                {elseif in_array($file->extension, ['doc', 'docx'])}
                                    {assign var="icon" value="fa-file-word"}
                                {elseif in_array($file->extension, ['xls', 'xlsx'])}
                                    {assign var="icon" value="fa-file-excel"}
                                {elseif in_array($file->extension, ['php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css'])}
                                    {assign var="icon" value="fa-file-code"}
                                {/if}
                                <i class="fa {$icon}"></i>
                            {/if}
                        </div>
                        <div class="fm-item-name">{$file->name}{if $file->type == 'file'}.{$file->extension}{/if}</div>
                        <div class="fm-item-meta">
                            {if $file->type == 'file'}
                                {if $file->size < 1024}
                                    {$file->size} Б
                                {elseif $file->size < 1048576}
                                    {round($file->size / 1024, 1)} КБ
                                {else}
                                    {round($file->size / 1048576, 1)} МБ
                                {/if}
                            {else}
                                Папка
                            {/if}
                        </div>
                        <div class="fm-item-actions">
                            {if $file->type == 'folder'}
                                <a href="/files/folder/{$file->id}/" class="fm-action-btn" title="Открыть">
                                    <i class="fa fa-folder-open"></i>
                                </a>
                            {else}
                                <a href="/files/get/{$file->id}/" class="fm-action-btn" title="Открыть" target="_blank">
                                    <i class="fa fa-eye"></i>
                                </a>
                            {/if}
                            <button class="fm-action-btn btn-rename" title="Переименовать">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="fm-action-btn btn-delete" title="Удалить">
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
<div id="modal-create-folder" class="fm-modal">
    <div class="fm-modal-content">
        <div class="fm-modal-header">
            <h3>Новая папка</h3>
            <button class="fm-modal-close">&times;</button>
        </div>
        <div class="fm-modal-body">
            <input type="text" id="folder-name-input" placeholder="Название папки" autofocus>
        </div>
        <div class="fm-modal-footer">
            <button class="fm-btn fm-btn-secondary modal-cancel">Отмена</button>
            <button class="fm-btn fm-btn-primary modal-ok">Создать</button>
        </div>
    </div>
</div>

<!-- Модальное окно переименования -->
<div id="modal-rename" class="fm-modal">
    <div class="fm-modal-content">
        <div class="fm-modal-header">
            <h3>Переименовать</h3>
            <button class="fm-modal-close">&times;</button>
        </div>
        <div class="fm-modal-body">
            <input type="text" id="rename-input" placeholder="Новое название">
            <input type="hidden" id="rename-id">
        </div>
        <div class="fm-modal-footer">
            <button class="fm-btn fm-btn-secondary modal-cancel">Отмена</button>
            <button class="fm-btn fm-btn-primary modal-ok">Переименовать</button>
        </div>
    </div>
</div>

<!-- Медиа плеер -->
<div id="media-player-modal" class="fm-modal">
    <div class="fm-modal-content fm-modal-large">
        <div class="fm-modal-header">
            <h3 id="player-title">Плеер</h3>
            <button class="fm-modal-close">&times;</button>
        </div>
        <div class="fm-modal-body">
            <div id="player-container"></div>
        </div>
    </div>
</div>

<!-- Code Editor Modal -->
<div id="code-editor-modal" class="fm-modal">
    <div class="fm-modal-content fm-modal-xl">
        <div class="fm-modal-header">
            <h3 id="editor-title">Редактор кода</h3>
            <button class="fm-modal-close">&times;</button>
        </div>
        <div class="fm-modal-body">
            <div id="editor-container">
                <textarea id="code-editor"></textarea>
            </div>
            <div class="editor-info">
                <small>⚠️ Код выполняется в изолированной среде (песочнице)</small>
            </div>
        </div>
        <div class="fm-modal-footer">
            <button class="fm-btn fm-btn-secondary" id="btn-run-code">Запустить</button>
            <button class="fm-btn fm-btn-primary" id="btn-save-code">Сохранить</button>
        </div>
    </div>
</div>
{/block}

{block name="scripts"}
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.4.14/ace.js"></script>
<script src="/assets/js/file_manager/script.js"></script>
{/block}
