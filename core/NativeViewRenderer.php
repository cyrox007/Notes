<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Stringable;
use Throwable;

final class NativeViewRenderer implements ViewRenderer
{
    private string $viewRoot;

    /** @var array<string,string> */
    private array $moduleViewRoots = [];

    /** @var list<array<string,mixed>> */
    private array $renderDataStack = [];

    /**
     * @param array<string,string> $moduleViewRoots Active isolated module id => views directory.
     */
    public function __construct(string $viewRoot, private ViewContext $context, array $moduleViewRoots = [])
    {
        $resolved = realpath($viewRoot);
        if ($resolved === false || !is_dir($resolved) || is_link($viewRoot)) {
            throw new RuntimeException('Native view root does not exist or is unsafe: ' . $viewRoot);
        }

        $this->viewRoot = rtrim($resolved, DIRECTORY_SEPARATOR);

        foreach ($moduleViewRoots as $moduleId => $moduleRoot) {
            if (!is_string($moduleId) || preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $moduleId) !== 1) {
                throw new RuntimeException('Invalid native module view root id.');
            }
            if (!is_string($moduleRoot) || $moduleRoot === '' || is_link($moduleRoot)) {
                throw new RuntimeException("Invalid native view root for module {$moduleId}.");
            }

            $resolvedModuleRoot = realpath($moduleRoot);
            if ($resolvedModuleRoot === false || !is_dir($resolvedModuleRoot)) {
                throw new RuntimeException("Native view root does not exist for module {$moduleId}.");
            }
            $this->moduleViewRoots[$moduleId] = rtrim($resolvedModuleRoot, DIRECTORY_SEPARATOR);
        }
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
     *
     * Layouts inherit the active page/partial render context. Explicit layout
     * values override inherited values and trusted content always wins the
     * reserved `content` key. This mirrors the variable visibility expected by
     * the old layout contract without requiring every page to manually forward
     * common runtime values such as licenseRuntime/base_url/workspaceAccess.
     * User-controlled values must still be escaped in the layout.
     *
     * @param array<string,mixed> $data
     */
    public function layout(string $template, array $data, string $content): string
    {
        $inherited = $this->renderDataStack !== []
            ? $this->renderDataStack[array_key_last($this->renderDataStack)]
            : [];
        $layoutData = array_merge($inherited, $data);
        $layoutData['content'] = $content;

        return $this->capture($template, $layoutData);
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
        $this->renderDataStack[] = $data;

        ob_start();
        try {
            extract($data, EXTR_SKIP);
            require $file;
            $output = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        } finally {
            array_pop($this->renderDataStack);
        }

        if ($output === false) {
            throw new RuntimeException('Unable to render native template: ' . $template);
        }

        return $output;
    }

    private function resolve(string $template): string
    {
        if ($template === '' || str_contains($template, '..') || str_contains($template, '\\')) {
            throw new RuntimeException('Invalid native template name.');
        }

        $root = $this->viewRoot;
        $relativeTemplate = $template;
        if (str_starts_with($template, '@')) {
            if (preg_match('/^@([a-z][a-z0-9_.-]{1,63})\/([A-Za-z0-9_\/.^-]+)$/D', $template, $matches) !== 1) {
                throw new RuntimeException('Invalid native module template name.');
            }

            $moduleId = $matches[1];
            $relativeTemplate = $matches[2];
            if (!isset($this->moduleViewRoots[$moduleId])) {
                throw new RuntimeException("Native view module is not active or has no view root: {$moduleId}");
            }
            $root = $this->moduleViewRoots[$moduleId];
        } elseif (preg_match('/^[A-Za-z0-9_\/.^-]+$/D', $template) !== 1) {
            throw new RuntimeException('Invalid native template name.');
        }

        $candidate = $root . DIRECTORY_SEPARATOR . $relativeTemplate . '.php';
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || is_link($candidate)) {
            throw new RuntimeException('Native template not found: ' . $template);
        }

        $prefix = $root . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            throw new RuntimeException('Native template escaped the configured view root.');
        }

        return $resolved;
    }
}
