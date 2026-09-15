<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeNotesAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$views = [
    'app/views/notes_page/index.php',
    'app/views/notes_page/edit_view.php',
    'app/views/notes_page/editor-013.php',
    'app/views/notes_page/shared_view.php',
    'app/views/^elements/note_item/index.php',
];

foreach ($views as $relative) {
    $path = $root . '/' . $relative;
    nativeNotesAssert(is_file($path), "missing native notes view: {$relative}");
    $source = file_get_contents($path);
    nativeNotesAssert(is_string($source), "cannot read native notes view: {$relative}");
    nativeNotesAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeNotesAssert(!str_contains($source, '{include'), "{$relative} still contains Smarty include syntax");
    nativeNotesAssert(!str_contains($source, '{foreach'), "{$relative} still contains Smarty foreach syntax");
    nativeNotesAssert(!str_contains($source, '{if'), "{$relative} still contains Smarty conditional syntax");
    nativeNotesAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
}

$index = (string) file_get_contents($root . '/app/views/notes_page/index.php');
nativeNotesAssert(str_contains($index, '$view->layout(\'core/base\''), 'notes index does not use native application shell');
nativeNotesAssert(str_contains($index, "route('note_create')"), 'note create route is missing');
nativeNotesAssert(str_contains($index, '$view->csrfInput()'), 'note create form lost CSRF input');
nativeNotesAssert(str_contains($index, "partial('^elements/note_item/index'"), 'native note item partial is not used');
nativeNotesAssert(str_contains($index, 'id="personal"'), 'personal notes list hook is missing');
nativeNotesAssert(str_contains($index, 'id="all-user"'), 'admin notes list hook is missing');
nativeNotesAssert(str_contains($index, '/assets/js/notes-list.js'), 'static notes list behavior bundle is missing');

$item = (string) file_get_contents($root . '/app/views/^elements/note_item/index.php');
nativeNotesAssert(str_contains($item, "route('edit_page'"), 'note edit route is missing');
nativeNotesAssert(str_contains($item, "route('delete_note'"), 'note delete route is missing');
nativeNotesAssert(str_contains($item, '$view->csrfInput()'), 'note delete form lost CSRF input');
nativeNotesAssert(str_contains($item, '$readOnly'), 'read-only admin metadata contract is missing');

$edit = (string) file_get_contents($root . '/app/views/notes_page/edit_view.php');
nativeNotesAssert(str_contains($edit, "partial('notes_page/editor-013'"), 'native editor partial is not used');
nativeNotesAssert(str_contains($edit, '/assets/js/notes-editor-013.js'), 'notes editor behavior bundle is missing');
nativeNotesAssert(str_contains($edit, '$view->layout(\'core/base\''), 'note editor wrapper does not use native shell');

$editor = (string) file_get_contents($root . '/app/views/notes_page/editor-013.php');
foreach (['note_attachment_upload', 'note_share', 'note_unshare', 'update_note', 'note_attachment_download', 'note_attachment_delete'] as $route) {
    nativeNotesAssert(str_contains($editor, "route('{$route}'"), "notes editor route {$route} is missing");
}
foreach (['data-note-editor-013', 'id="recordVoiceBtn"', 'id="voiceRecorder"', 'id="uploadForm"', 'id="shareForm"', 'id="attachmentsList"'] as $hook) {
    nativeNotesAssert(str_contains($editor, $hook), "notes editor DOM hook {$hook} is missing");
}
nativeNotesAssert(str_contains($editor, '$view->csrfInput()'), 'note update form lost CSRF input');
nativeNotesAssert(str_contains($editor, '$view->e($noteRow[\'content\'] ?? \'\')'), 'note content is not escaped in editor');

$shared = (string) file_get_contents($root . '/app/views/notes_page/shared_view.php');
nativeNotesAssert(str_contains($shared, '$view->layout(\'core/base\''), 'shared note does not use native shell');
nativeNotesAssert(str_contains($shared, '$attachment[\'file_url\']'), 'shared note does not consume controller-provided protected attachment URL');
nativeNotesAssert(!str_contains($shared, "route('update_note'"), 'shared note unexpectedly exposes update route');
nativeNotesAssert(!str_contains($shared, "route('delete_note'"), 'shared note unexpectedly exposes delete route');
nativeNotesAssert(str_contains($shared, '$view->e($noteRow[\'content\'])'), 'shared note content is not escaped');

$listJsPath = $root . '/assets/js/notes-list.js';
nativeNotesAssert(is_file($listJsPath), 'static notes-list.js is missing');
$listJs = (string) file_get_contents($listJsPath);
nativeNotesAssert(!str_contains($listJs, '{literal}'), 'notes-list.js still contains Smarty literal markers');
nativeNotesAssert(str_contains($listJs, "classList.toggle('visible'"), 'notes admin list toggle behavior is missing');

$shareController = (string) file_get_contents($root . '/app/controllers/NoteShareController.php');
nativeNotesAssert(str_contains($shareController, "header('Cache-Control: no-store, max-age=0')"), 'shared-note no-store header contract is missing');
nativeNotesAssert(str_contains($shareController, "header('Referrer-Policy: no-referrer')"), 'shared-note referrer protection is missing');
nativeNotesAssert(str_contains($shareController, "'file_url'"), 'shared attachment URL is not prepared server-side');

echo "[OK] native notes views contract\n";
