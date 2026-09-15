<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Stringable;
use Throwable;

final class NativeViewRenderer implements ViewRenderer
{
    private string $viewRoot;

    public function __construct(string $viewRoot, private ViewContext $context)
    {
        $resolved = realpath($viewRoot);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('Native view root does not exist: ' . $viewRoot);
        }

        $this->viewRoot = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function hasTemplate(string $template): bool
    {
        try {
            $this->resolve($template);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function render(string $template, array $data = []): void
    {
        echo $this->capture($template, $data);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /**
     * Render an internal layout around already-rendered trusted template content.
     * User-controlled values in $data must still be escaped in the layout.
     *
     * @param array<string,mixed> $data
     */
    public function layout(string $template, array $data, string $content): string
    {
        $data['content'] = $content;
        return $this->capture($template, $data);
    }

    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_scalar($value) && !$value instanceof Stringable) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function route(string $name, array $params = []): string
    {
        return $this->context->route($name, $params);
    }

    public function csrfInput(): string
    {
        return $this->context->csrfInput();
    }

    public function session(string $key, mixed $default = null): mixed
    {
        return $this->context->session($key, $default);
    }

    /** @param array<string,mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = $this->resolve($template);
        $view = $this;

        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $file;
            $output = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        if ($output === false) {
            throw new RuntimeException('Unable to render native template: ' . $template);
        }

        return $output;
    }

    private function resolve(string $template): string
    {
        if (
            $template === ''
            || str_contains($template, '..')
            || str_contains($template, '\\')
            || preg_match('/^[A-Za-z0-9_\/.^-]+$/D', $template) !== 1
        ) {
            throw new RuntimeException('Invalid native template name.');
        }

        $candidate = $this->viewRoot . DIRECTORY_SEPARATOR . $template . '.php';
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            throw new RuntimeException('Native template not found: ' . $template);
        }

        $prefix = $this->viewRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            throw new RuntimeException('Native template escaped the configured view root.');
        }

        return $resolved;
    }
}
