<?php
declare(strict_types=1);

namespace Modules\Profile;

use App\Controllers\ProfileController;
use App\Controllers\PublicProfileController;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireProfileUse;
use Core\ModuleRuntimeProvider;
use Core\Router;

final class ProfileRuntimeProvider implements ModuleRuntimeProvider
{
    private ProfileCapability $capability;

    public function __construct()
    {
        $this->capability = new ProfileCapability();
    }

    public function moduleId(): string
    {
        return 'profile';
    }

    public function boot(): void
    {
        // Module-owned classes are loaded explicitly by runtime.php.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.profile' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/profile')
            ->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class, RequireProfileUse::class], 'profile')
            ->add('GET', '/user/{str:uid}', [PublicProfileController::class, 'view'], [LoginRequared::class, RequireProfileUse::class], 'profile-public')
            ->add('POST', '/publication', [ProfileController::class, 'setPublication'], [LoginRequared::class, RequireProfileUse::class], 'profile-publication')
            ->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class, RequireProfileUse::class], 'profile-set')
            ->add('GET', '/avatar/{str:uid}', [ProfileController::class, 'avatar'], [LoginRequared::class, RequireProfileUse::class], 'profile-avatar')
            ->add('POST', '/avatar/delete', [ProfileController::class, 'removeAvatar'], [LoginRequared::class, RequireProfileUse::class], 'profile-avatar-delete')
            ->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class, RequireProfileUse::class], 'profile-password-set')
            ->add('POST', '/two-factor/start', [ProfileController::class, 'startTwoFactorSetup'], [LoginRequared::class, RequireProfileUse::class, CSRFMiddleware::class], 'profile-two-factor-start')
            ->add('POST', '/two-factor/confirm', [ProfileController::class, 'confirmTwoFactorSetup'], [LoginRequared::class, RequireProfileUse::class, CSRFMiddleware::class], 'profile-two-factor-confirm')
            ->add('POST', '/two-factor/recovery-codes', [ProfileController::class, 'regenerateTwoFactorRecoveryCodes'], [LoginRequared::class, RequireProfileUse::class, CSRFMiddleware::class], 'profile-two-factor-recovery')
            ->add('POST', '/two-factor/disable', [ProfileController::class, 'disableTwoFactor'], [LoginRequared::class, RequireProfileUse::class, CSRFMiddleware::class], 'profile-two-factor-disable')
            ->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class, RequireProfileUse::class], 'profile-delete')
            ->endGroup();
    }
}
