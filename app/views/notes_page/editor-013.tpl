<div class="note-editor-013" data-note-editor-013 data-upload-url="{route_path name='note_attachment_upload' uid=$note.uid}" data-share-url="{route_path name='note_share' uid=$note.uid}" data-unshare-url="{route_path name='note_unshare' uid=$note.uid}">
    <header class="note-editor-013__topbar">
        <div class="note-editor-013__title-group">
            <span class="ux-kicker">Блокнот</span>
            <h1>{$note.notename|default:'Без названия'|escape}</h1>
            <div class="note-editor-013__meta">
                <span>Автор: {$note.author_username|default:'Неизвестно'|escape}</span>
                <span>Создано: {$note.created_note|escape}</span>
                <span>Изменено: {$note.updated_note|escape}</span>
            </div>
        </div>
        <div class="note-editor-013__top-actions">
            <button id="recordVoiceBtn" type="button" class="note-editor-013__voice-button"><i class="fa fa-microphone" aria-hidden="true"></i> Голосовая заметка</button>
            <a class="btn btn-secondary" href="{route_path name='notes'}">К списку</a>
        </div>
    </header>

    <section id="voiceRecorder" class="note-voice-recorder" hidden aria-labelledby="voiceRecorderTitle">
        <div class="note-editor-013__section-head">
            <div><span class="ux-kicker">Voice note</span><h2 id="voiceRecorderTitle">Запись с микрофона</h2><p>Запись сохраняется как приватное вложение заметки.</p></div>
            <div id="recordingTimer" class="note-voice-recorder__timer" aria-live="polite">00:00</div>
        </div>
        <div class="note-voice-recorder__status"><span class="note-voice-recorder__pulse" aria-hidden="true"></span><span id="recordingStatus">Нажмите «Начать запись», когда будете готовы.</span></div>
        <audio id="voicePreview" controls preload="metadata" hidden></audio>
        <div class="note-voice-recorder__controls">
            <button id="startRecord" type="button" class="btn btn-primary"><i class="fa fa-circle" aria-hidden="true"></i> Начать запись</button>
            <button id="stopRecord" type="button" class="btn btn-secondary" disabled>Остановить</button>
            <button id="sendVoice" type="button" class="btn btn-primary" disabled>Сохранить голосовую</button>
            <button id="discardRecord" type="button" class="btn btn-secondary">Отмена</button>
        </div>
        <div class="note-voice-recorder__hint">Перед сохранением запись можно прослушать.</div>
    </section>

    <div class="note-editor-013__workspace">
        <section class="note-editor-013__document" aria-label="Редактор заметки">
            <form class="note__edit" action="{route_path name='update_note' uid=$note.uid}" method="post">
                {csrf_token}
                <div class="note__name-input"><input type="text" name="notename" maxlength="255" placeholder="Название заметки" value="{$note.notename|default:''|escape}" aria-label="Название заметки"></div>
                <div class="note__text"><textarea name="content" class="textarea" placeholder="Начните писать…">{$note.content|default:''|escape}</textarea></div>
                <div class="note-editor-013__save-row">
                    <div class="note__submit"><button type="submit" class="btn btn-primary">Сохранить текст</button></div>
                    <span class="note-editor-013__save-hint">Текст хранится в зашифрованном виде.</span>
                </div>
            </form>
        </section>

        <aside class="note-editor-013__sidebar" aria-label="Материалы заметки">
            <section class="note-editor-013__side-card">
                <div class="note-editor-013__section-head"><div><h2>Голосовые</h2><p>Быстрые аудиозаметки прямо из редактора.</p></div></div>
                <div class="note-editor-013__voice-list">
                    {assign var=voiceCount value=0}
                    {foreach $attachments as $attachment}
                        {if $attachment.file_type == 'voice'}
                            {assign var=voiceCount value=$voiceCount+1}
                            <div class="attachment-item attachment-item--voice" data-id="{$attachment.id}" data-file-type="voice">
                                <span class="note-editor-013__voice-icon"><i class="fa fa-microphone" aria-hidden="true"></i></span>
                                <div class="note-editor-013__voice-copy">
                                    <strong class="attachment-name">Голосовая заметка</strong>
                                    <audio controls preload="metadata"><source src="{route_path name='note_attachment_download' fileUid=$attachment.file_uid}" type="{$attachment.mime_type|escape}"></audio>
                                    <div><span class="attachment-size">{$attachment.formatted_size|escape}</span>{if $attachment.duration}<span class="attachment-duration"> · {$attachment.duration|escape} сек</span>{/if}</div>
                                </div>
                                <button type="button" class="btn-icon delete-attachment" data-delete-url="{route_path name='note_attachment_delete' attachmentId=$attachment.id}" aria-label="Удалить голосовую заметку"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </div>
                        {/if}
                    {/foreach}
                    {if $voiceCount == 0}<div class="note-editor-013__empty">Голосовых пока нет. Нажмите «Голосовая заметка» сверху.</div>{/if}
                </div>
            </section>

            <section class="note-editor-013__side-card">
                <div class="note-editor-013__section-head"><div><h2>Файлы</h2><p>Документы и медиа в приватном хранилище.</p></div></div>
                <form id="uploadForm" class="note-editor-013__file-form" enctype="multipart/form-data">
                    <input type="file" name="attachment" id="attachmentInput" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/mp4,video/webm,video/quicktime,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp" required>
                    <input type="hidden" name="is_voice" value="false"><button type="submit" class="btn btn-secondary">Добавить файл</button>
                </form>
                <div id="attachmentsList" class="note-editor-013__attachments">
                    {assign var=fileCount value=0}
                    {foreach $attachments as $attachment}
                        {if $attachment.file_type != 'voice'}
                            {assign var=fileCount value=$fileCount+1}
                            <div class="attachment-item" data-id="{$attachment.id}" data-file-type="{$attachment.file_type|escape}">
                                <div class="attachment-preview">
                                    {if $attachment.file_type == 'audio'}<audio controls preload="metadata"><source src="{route_path name='note_attachment_download' fileUid=$attachment.file_uid}" type="{$attachment.mime_type|escape}"></audio>
                                    {elseif $attachment.file_type == 'image'}<img src="{route_path name='note_attachment_download' fileUid=$attachment.file_uid}" alt="{$attachment.file_name|escape}" loading="lazy" style="max-width:100%;max-height:160px;object-fit:contain;">
                                    {elseif $attachment.file_type == 'video'}<video controls preload="metadata" style="max-width:100%;"><source src="{route_path name='note_attachment_download' fileUid=$attachment.file_uid}" type="{$attachment.mime_type|escape}"></video>
                                    {else}<a href="{route_path name='note_attachment_download' fileUid=$attachment.file_uid}">{$attachment.file_name|escape}</a>{/if}
                                </div>
                                <div class="attachment-info"><span class="attachment-name">{$attachment.file_name|escape}</span><span class="attachment-size">{$attachment.formatted_size|escape}</span></div>
                                <button type="button" class="btn-icon delete-attachment" data-delete-url="{route_path name='note_attachment_delete' attachmentId=$attachment.id}" aria-label="Удалить вложение"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </div>
                        {/if}
                    {/foreach}
                    {if $fileCount == 0}<div class="note-editor-013__empty">Файлов пока нет.</div>{/if}
                </div>
            </section>

            <section class="note-editor-013__side-card">
                <div class="note-editor-013__section-head"><div><h2>Поделиться</h2><p>Ссылка даёт только просмотр и может быть отключена.</p></div></div>
                <div class="note-editor-013__share-box">
                    {if $shareInfo}
                        <div class="note-editor-013__share-url"><input type="text" id="shareUrl" value="{$base_url|escape}/notes/shared/{$shareInfo.share_token|escape:'url'}" readonly aria-label="Публичная ссылка"><button id="copyShareUrl" type="button" class="btn btn-secondary">Копировать</button></div>
                        {if $shareInfo.expires_at}<p>Действует до: {$shareInfo.expires_at|escape}</p>{/if}
                        <button id="unshareNote" type="button" class="btn btn-danger">Отключить ссылку</button>
                    {else}
                        <form id="shareForm" class="share-form"><input type="hidden" name="access_type" value="view"><label>Срок действия, часов<input type="number" name="expires_in" id="expiresIn" value="0" min="0" max="8760"></label><button type="submit" class="btn btn-primary">Создать ссылку</button></form>
                    {/if}
                </div>
            </section>
        </aside>
    </div>
</div>