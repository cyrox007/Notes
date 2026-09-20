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

echo "[OK] native file manager view contract\n";
