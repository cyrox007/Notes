<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$fileItems = isset($files) && is_array($files) ? $files : [];
$currentFolder = isset($current_folder) && is_array($current_folder) ? $current_folder : null;
$crumbs = isset($breadcrumb) && is_array($breadcrumb) ? $breadcrumb : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';

$iconFor = static function (array $file): string {
    if (($file['type'] ?? '') === 'folder') {
        return 'fa-folder';
    }

    $mime = strtolower((string) ($file['mime_type'] ?? ''));
    $extension = strtolower((string) ($file['extension'] ?? ''));
    if (str_contains($mime, 'image')) {
        return 'fa-file-image-o';
    }
    if (str_contains($mime, 'audio')) {
        return 'fa-file-audio-o';
    }
    if (str_contains($mime, 'video')) {
        return 'fa-file-video-o';
    }
    if ($extension === 'pdf') {
        return 'fa-file-pdf-o';
    }
    if (in_array($extension, ['doc', 'docx'], true)) {
        return 'fa-file-word-o';
    }
    if (in_array($extension, ['ppt', 'pptx'], true)) {
        return 'fa-file-powerpoint-o';
    }
    if (in_array($extension, ['zip', 'rar', '7z', 'tar', 'gz'], true)) {
        return 'fa-file-archive-o';
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return 'fa-file-excel-o';
    }
    if (in_array($extension, ['php', 'js', 'py', 'java', 'cpp', 'c', 'html', 'css', 'json', 'xml', 'sql', 'md', 'txt'], true)) {
        return 'fa-file-code-o';
    }
    return 'fa-file-o';
};

$formatSize = static function (mixed $value): string {
    $size = max(0, (int) $value);
    if ($size < 1024) {
        return $size . ' Б';
    }
    if ($size < 1048576) {
        return round($size / 1024, 1) . ' КБ';
    }
    return round($size / 1048576, 1) . ' МБ';
};

ob_start();
?>
<section class="module-page-header module-page-header--files">
    <div><span class="module-page-header__eyebrow">Хранилище</span><h1>Файлы</h1><p>Папки, документы и защищённые загрузки.</p></div>
</section>
<div class="file-manager"<?php if ($currentFolder !== null): ?> data-current-folder-id="<?= $view->e($currentFolder['id'] ?? '') ?>"<?php endif; ?>>
    <div class="file-manager__toolbar">
        <div class="file-manager__breadcrumb" aria-label="Путь к папке">
            <?php foreach ($crumbs as $index => $crumb): ?>
                <?php if (!is_array($crumb)) { continue; } ?>
                <?php if ($index > 0): ?><span class="file-manager__separator" aria-hidden="true">/</span><?php endif; ?>
                <?php
                    $crumbId = (int) ($crumb['id'] ?? 0);
                    $crumbHref = $crumbId === 0
                        ? $view->route('files')
                        : $view->route('files_folder', ['folderId' => $crumbId]);
                    $isActive = $index === array_key_last($crumbs);
                ?>
                <a href="<?= $view->e($crumbHref) ?>"
                   class="file-manager__breadcrumb-item<?= $isActive ? ' file-manager__breadcrumb-item--active' : '' ?>"
                   <?= $isActive ? 'aria-current="page"' : '' ?>><?= $view->e($crumb['name'] ?? '') ?></a>
            <?php endforeach; ?>
        </div>

        <div class="file-manager__actions">
            <button id="btn-create-folder" type="button" class="file-manager__btn file-manager__btn--primary">
                <i class="fa fa-folder-o" aria-hidden="true"></i> Новая папка
            </button>
            <button id="btn-upload-file" type="button" class="file-manager__btn file-manager__btn--success">
                <i class="fa fa-upload" aria-hidden="true"></i> Загрузить
            </button>
            <input type="file" id="file-input" hidden multiple>
        </div>
    </div>

    <section id="file-manager-quota" class="file-manager__quota" data-url="<?= $view->e($view->route('files_quota')) ?>" aria-label="Использование хранилища">
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
        <?php if ($fileItems === []): ?>
            <div class="file-manager__empty">
                <i class="fa fa-folder-open-o" aria-hidden="true"></i>
                <strong>Папка пуста</strong>
                <p>Создайте папку или загрузите файл, чтобы начать.</p>
            </div>
        <?php else: ?>
            <div class="file-manager__grid">
                <?php foreach ($fileItems as $file): ?>
                    <?php if (!is_array($file)) { continue; } ?>
                    <?php
                        $id = (int) ($file['id'] ?? 0);
                        $type = (string) ($file['type'] ?? 'file');
                        $name = (string) ($file['name'] ?? '');
                        $extension = (string) ($file['extension'] ?? '');
                        $displayName = $name . ($type !== 'folder' && $extension !== '' ? '.' . $extension : '');
                        $icon = $iconFor($file);
                    ?>
                    <div class="file-manager__item"
                         data-id="<?= $view->e($id) ?>"
                         data-type="<?= $view->e($type) ?>"
                         data-name="<?= $view->e($name) ?>"
                         data-extension="<?= $view->e($extension) ?>"
                         tabindex="0">
                        <div class="file-manager__item-icon">
                            <i class="fa <?= $view->e($icon) ?>" aria-hidden="true"></i>
                        </div>
                        <div class="file-manager__item-name"><?= $view->e($displayName) ?></div>
                        <div class="file-manager__item-meta">
                            <?= $type === 'folder' ? 'Папка' : $view->e($formatSize($file['size'] ?? 0)) ?>
                        </div>
                        <div class="file-manager__item-actions">
                            <?php if ($type === 'folder'): ?>
                                <a href="<?= $view->e($view->route('files_folder', ['folderId' => $id])) ?>" class="file-manager__action-btn" title="Открыть" aria-label="Открыть <?= $view->e($name) ?>">
                                    <i class="fa fa-folder-open-o" aria-hidden="true"></i>
                                </a>
                            <?php else: ?>
                                <a href="<?= $view->e($view->route('files_get', ['fileId' => $id])) ?>" class="file-manager__action-btn" title="Открыть" aria-label="Открыть <?= $view->e($name) ?>" target="_blank" rel="noopener">
                                    <i class="fa fa-eye" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="file-manager__action-btn file-manager__action-btn--rename btn-rename" title="Переименовать" aria-label="Переименовать <?= $view->e($name) ?>">
                                <i class="fa fa-pencil" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="file-manager__action-btn file-manager__action-btn--delete btn-delete" title="Удалить" aria-label="Удалить <?= $view->e($name) ?>">
                                <i class="fa fa-trash" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
        <div class="file-manager__modal-body"><div id="player-container"></div></div>
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
            <div class="file-manager__editor-info"><small>Режим только для чтения. Код на странице не выполняется.</small></div>
        </div>
    </div>
</div>

<div id="modal-upload-progress" class="file-manager__modal" role="dialog" aria-modal="true" aria-labelledby="upload-title">
    <div class="file-manager__modal-content">
        <div class="file-manager__modal-header"><h3 id="upload-title">Загрузка файла</h3></div>
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
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Файловый менеджер',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('files', 'style.css'),
        $view->moduleAsset('files', 'quota.css'),
        $view->moduleAsset('files', 'polish.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('files', 'script.js'),
        $view->moduleAsset('files', 'quota.js'),
        $view->moduleAsset('files', 'polish.js'),
        $view->moduleAsset('files', 'drop-upload.js'),
    ],
], $content);
