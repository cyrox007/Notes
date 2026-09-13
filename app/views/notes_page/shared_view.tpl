{extends file="core/base.tpl"}
{block name=title}Просмотр заметки: {$note.notename|escape}{/block}
{block name=body}
<section class="shared-note">
    <div class="note-header">
        <h1>{$note.notename|escape}</h1>
        <p class="note-meta">
            Автор: <strong>{$note.owner|escape}</strong> |
            Создано: {$note.created_note|escape} |
            Обновлено: {$note.updated_note|escape}
        </p>
        <p class="view-notice">Ссылка предоставляет только просмотр. Владелец может отключить её в любой момент.</p>
    </div>

    <div class="note-content-display">
        <h3>Содержимое</h3>
        <div class="note-text">{if $note.content}{$note.content|escape}{else}<em>Текст отсутствует</em>{/if}</div>
    </div>

    {if $attachments}
        <div class="note-attachments-display">
            <h3>Вложения ({$attachments|@count})</h3>
            {foreach $attachments as $attachment}
                <div class="attachment-item">
                    <div class="attachment-preview">
                        {if $attachment.file_type == 'voice' || $attachment.file_type == 'audio'}
                            <span class="attachment-icon">🎤</span>
                            <audio controls preload="metadata">
                                <source src="{$attachment.file_url|escape}" type="{$attachment.mime_type|escape}">
                                Ваш браузер не поддерживает аудио.
                            </audio>
                        {elseif $attachment.file_type == 'image'}
                            <img src="{$attachment.file_url|escape}" alt="{$attachment.file_name|escape}" loading="lazy" style="max-width:300px;max-height:240px;object-fit:contain;">
                        {elseif $attachment.file_type == 'video'}
                            <span class="attachment-icon">🎬</span>
                            <video controls preload="metadata" style="max-width:400px;">
                                <source src="{$attachment.file_url|escape}" type="{$attachment.mime_type|escape}">
                                Ваш браузер не поддерживает видео.
                            </video>
                        {else}
                            <span class="attachment-icon">📄</span>
                            <a href="{$attachment.file_url|escape}">{$attachment.file_name|escape}</a>
                        {/if}
                    </div>
                    <div class="attachment-info">
                        <span class="attachment-name">{$attachment.file_name|escape}</span>
                        <span class="attachment-size">{$attachment.formatted_size|escape}</span>
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
.shared-note{max-width:900px;margin:0 auto;padding:20px}.note-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px;border-radius:12px;margin-bottom:30px}.note-header h1{margin:0 0 15px;font-size:2em}.note-meta{opacity:.9;font-size:.95em}.view-notice{margin-top:15px;padding:10px;background:rgba(255,255,255,.18);border-radius:6px;display:inline-block}.note-content-display{background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1);margin-bottom:25px}.note-text{line-height:1.8;font-size:1.05em;white-space:pre-wrap;overflow-wrap:anywhere}.note-attachments-display{background:#f8f9fa;padding:25px;border-radius:8px;margin-bottom:25px}.attachment-item{display:flex;align-items:center;gap:15px;padding:15px;margin-bottom:15px;background:#fff;border:1px solid #e0e0e0;border-radius:8px}.attachment-preview{flex-shrink:0}.attachment-icon{font-size:2em;margin-right:10px}.attachment-info{flex:1;display:flex;flex-direction:column;gap:5px;min-width:0}.attachment-name{font-weight:700;color:#333;overflow-wrap:anywhere}.attachment-size{font-size:.85em;color:#666}.share-actions{text-align:center;margin-top:30px}@media(max-width:768px){.attachment-item{flex-direction:column;align-items:flex-start}.attachment-preview audio,.attachment-preview video{max-width:100%!important}}
</style>
{/block}
