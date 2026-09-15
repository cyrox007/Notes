<?php

declare(strict_types=1);

namespace Core;

use Closure;
use RuntimeException;

final class HybridViewRenderer implements ViewRenderer
{
    private ?ViewRenderer $legacy = null;

    /** @param Closure():ViewRenderer $legacyFactory */
    public function __construct(
        private NativeViewRenderer $native,
        private Closure $legacyFactory
    ) {
    }

    public function render(string $template, array $data = []): void
    {
        if ($this->native->hasTemplate($template)) {
            $this->native->render($template, $data);
            return;
        }

        $this->legacy()->render($template, $data);
    }

    public function usesNative(string $template): bool
    {
        return $this->native->hasTemplate($template);
    }

    private function legacy(): ViewRenderer
    {
        if ($this->legacy !== null) {
            return $this->legacy;
        }

        $renderer = ($this->legacyFactory)();
        if (!$renderer instanceof ViewRenderer) {
            throw new RuntimeException('Legacy view factory did not return a ViewRenderer.');
        }

        return $this->legacy = $renderer;
    }
}
