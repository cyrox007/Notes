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

    /**
     * Export concrete services for every capability declared by the isolated
     * module manifest. Keys are capability identifiers; values are service
     * objects consumed through ModuleCapabilityRegistry rather than module paths.
     *
     * @return array<string,object>
     */
    public function capabilities(): array;

    /** Register only routes owned by this module. */
    public function registerRoutes(Router $router): void;
}
