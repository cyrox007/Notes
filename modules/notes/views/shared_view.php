<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$noteRow = isset($note) && is_array($note) ? $note : [];
$attachmentRows = isset($attachments) && is_array($attachments) ? $attachments : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$noteName = trim((string) ($noteRow['notename'] ?? ''));
if ($noteName === '') { $noteName = 'Без названия'; }

ob_start();
?>
<section class="shared-note">
    <div class="note-header">
        <h1><?= $view->e($noteName) ?></h1>
        <p class="note-meta">Автор: <strong><?= $view->e($noteRow['owner'] ?? '') ?></strong> | Создано: <?= $view->e($noteRow['created_note'] ?? '') ?> | Обновлено: <?= $view->e($noteRow['updated_note'] ?? '') ?></p>
        <p class="view-notice">Ссылка предоставляет только просмотр. Владелец может отключить её в любой момент.</p>
    </div>
    <div class="note-content-display">
        <h3>Содержимое</h3>
        <div class="note-text"><?php if ((string) ($noteRow['content'] ?? '') !== ''): ?><?= $view->e($noteRow['content']) ?><?php else: ?><em>Текст отсутствует</em><?php endif; ?></div>
    </div>
    <?php if ($attachmentRows !== []): ?>
        <div class="note-attachments-display">
            <h3>Вложения (<?= count($attachmentRows) ?>)</h3>
            <?php foreach ($attachmentRows as $attachment): ?>
                <?php
                    if (!is_array($attachment)) { continue; }
                    $attachmentUrl = (string) ($attachment['file_url'] ?? '');
                    $type = (string) ($attachment['file_type'] ?? 'file');
                    $mime = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
                    $fileName = (string) ($attachment['file_name'] ?? 'Вложение');
                ?>
                <div class="attachment-item">
                    <div class="attachment-preview">
                        <?php if ($type === 'voice' || $type === 'audio'): ?>
                            <audio controls preload="metadata"><source src="<?= $view->e($attachmentUrl) ?>" type="<?= $view->e($mime) ?>">Ваш браузер не поддерживает аудио.</audio>
                        <?php elseif ($type === 'image'): ?>
                            <img src="<?= $view->e($attachmentUrl) ?>" alt="<?= $view->e($fileName) ?>" loading="lazy">
                        <?php elseif ($type === 'video'): ?>
                            <video controls preload="metadata"><source src="<?= $view->e($attachmentUrl) ?>" type="<?= $view->e($mime) ?>">Ваш браузер не поддерживает видео.</video>
                        <?php else: ?>
                            <a href="<?= $view->e($attachmentUrl) ?>"><?= $view->e($fileName) ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="attachment-info"><span class="attachment-name"><?= $view->e($fileName) ?></span><span class="attachment-size"><?= $view->e($attachment['formatted_size'] ?? '') ?></span></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="share-actions"><a href="<?= $view->e($view->route('main')) ?>" class="btn btn-secondary">Вернуться на главную</a></div>
</section>
<style>
.shared-note{max-width:900px;margin:0 auto;padding:20px}.shared-note .note-header{padding:30px;margin-bottom:30px}.shared-note .note-meta{font-size:.95em}.shared-note .view-notice{margin-top:15px;padding:10px;background:#f1f5f9;border-radius:6px}.note-content-display{background:#fff;padding:25px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);margin-bottom:25px}.note-text{line-height:1.8;font-size:1.05em;white-space:pre-wrap;overflow-wrap:anywhere}.note-attachments-display{background:#f8f9fa;padding:25px;border-radius:8px;margin-bottom:25px}.note-attachments-display .attachment-item{display:flex;align-items:center;gap:15px;padding:15px;margin-bottom:15px;background:#fff;border:1px solid #e0e0e0;border-radius:8px}.note-attachments-display .attachment-preview{flex-shrink:0}.note-attachments-display img,.note-attachments-display video{max-width:300px;max-height:240px;object-fit:contain}.note-attachments-display audio{max-width:100%}.note-attachments-display .attachment-info{flex:1;display:flex;flex-direction:column;gap:5px;min-width:0}.share-actions{text-align:center;margin-top:30px}@media(max-width:768px){.note-attachments-display .attachment-item{flex-direction:column;align-items:flex-start}.note-attachments-display img,.note-attachments-display video{max-width:100%}}
</style>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Просмотр заметки: ' . $noteName,
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'module_styles' => [$view->moduleAsset('notes', 'style.css')],
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
