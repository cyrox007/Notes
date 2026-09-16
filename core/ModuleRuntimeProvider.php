<?php

declare(strict_types=1);

namespace Core;

interface ModuleRuntimeProvider
{
    public function moduleId(): string;

    /**
     * Load/register module-owned runtime services that do not mutate the router.
     * This runs only for modules selected by the effective runtime composition.
     */
    public function boot(): void;

    /** Register only routes owned by this module. */
    public function registerRoutes(Router $router): void;
}
