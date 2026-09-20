<?php

declare(strict_types=1);

namespace Modules\Messenger;

use App\Controllers\MessagerController;
use App\Controllers\MessengerGroupController;
use App\Controllers\MessengerVoiceController;
use App\Controllers\MessengerWorkspaceController;
use App\Middlewares\EnforceMessengerUploadPolicy;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireMessengerUse;
use App\Middlewares\UploadRateLimit;
use Core\ModuleRuntimeProvider;
use Core\Router;

final class MessengerRuntimeProvider implements ModuleRuntimeProvider
{
    private MessengerCapability $capability;

    public function __construct()
    {
        $this->capability = new MessengerCapability();
    }

    public function moduleId(): string
    {
        return 'messenger';
    }

    public function boot(): void
    {
        // Module-owned HTTP and WebSocket classes are loaded explicitly by runtime.php.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.messenger' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/messenger')
            ->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class, RequireMessengerUse::class], 'messenger')
            ->add('POST', '/socket-ticket', [MessagerController::class, 'socketTicket'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_socket_ticket')
            ->add('POST', '/upload', [MessagerController::class, 'uploadFile'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_upload')
            ->add('POST', '/voice-upload', [MessengerVoiceController::class, 'upload'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_voice_upload')
            ->add('POST', '/workspace/note', [MessengerWorkspaceController::class, 'createNote'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_workspace_note')
            ->add('POST', '/workspace/task', [MessengerWorkspaceController::class, 'createTask'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_workspace_task')
            ->add('GET', '/media/{str:uid}', [MessagerController::class, 'media'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_media')
            ->add('GET', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'avatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar')
            ->add('POST', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'uploadAvatar'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class], 'messenger_group_avatar_upload')
            ->add('POST', '/group-avatar/{str:uid}/delete', [MessengerGroupController::class, 'removeAvatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar_delete')
            ->endGroup();
    }
}
