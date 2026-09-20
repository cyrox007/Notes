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

$moduleRoot = $root . '/modules/notes';
$views = [
    'modules/notes/views/index.php',
    'modules/notes/views/edit_view.php',
    'modules/notes/views/editor-013.php',
    'modules/notes/views/shared_view.php',
    'modules/notes/views/note_item/index.php',
];

foreach ($views as $relative) {
    $path = $root . '/' . $relative;
    nativeNotesAssert(is_file($path), "missing isolated Notes view: {$relative}");
    $source = file_get_contents($path);
    nativeNotesAssert(is_string($source), "cannot read isolated Notes view: {$relative}");
    nativeNotesAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeNotesAssert(!str_contains($source, '{include'), "{$relative} still contains Smarty include syntax");
    nativeNotesAssert(!str_contains($source, '{foreach'), "{$relative} still contains Smarty foreach syntax");
    nativeNotesAssert(!str_contains($source, '{if'), "{$relative} still contains Smarty conditional syntax");
    nativeNotesAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
}

foreach ([
    'app/views/notes_page/index.php',
    'app/views/notes_page/edit_view.php',
    'app/views/notes_page/editor-013.php',
    'app/views/notes_page/shared_view.php',
    'app/views/notes_page/index.tpl',
    'app/views/notes_page/edit_view.tpl',
    'app/views/notes_page/editor-013.tpl',
    'app/views/notes_page/shared_view.tpl',
    'app/views/^elements/note_item/index.php',
    'app/views/^elements/note_item/index.tpl',
    'assets/js/notes-list.js',
    'assets/js/notes-editor-013.js',
    'assets/js/notes-draft.js',
    'app/controllers/NoteController.php',
    'app/controllers/NoteAttachmentController.php',
    'app/controllers/NoteShareController.php',
    'app/models/NoteModel.php',
    'app/middlewares/RequireNotesUse.php',
] as $legacyPath) {
    nativeNotesAssert(!file_exists($root . '/' . $legacyPath), "legacy Notes runtime artifact remains: {$legacyPath}");
}

foreach ([
    'modules/notes/runtime.php',
    'modules/notes/NotesRuntimeProvider.php',
    'modules/notes/controllers/NoteController.php',
    'modules/notes/controllers/NoteAttachmentController.php',
    'modules/notes/controllers/NoteShareController.php',
    'modules/notes/models/NoteModel.php',
    'modules/notes/middlewares/RequireNotesUse.php',
    'modules/notes/assets/style.css',
    'modules/notes/assets/editor-013.css',
    'modules/notes/assets/notes-list.js',
    'modules/notes/assets/notes-editor-013.js',
    'modules/notes/assets/notes-draft.js',
] as $ownedPath) {
    nativeNotesAssert(is_file($root . '/' . $ownedPath), "isolated Notes owned file is missing: {$ownedPath}");
}

$manifest = json_decode((string) file_get_contents($moduleRoot . '/module.json'), true);
nativeNotesAssert(is_array($manifest), 'Notes module manifest is invalid JSON');
nativeNotesAssert(($manifest['runtime']['mode'] ?? null) === 'isolated', 'Notes manifest is not isolated');
nativeNotesAssert(($manifest['runtime']['entrypoint'] ?? null) === 'runtime.php', 'Notes runtime entrypoint drifted');
nativeNotesAssert(in_array('workspace.notes', (array) ($manifest['capabilities'] ?? []), true), 'Notes capability declaration is missing');

$provider = (string) file_get_contents($moduleRoot . '/NotesRuntimeProvider.php');
nativeNotesAssert(str_contains($provider, "return 'notes';"), 'Notes runtime provider id drifted');
nativeNotesAssert(str_contains($provider, "'workspace.notes'"), 'Notes runtime provider does not export workspace.notes');
nativeNotesAssert(str_contains($provider, "\$router->group('/notes')"), 'Notes provider does not own /notes routes');

$coreRoutes = (string) file_get_contents($root . '/core/routerConfig.php');
nativeNotesAssert(!str_contains($coreRoutes, "\$router->group('/notes')"), 'core router still owns Notes routes');
nativeNotesAssert(!str_contains($coreRoutes, 'NoteController'), 'core router still imports a Notes controller');

$index = (string) file_get_contents($moduleRoot . '/views/index.php');
nativeNotesAssert(str_contains($index, '$view->layout(\'core/base\''), 'notes index does not use native application shell');
nativeNotesAssert(str_contains($index, "route('note_create')"), 'note create route is missing');
nativeNotesAssert(str_contains($index, '$view->csrfInput()'), 'note create form lost CSRF input');
nativeNotesAssert(str_contains($index, "partial('@notes/note_item/index'"), 'isolated note item partial is not used');
nativeNotesAssert(str_contains($index, 'id="personal"'), 'personal notes list hook is missing');
nativeNotesAssert(str_contains($index, 'id="all-user"'), 'admin notes list hook is missing');
nativeNotesAssert(str_contains($index, "moduleAsset('notes', 'style.css')"), 'Notes list style is not module-owned');
nativeNotesAssert(str_contains($index, "moduleAsset('notes', 'notes-list.js')"), 'Notes list behavior is not module-owned');
nativeNotesAssert(!str_contains($index, '/assets/js/notes-list.js'), 'Notes list still references the legacy global asset path');

$item = (string) file_get_contents($moduleRoot . '/views/note_item/index.php');
nativeNotesAssert(str_contains($item, "route('edit_page'"), 'note edit route is missing');
nativeNotesAssert(str_contains($item, "route('delete_note'"), 'note delete route is missing');
nativeNotesAssert(str_contains($item, '$view->csrfInput()'), 'note delete form lost CSRF input');
nativeNotesAssert(str_contains($item, '$readOnly'), 'read-only admin metadata contract is missing');

$edit = (string) file_get_contents($moduleRoot . '/views/edit_view.php');
nativeNotesAssert(str_contains($edit, "partial('@notes/editor-013'"), 'isolated native editor partial is not used');
nativeNotesAssert(str_contains($edit, "moduleAsset('notes', 'notes-editor-013.js')"), 'Notes editor behavior is not module-owned');
nativeNotesAssert(str_contains($edit, "moduleAsset('notes', 'notes-draft.js')"), 'Notes draft behavior is not module-owned');
nativeNotesAssert(!str_contains($edit, '/assets/js/notes-editor-013.js'), 'Notes editor still references the deleted global asset');
nativeNotesAssert(!str_contains($edit, '<script'), 'Notes editor injects a duplicate direct script tag');
nativeNotesAssert(str_contains($edit, '$view->layout(\'core/base\''), 'note editor wrapper does not use native shell');

$editor = (string) file_get_contents($moduleRoot . '/views/editor-013.php');
foreach (['note_attachment_upload', 'note_share', 'note_unshare', 'update_note', 'note_attachment_download', 'note_attachment_delete'] as $route) {
    nativeNotesAssert(str_contains($editor, "route('{$route}'"), "notes editor route {$route} is missing");
}
foreach (['data-note-editor-013', 'id="recordVoiceBtn"', 'id="voiceRecorder"', 'id="uploadForm"', 'id="shareForm"', 'id="attachmentsList"'] as $hook) {
    nativeNotesAssert(str_contains($editor, $hook), "notes editor DOM hook {$hook} is missing");
}
nativeNotesAssert(str_contains($editor, '$view->csrfInput()'), 'note update form lost CSRF input');
nativeNotesAssert(str_contains($editor, '$view->e($noteRow[\'content\'] ?? \'\')'), 'note content is not escaped in editor');

$shared = (string) file_get_contents($moduleRoot . '/views/shared_view.php');
nativeNotesAssert(str_contains($shared, '$view->layout(\'core/base\''), 'shared note does not use native shell');
nativeNotesAssert(str_contains($shared, '$attachment[\'file_url\']'), 'shared note does not consume controller-provided protected attachment URL');
nativeNotesAssert(!str_contains($shared, "route('update_note'"), 'shared note unexpectedly exposes update route');
nativeNotesAssert(!str_contains($shared, "route('delete_note'"), 'shared note unexpectedly exposes delete route');
nativeNotesAssert(str_contains($shared, '$view->e($noteRow[\'content\'])'), 'shared note content is not escaped');
nativeNotesAssert(str_contains($shared, "moduleAsset('notes', 'style.css')"), 'shared note style is not module-owned');

$listJs = (string) file_get_contents($moduleRoot . '/assets/notes-list.js');
nativeNotesAssert(!str_contains($listJs, '{literal}'), 'notes-list.js still contains Smarty literal markers');
nativeNotesAssert(str_contains($listJs, "classList.toggle('visible'"), 'notes admin list toggle behavior is missing');

$draftJs = (string) file_get_contents($moduleRoot . '/assets/notes-draft.js');
nativeNotesAssert(
    str_contains($draftJs, "submit.insertAdjacentElement('afterend', status)"),
    'draft status is still injected inside the save-button container'
);

$editorCss = (string) file_get_contents($moduleRoot . '/assets/editor-013.css');
nativeNotesAssert(
    str_contains($editorCss, 'grid-template-columns:auto minmax(160px,1fr) auto'),
    'note save row does not reserve independent space for button, status and hint'
);
nativeNotesAssert(
    str_contains($editorCss, '.note-editor-013 .share-form{display:grid;gap:10px}'),
    'note share form spacing regression is not protected'
);

$shareController = (string) file_get_contents($moduleRoot . '/controllers/NoteShareController.php');
nativeNotesAssert(str_contains($shareController, "header('Cache-Control: no-store, max-age=0')"), 'shared-note no-store header contract is missing');
nativeNotesAssert(str_contains($shareController, "header('Referrer-Policy: no-referrer')"), 'shared-note referrer protection is missing');
nativeNotesAssert(str_contains($shareController, "'file_url'"), 'shared attachment URL is not prepared server-side');
nativeNotesAssert(str_contains($shareController, "render_template('@notes/shared_view'"), 'shared note controller does not use isolated view namespace');

echo "[OK] isolated native Notes module contract\n";
