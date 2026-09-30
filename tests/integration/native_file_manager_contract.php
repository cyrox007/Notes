<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/modules/files/views/index.php';

function nativeFileManagerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

nativeFileManagerAssert(is_file($path), 'native file manager view is missing');
$source = file_get_contents($path);
nativeFileManagerAssert(is_string($source), 'cannot read native file manager view');
nativeFileManagerAssert(!str_contains($source, '{extends'), 'file manager still contains Smarty extends syntax');
nativeFileManagerAssert(!str_contains($source, '{foreach'), 'file manager still contains Smarty foreach syntax');
nativeFileManagerAssert(!str_contains($source, '{if'), 'file manager still contains Smarty conditional syntax');
nativeFileManagerAssert(!str_contains($source, '$smarty'), 'file manager still reads Smarty runtime state');
nativeFileManagerAssert(str_contains($source, '$view->layout(\'core/base\''), 'file manager does not use native application shell');
nativeFileManagerAssert(str_contains($source, '$view->e($displayName)'), 'file names are not escaped before HTML output');
nativeFileManagerAssert(str_contains($source, 'data-current-folder-id'), 'current folder data contract was dropped');
nativeFileManagerAssert(str_contains($source, "['vsd', 'vsdx']"), 'для VSD/VSDX не назначена иконка документа');
nativeFileManagerAssert(str_contains($source, "['pdf', 'djvu']"), 'для DJVU не назначена иконка документа');
$djvuIconPosition = strpos($source, "['pdf', 'djvu']");
$imageMimeIconPosition = strpos($source, "str_contains(\$mime, 'image')");
nativeFileManagerAssert(
    $djvuIconPosition !== false && $imageMimeIconPosition !== false && $djvuIconPosition < $imageMimeIconPosition,
    'DJVU ошибочно определяется как обычное изображение до проверки расширения'
);
nativeFileManagerAssert(str_contains($source, 'id="btn-create-folder"'), 'create-folder JS hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="btn-upload-file"'), 'upload JS hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-create-folder"'), 'create-folder modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-rename"'), 'rename modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="media-player-modal"'), 'media preview modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="text-preview-modal"'), 'text preview modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-upload-progress"'), 'upload progress modal hook was dropped');
nativeFileManagerAssert(!str_contains($source, 'fa fa-eye'), 'кнопка-глазок всё ещё отображается вместо открытия по объекту');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'script.js')"), 'Files module behavior asset was dropped');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'quota.js')"), 'Files module quota asset was dropped');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'share.js')"), 'Files module share behavior asset was dropped');
nativeFileManagerAssert(str_contains($source, 'data-uid="<?= $view->e($file[\'uid\'] ?? \'\') ?>"'), 'File Manager does not expose a safe file UID to share controls');
nativeFileManagerAssert(str_contains($source, 'class="file-manager__action-btn file-manager__action-btn--share btn-share"'), 'File Manager share action is missing');

$fileController = (string) file_get_contents($root . '/modules/files/controllers/FileController.php');
nativeFileManagerAssert(str_contains($fileController, "'vsd' =>"), 'загрузка VSD не разрешена');
nativeFileManagerAssert(str_contains($fileController, "'vsdx' =>"), 'загрузка VSDX не разрешена');
nativeFileManagerAssert(str_contains($fileController, "'djvu' =>"), 'загрузка DJVU не разрешена');
nativeFileManagerAssert(str_contains($fileController, "'zip' =>"), 'загрузка ZIP не разрешена');
nativeFileManagerAssert(str_contains($fileController, 'FileUploadLimitService'), 'File Manager не использует системную настройку размера загрузки');
nativeFileManagerAssert(!str_contains($fileController, 'DEFAULT_MAX_UPLOAD_SIZE'), 'в FileController остался жёстко заданный лимит 10 МБ');
nativeFileManagerAssert(str_contains($fileController, 'application/vnd.ms-visio.drawing.main+xml'), 'MIME VSDX не разрешён');
nativeFileManagerAssert(str_contains($fileController, 'image/vnd.djvu'), 'MIME DJVU не разрешён');
$djvuTypePosition = strpos($fileController, "in_array(\$extension, ['pdf', 'djvu'");
$imageMimeTypePosition = strpos($fileController, "str_starts_with(\$mimeType, 'image/')");
nativeFileManagerAssert(
    $djvuTypePosition !== false && $imageMimeTypePosition !== false && $djvuTypePosition < $imageMimeTypePosition,
    'DJVU должен определяться как документ до общей проверки image MIME'
);

$scriptJs = (string) file_get_contents($root . '/modules/files/assets/script.js');
nativeFileManagerAssert(str_contains($scriptJs, "root.addEventListener('click'"), 'открытие файла по клику на объект не подключено');
nativeFileManagerAssert(str_contains($scriptJs, 'if (item) openItem(item);'), 'клик по объекту не вызывает открытие файла');
nativeFileManagerAssert(!str_contains($scriptJs, "root.addEventListener('dblclick'"), 'для открытия файла всё ещё требуется двойной клик');
nativeFileManagerAssert(str_contains($scriptJs, "window.open(fileUrl(id), '_blank'"), 'обычные документы не открываются по клику на объект');
nativeFileManagerAssert(str_contains($scriptJs, 'effectiveUploadLimit'), 'интерфейс не проверяет известный серверный потолок до начала загрузки');

$shareJs = (string) file_get_contents($root . '/modules/files/assets/share.js');
nativeFileManagerAssert(str_contains($shareJs, "appPath('/files/share/'"), 'File Manager public-link request is not BASE_PATH-aware');
nativeFileManagerAssert(str_contains($shareJs, 'navigator.clipboard.writeText'), 'File Manager does not copy created links');

$capability = (string) file_get_contents($root . '/modules/files/FilesCapability.php');
nativeFileManagerAssert(str_contains($capability, 'WorkspaceFileProvider'), 'Files capability does not expose the cross-module file boundary');
nativeFileManagerAssert(str_contains($capability, 'createWorkspaceFileShare('), 'Files capability cannot create revocable public links');
nativeFileManagerAssert(str_contains($capability, "'files', 'can_share'"), 'Files public-link capability bypasses the role policy');

$provider = (string) file_get_contents($root . '/modules/files/FilesRuntimeProvider.php');
nativeFileManagerAssert(str_contains($provider, "'/share/{str:uid}'"), 'File share-create route is missing');
nativeFileManagerAssert(str_contains($provider, "'/shared/{str:token}'"), 'Public file-share route is missing');

echo "[OK] native file manager view contract\n";
