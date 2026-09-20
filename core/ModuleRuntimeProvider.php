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
     * Export concrete services for every capability declared by this isolated
     * module's manifest. Consumers resolve them through ModuleCapabilityRegistry
     * and therefore do not depend on the provider module's concrete class/path.
     *
     * @return array<string,object>
     */
    public function capabilities(): array;

    /** Register only routes owned by this module. */
    public function registerRoutes(Router $router): void;
}
