{extends file="core/base.tpl"}
{block name=title}Блокнот: {$note.notename|escape}{/block}
{block name=body}
<section class="note-header">
    <h1 class="note__title">{$note.notename|default:'Без названия'|escape}</h1>
    <p class="note__info">Автор: {$note.author_username|default:'Неизвестно'|escape}</p>
    <p class="note__info">Дата создания: {$note.created_note|escape}</p>
    <p class="note__info">Дата редактирования: {$note.updated_note|escape}</p>
</section>

<section class="note-content">
    <form class="note__edit" action="{route_path name="update_note" uid=$note.uid}" method="post">
        {csrf_token}
        <div class="note__name-input">
            <input type="text" name="notename" maxlength="255" placeholder="Название заметки" value="{$note.notename|default:''|escape}">
        </div>
        <div class="note__text">
            <textarea name="content" class="textarea" placeholder="Введите текст заметки...">{$note.content|default:''|escape}</textarea>
        </div>
        <div class="note__submit">
            <button type="submit" class="btn btn-primary">Сохранить текст</button>
        </div>
    </form>
</section>

<section class="note-attachments">
    <h3>Вложения</h3>
    <p class="note-security-hint">Файлы хранятся в приватном хранилище и выдаются только после проверки доступа.</p>

    <div class="attachment-upload">
        <form id="uploadForm" enctype="multipart/form-data">
            <input type="file" name="attachment" id="attachmentInput" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/mp4,video/webm,video/quicktime,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp" required>
            <input type="hidden" name="is_voice" id="isVoice" value="false">
            <button type="submit" class="btn btn-secondary">Загрузить файл</button>
        </form>
        <button id="recordVoiceBtn" type="button" class="btn btn-voice"><i class="fa fa-microphone"></i> Записать голосовое</button>
    </div>

    <div id="attachmentsList" class="attachments-list">
        {if $attachments}
            {foreach $attachments as $attachment}
                <div class="attachment-item" data-id="{$attachment.id}">
                    <div class="attachment-preview">
                        {if $attachment.file_type == 'voice' || $attachment.file_type == 'audio'}
                            <span class="attachment-icon"><i class="fa fa-microphone"></i></span>
                            <audio controls preload="metadata">
                                <source src="/notes/attachment/{$attachment.file_uid|escape:'url'}" type="{$attachment.mime_type|escape}">
                                Ваш браузер не поддерживает аудио.
                            </audio>
                        {elseif $attachment.file_type == 'image'}
                            <img src="/notes/attachment/{$attachment.file_uid|escape:'url'}" alt="{$attachment.file_name|escape}" loading="lazy" style="max-width:200px;max-height:160px;object-fit:contain;">
                        {elseif $attachment.file_type == 'video'}
                            <span class="attachment-icon"><i class="fa fa-film"></i></span>
                            <video controls preload="metadata" style="max-width:300px;">
                                <source src="/notes/attachment/{$attachment.file_uid|escape:'url'}" type="{$attachment.mime_type|escape}">
                                Ваш браузер не поддерживает видео.
                            </video>
                        {else}
                            <span class="attachment-icon"><i class="fa fa-file"></i></span>
                            <a href="/notes/attachment/{$attachment.file_uid|escape:'url'}">{$attachment.file_name|escape}</a>
                        {/if}
                    </div>
                    <div class="attachment-info">
                        <span class="attachment-name">{$attachment.file_name|escape}</span>
                        <span class="attachment-size">{$attachment.formatted_size|escape}</span>
                        {if $attachment.duration}<span class="attachment-duration">⏱ {$attachment.duration|escape} сек</span>{/if}
                    </div>
                    <button type="button" class="btn btn-danger delete-attachment" data-id="{$attachment.id}">Удалить</button>
                </div>
            {/foreach}
        {else}
            <p class="no-attachments">Нет вложений</p>
        {/if}
    </div>
</section>

<section class="note-sharing">
    <h3>Поделиться заметкой</h3>
    <p class="note-security-hint">Публичная ссылка предоставляет только просмотр. Её можно отключить в любой момент.</p>
    {if $shareInfo}
        <div class="share-active">
            <p>Заметка доступна по ссылке:</p>
            <div class="share-url-box">
                <input type="text" id="shareUrl" value="{$shareUrl|escape}" readonly>
                <button id="copyShareUrl" type="button" class="btn btn-secondary">Копировать</button>
            </div>
            {if $shareInfo.expires_at}<p>Действует до: {$shareInfo.expires_at|escape}</p>{/if}
            <button id="unshareNote" type="button" class="btn btn-danger">Деактивировать ссылку</button>
        </div>
    {else}
        <form id="shareForm" class="share-form">
            <input type="hidden" name="access_type" value="view">
            <label>
                Срок действия (часы, 0 = бессрочно):
                <input type="number" name="expires_in" id="expiresIn" value="0" min="0" max="8760">
            </label>
            <button type="submit" class="btn btn-primary">Создать ссылку для просмотра</button>
        </form>
    {/if}
</section>

<div id="voiceRecordModal" class="modal" style="display:none;">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="voice-title">
        <h4 id="voice-title">Запись голосовой заметки</h4>
        <div class="recorder-controls">
            <button id="startRecord" type="button" class="btn btn-primary">Начать запись</button>
            <button id="stopRecord" type="button" class="btn btn-danger" disabled>Остановить</button>
            <button id="cancelRecord" type="button" class="btn btn-secondary">Отмена</button>
        </div>
        <div id="recordingStatus">Готов к записи</div>
        <audio id="voicePreview" controls style="display:none;margin-top:10px;"></audio>
        <button id="sendVoice" type="button" class="btn btn-success" style="display:none;">Загрузить запись</button>
    </div>
</div>

<style>
.note-attachments,.note-sharing{margin-top:30px;padding:20px;background:#f9f9f9;border-radius:8px}.note-security-hint{color:#667085;font-size:.9rem}.attachment-upload{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.attachments-list{margin-top:15px}.attachment-item{display:flex;align-items:center;gap:15px;padding:10px;margin-bottom:10px;background:#fff;border:1px solid #ddd;border-radius:6px}.attachment-preview{flex-shrink:0}.attachment-info{flex:1;display:flex;flex-direction:column;gap:5px;min-width:0}.attachment-name{font-weight:700;overflow-wrap:anywhere}.attachment-size,.attachment-duration{font-size:.85em;color:#666}.share-url-box{display:flex;gap:10px;margin:10px 0}.share-url-box input{flex:1;padding:8px;border:1px solid #ccc;border-radius:4px}.share-form{display:flex;align-items:end;gap:14px;flex-wrap:wrap}.share-form label{display:flex;flex-direction:column;gap:5px}.modal{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000}.modal-content{background:#fff;max-width:420px;margin:100px auto;padding:20px;border-radius:8px}.recorder-controls{display:flex;gap:10px;margin:15px 0;flex-wrap:wrap}#recordingStatus{padding:10px;background:#eef;border-radius:4px}@media(max-width:720px){.attachment-item{align-items:flex-start;flex-direction:column}.attachment-preview audio,.attachment-preview video{max-width:100%!important}.share-url-box{flex-direction:column}}
</style>

<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
    const noteUid = '{/literal}{$note.uid|escape:'javascript'}{literal}';
    const parseJson = async (response) => {
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) throw new Error(data.error || 'Ошибка запроса');
        return data;
    };

    document.getElementById('uploadForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            await parseJson(await fetch('/notes/upload/' + encodeURIComponent(noteUid), {
                method: 'POST', body: new FormData(event.currentTarget)
            }));
            location.reload();
        } catch (error) { alert(error.message); }
    });

    document.querySelectorAll('.delete-attachment').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Удалить вложение?')) return;
            try {
                await parseJson(await fetch('/notes/attachment/delete/' + encodeURIComponent(button.dataset.id), { method: 'POST' }));
                location.reload();
            } catch (error) { alert(error.message); }
        });
    });

    document.getElementById('shareForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const result = await parseJson(await fetch('/notes/share/' + encodeURIComponent(noteUid), {
                method: 'POST', body: new FormData(event.currentTarget)
            }));
            if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(result.share_url).catch(() => {});
            alert('Ссылка создана: ' + result.share_url);
            location.reload();
        } catch (error) { alert(error.message); }
    });

    document.getElementById('copyShareUrl')?.addEventListener('click', async () => {
        const input = document.getElementById('shareUrl');
        try {
            if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(input.value);
            else { input.select(); document.execCommand('copy'); }
            alert('Ссылка скопирована');
        } catch (_) { alert('Не удалось скопировать ссылку'); }
    });

    document.getElementById('unshareNote')?.addEventListener('click', async () => {
        if (!confirm('Деактивировать публичную ссылку?')) return;
        try {
            await parseJson(await fetch('/notes/unshare/' + encodeURIComponent(noteUid), { method: 'POST' }));
            location.reload();
        } catch (error) { alert(error.message); }
    });

    const modal = document.getElementById('voiceRecordModal');
    const start = document.getElementById('startRecord');
    const stop = document.getElementById('stopRecord');
    const cancel = document.getElementById('cancelRecord');
    const send = document.getElementById('sendVoice');
    const preview = document.getElementById('voicePreview');
    const status = document.getElementById('recordingStatus');
    let recorder = null;
    let stream = null;
    let chunks = [];
    let recordedBlob = null;

    const closeStream = () => {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    };

    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        document.getElementById('recordVoiceBtn')?.setAttribute('hidden', 'hidden');
    }

    document.getElementById('recordVoiceBtn')?.addEventListener('click', () => { modal.style.display = 'block'; });
    cancel?.addEventListener('click', () => {
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        closeStream();
        recordedBlob = null;
        modal.style.display = 'none';
    });

    start?.addEventListener('click', async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
            recorder = new MediaRecorder(stream);
            chunks = [];
            recordedBlob = null;
            recorder.addEventListener('dataavailable', (event) => { if (event.data.size) chunks.push(event.data); });
            recorder.addEventListener('stop', () => {
                recordedBlob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
                preview.src = URL.createObjectURL(recordedBlob);
                preview.style.display = 'block';
                send.style.display = 'inline-block';
                status.textContent = 'Запись готова к загрузке';
                closeStream();
            }, { once: true });
            recorder.start(1000);
            start.disabled = true;
            stop.disabled = false;
            status.textContent = 'Идёт запись…';
        } catch (error) { alert('Не удалось получить доступ к микрофону: ' + error.message); closeStream(); }
    });

    stop?.addEventListener('click', () => {
        if (!recorder || recorder.state === 'inactive') return;
        recorder.stop();
        start.disabled = false;
        stop.disabled = true;
    });

    send?.addEventListener('click', async () => {
        if (!recordedBlob) return;
        const mime = recordedBlob.type || 'audio/webm';
        const extension = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';
        const file = new File([recordedBlob], 'voice_' + Date.now() + '.' + extension, { type: mime });
        const form = new FormData();
        form.append('attachment', file);
        form.append('is_voice', 'true');
        try {
            await parseJson(await fetch('/notes/upload/' + encodeURIComponent(noteUid), { method: 'POST', body: form }));
            modal.style.display = 'none';
            location.reload();
        } catch (error) { alert(error.message); }
    });
});
{/literal}
</script>
{/block}
