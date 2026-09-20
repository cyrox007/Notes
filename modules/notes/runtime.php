<?php

declare(strict_types=1);

$root = __DIR__;
$files = [
    '/models/NoteModel.php',
    '/models/NoteAttachmentModel.php',
    '/models/NoteTagModel.php',
    '/models/NoteTagRelationModel.php',
    '/models/SharedNoteModel.php',
    '/middlewares/RequireNotesUse.php',
    '/middlewares/EnforceNoteCreatePolicy.php',
    '/middlewares/EnforceNoteAttachmentPolicy.php',
    '/middlewares/EnforceNoteSharePolicy.php',
    '/controllers/NoteController.php',
    '/controllers/NoteAttachmentController.php',
    '/controllers/NoteShareController.php',
    '/NotesCapability.php',
    '/NotesRuntimeProvider.php',
];

foreach ($files as $file) {
    $path = $root . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Notes runtime file is missing or unsafe: ' . $file);
    }
    require_once $path;
}

return new \Modules\Notes\NotesRuntimeProvider();
