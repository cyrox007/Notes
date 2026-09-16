<?php

declare(strict_types=1);

namespace Modules\Notes;

use App\Controllers\NoteAttachmentController;
use App\Controllers\NoteController;
use App\Controllers\NoteShareController;
use App\Middlewares\EnforceNoteAttachmentPolicy;
use App\Middlewares\EnforceNoteCreatePolicy;
use App\Middlewares\EnforceNoteSharePolicy;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireNotesUse;
use App\Middlewares\UploadRateLimit;
use Core\ModuleRuntimeProvider;
use Core\Router;

final class NotesRuntimeProvider implements ModuleRuntimeProvider
{
    private NotesCapability $capability;

    public function __construct()
    {
        $this->capability = new NotesCapability();
    }

    public function moduleId(): string
    {
        return 'notes';
    }

    public function boot(): void
    {
        // Module-owned classes are loaded explicitly by runtime.php. No recursive
        // directory scan is permitted for isolated modules.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.notes' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/notes')
            ->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class, RequireNotesUse::class], 'notes')
            ->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class, RequireNotesUse::class, EnforceNoteCreatePolicy::class], 'note_create')
            ->add('GET', '/{str:uid}/edit', [NoteController::class, 'edit'], [LoginRequared::class, RequireNotesUse::class], 'edit_page')
            ->add('POST', '/{str:uid}/edit', [NoteController::class, 'update'], [LoginRequared::class, RequireNotesUse::class], 'update_note')
            ->add('POST', '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class, RequireNotesUse::class], 'delete_note')
            ->add('POST', '/upload/{str:uid}', [NoteAttachmentController::class, 'upload'], [LoginRequared::class, RequireNotesUse::class, UploadRateLimit::class, EnforceNoteAttachmentPolicy::class], 'note_attachment_upload')
            ->add('POST', '/attachment/delete/{int:attachmentId}', [NoteAttachmentController::class, 'delete'], [LoginRequared::class, RequireNotesUse::class], 'note_attachment_delete')
            ->add('GET', '/attachment/{str:fileUid}', [NoteAttachmentController::class, 'download'], [LoginRequared::class, RequireNotesUse::class], 'note_attachment_download')
            ->add('POST', '/share/{str:uid}', [NoteShareController::class, 'create'], [LoginRequared::class, RequireNotesUse::class, EnforceNoteSharePolicy::class], 'note_share')
            ->add('POST', '/unshare/{str:uid}', [NoteShareController::class, 'unshare'], [LoginRequared::class, RequireNotesUse::class], 'note_unshare')
            ->add('GET', '/shared/{str:token}', [NoteShareController::class, 'view'], [], 'note_shared_view')
            ->add('GET', '/shared/{str:token}/attachment/{str:fileUid}', [NoteAttachmentController::class, 'sharedDownload'], [], 'note_shared_attachment')
            ->endGroup();
    }
}
