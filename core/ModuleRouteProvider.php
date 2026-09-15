<?php

declare(strict_types=1);

namespace Core;

interface ModuleRouteProvider
{
    public function registerRoutes(Router $router): void;
}
