<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$noteRow = isset($note) && is_array($note) ? $note : [];
$attachmentRows = isset($attachments) && is_array($attachments) ? $attachments : [];
$share = isset($shareInfo) && is_array($shareInfo) ? $shareInfo : null;
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$uid = (string) ($noteRow['uid'] ?? '');
$noteName = trim((string) ($noteRow['notename'] ?? ''));
if ($noteName === '') {
    $noteName = 'Без названия';
}
$voiceRows = [];
$fileRows = [];
foreach ($attachmentRows as $attachment) {
    if (!is_array($attachment)) {
        continue;
    }
    if (($attachment['file_type'] ?? '') === 'voice') {
        $voiceRows[] = $attachment;
    } else {
        $fileRows[] = $attachment;
    }
}
?>
<div class="note-editor-013"
     data-note-editor-013
     data-upload-url="<?= $view->e($view->route('note_attachment_upload', ['uid' => $uid])) ?>"
     data-share-url="<?= $view->e($view->route('note_share', ['uid' => $uid])) ?>"
     data-unshare-url="<?= $view->e($view->route('note_unshare', ['uid' => $uid])) ?>">
    <header class="note-editor-013__topbar">
        <div class="note-editor-013__title-group">
            <span class="ux-kicker">Блокнот</span>
            <h1><?= $view->e($noteName) ?></h1>
            <div class="note-editor-013__meta">
                <span>Автор: <?= $view->e(($noteRow['author_username'] ?? '') !== '' ? $noteRow['author_username'] : 'Неизвестно') ?></span>
                <span>Создано: <?= $view->e($noteRow['created_note'] ?? '') ?></span>
                <span>Изменено: <?= $view->e($noteRow['updated_note'] ?? '') ?></span>
            </div>
        </div>
        <div class="note-editor-013__top-actions">
            <button id="recordVoiceBtn" type="button" class="note-editor-013__voice-button"><i class="fa fa-microphone" aria-hidden="true"></i> Голосовая заметка</button>
            <a class="btn btn-secondary" href="<?= $view->e($view->route('notes')) ?>">К списку</a>
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
            <form class="note__edit" action="<?= $view->e($view->route('update_note', ['uid' => $uid])) ?>" method="post">
                <?= $view->csrfInput() ?>
                <div class="note__name-input"><input type="text" name="notename" maxlength="255" placeholder="Название заметки" value="<?= $view->e($noteRow['notename'] ?? '') ?>" aria-label="Название заметки"></div>
                <div class="note__text"><textarea name="content" class="textarea" placeholder="Начните писать…"><?= $view->e($noteRow['content'] ?? '') ?></textarea></div>
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
                    <?php if ($voiceRows !== []): ?>
                        <?php foreach ($voiceRows as $attachment): ?>
                            <?php $downloadUrl = $view->route('note_attachment_download', ['fileUid' => (string) ($attachment['file_uid'] ?? '')]); ?>
                            <div class="attachment-item attachment-item--voice" data-id="<?= $view->e($attachment['id'] ?? '') ?>" data-file-type="voice">
                                <span class="note-editor-013__voice-icon"><i class="fa fa-microphone" aria-hidden="true"></i></span>
                                <div class="note-editor-013__voice-copy">
                                    <strong class="attachment-name">Голосовая заметка</strong>
                                    <audio controls preload="metadata"><source src="<?= $view->e($downloadUrl) ?>" type="<?= $view->e($attachment['mime_type'] ?? '') ?>"></audio>
                                    <div><span class="attachment-size"><?= $view->e($attachment['formatted_size'] ?? '') ?></span><?php if (!empty($attachment['duration'])): ?><span class="attachment-duration"> · <?= $view->e($attachment['duration']) ?> сек</span><?php endif; ?></div>
                                </div>
                                <button type="button" class="btn-icon delete-attachment" data-delete-url="<?= $view->e($view->route('note_attachment_delete', ['attachmentId' => (int) ($attachment['id'] ?? 0)])) ?>" aria-label="Удалить голосовую заметку"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="note-editor-013__empty">Голосовых пока нет. Нажмите «Голосовая заметка» сверху.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="note-editor-013__side-card">
                <div class="note-editor-013__section-head"><div><h2>Файлы</h2><p>Документы и медиа в приватном хранилище.</p></div></div>
                <form id="uploadForm" class="note-editor-013__file-form" enctype="multipart/form-data">
                    <input type="file" name="attachment" id="attachmentInput" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,video/mp4,video/webm,video/quicktime,.pdf,.txt,.md,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp" required>
                    <input type="hidden" name="is_voice" value="false"><button type="submit" class="btn btn-secondary">Добавить файл</button>
                </form>
                <div id="attachmentsList" class="note-editor-013__attachments">
                    <?php if ($fileRows !== []): ?>
                        <?php foreach ($fileRows as $attachment): ?>
                            <?php
                                $type = (string) ($attachment['file_type'] ?? 'file');
                                $downloadUrl = $view->route('note_attachment_download', ['fileUid' => (string) ($attachment['file_uid'] ?? '')]);
                            ?>
                            <div class="attachment-item" data-id="<?= $view->e($attachment['id'] ?? '') ?>" data-file-type="<?= $view->e($type) ?>">
                                <div class="attachment-preview">
                                    <?php if ($type === 'audio'): ?>
                                        <audio controls preload="metadata"><source src="<?= $view->e($downloadUrl) ?>" type="<?= $view->e($attachment['mime_type'] ?? '') ?>"></audio>
                                    <?php elseif ($type === 'image'): ?>
                                        <img src="<?= $view->e($downloadUrl) ?>" alt="<?= $view->e($attachment['file_name'] ?? '') ?>" loading="lazy" style="max-width:100%;max-height:160px;object-fit:contain;">
                                    <?php elseif ($type === 'video'): ?>
                                        <video controls preload="metadata" style="max-width:100%;"><source src="<?= $view->e($downloadUrl) ?>" type="<?= $view->e($attachment['mime_type'] ?? '') ?>"></video>
                                    <?php else: ?>
                                        <a href="<?= $view->e($downloadUrl) ?>"><?= $view->e($attachment['file_name'] ?? '') ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="attachment-info"><span class="attachment-name"><?= $view->e($attachment['file_name'] ?? '') ?></span><span class="attachment-size"><?= $view->e($attachment['formatted_size'] ?? '') ?></span></div>
                                <button type="button" class="btn-icon delete-attachment" data-delete-url="<?= $view->e($view->route('note_attachment_delete', ['attachmentId' => (int) ($attachment['id'] ?? 0)])) ?>" aria-label="Удалить вложение"><i class="fa fa-trash" aria-hidden="true"></i></button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="note-editor-013__empty">Файлов пока нет.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="note-editor-013__side-card">
                <div class="note-editor-013__section-head"><div><h2>Поделиться</h2><p>Ссылка даёт только просмотр и может быть отключена.</p></div></div>
                <div class="note-editor-013__share-box">
                    <?php if ($share !== null): ?>
                        <?php $shareToken = (string) ($share['share_token'] ?? ''); $publicShareUrl = $baseUrl . '/notes/shared/' . rawurlencode($shareToken); ?>
                        <div class="note-editor-013__share-url"><input type="text" id="shareUrl" value="<?= $view->e($publicShareUrl) ?>" readonly aria-label="Публичная ссылка"><button id="copyShareUrl" type="button" class="btn btn-secondary">Копировать</button></div>
                        <?php if (!empty($share['expires_at'])): ?><p>Действует до: <?= $view->e($share['expires_at']) ?></p><?php endif; ?>
                        <button id="unshareNote" type="button" class="btn btn-danger">Отключить ссылку</button>
                    <?php else: ?>
                        <form id="shareForm" class="share-form"><input type="hidden" name="access_type" value="view"><label>Срок действия, часов<input type="number" name="expires_in" id="expiresIn" value="0" min="0" max="8760"></label><button type="submit" class="btn btn-primary">Создать ссылку</button></form>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>
