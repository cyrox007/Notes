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
nativeFileManagerAssert(str_contains($source, 'id="btn-create-folder"'), 'create-folder JS hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="btn-upload-file"'), 'upload JS hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-create-folder"'), 'create-folder modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-rename"'), 'rename modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="media-player-modal"'), 'media preview modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="text-preview-modal"'), 'text preview modal hook was dropped');
nativeFileManagerAssert(str_contains($source, 'id="modal-upload-progress"'), 'upload progress modal hook was dropped');
nativeFileManagerAssert(str_contains($source, "route('files_get'"), 'protected file route was dropped');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'script.js')"), 'Files module behavior asset was dropped');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'quota.js')"), 'Files module quota asset was dropped');
nativeFileManagerAssert(str_contains($source, "moduleAsset('files', 'share.js')"), 'Files module share behavior asset was dropped');
nativeFileManagerAssert(str_contains($source, 'data-uid="<?= $view->e($file[\'uid\'] ?? \'\') ?>"'), 'File Manager does not expose a safe file UID to share controls');
nativeFileManagerAssert(str_contains($source, 'class="file-manager__action-btn file-manager__action-btn--share btn-share"'), 'File Manager share action is missing');

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
