<?php

declare(strict_types=1);

namespace Core;

interface ViewRenderer
{
    /**
     * Render a logical template name without a file extension.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): void;
}
