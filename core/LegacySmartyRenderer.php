<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Smarty\Smarty;
use Smarty\Template;

/**
 * Temporary 1.0 migration adapter. New views must use NativeViewRenderer; this
 * adapter exists only until the remaining .tpl templates are migrated.
 */
final class LegacySmartyRenderer implements ViewRenderer
{
    private Smarty $smarty;

    public function __construct(
        private ViewContext $context,
        string $templateDir,
        string $configDir,
        string $compileDir,
        string $cacheDir
    ) {
        if (!class_exists(Smarty::class)) {
            throw new RuntimeException('Legacy Smarty renderer requested but Smarty is not installed.');
        }

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($templateDir);
        $this->smarty->setConfigDir($configDir);
        $this->smarty->setCompileDir($compileDir);
        $this->smarty->setCacheDir($cacheDir);
        $this->smarty->setEscapeHtml(true);

        $this->smarty->registerPlugin('function', 'route_path', [$this->context, 'routePlugin']);
        $this->smarty->registerPlugin('function', 'csrf_token', [$this->context, 'csrfPlugin']);
        $this->smarty->registerPlugin('function', 'session', [$this->context, 'sessionPlugin']);
        $this->smarty->registerPlugin('function', 'jsonParse', [$this, 'jsonParse']);
        $this->smarty->registerPlugin('function', 'file_get_contents', [$this, 'fileGetContents']);

        $this->smarty->registerPlugin('modifier', 'strpos', 'strpos');
        $this->smarty->registerPlugin('modifier', 'round', 'round');
        $this->smarty->registerPlugin('modifier', 'count', 'count');
        $this->smarty->registerPlugin('modifier', 'in_array', 'in_array');
    }

    public function render(string $template, array $data = []): void
    {
        foreach ($data as $key => $value) {
            $this->smarty->assign((string) $key, $value);
        }

        $this->smarty->display($template . '.tpl');
    }

    /** @param array<string,mixed> $params */
    public function jsonParse(array $params, Template $template): void
    {
        $assign = isset($params['assign']) ? (string) $params['assign'] : '';
        if ($assign === '') {
            return;
        }

        $template->assign($assign, json_decode((string) ($params['json'] ?? ''), true));
    }

    /** @param array<string,mixed> $params */
    public function fileGetContents(array $params, Template $template): string
    {
        $file = isset($params['file']) ? (string) $params['file'] : '';
        if ($file === '') {
            return '';
        }

        return file_get_contents($file) ?: '';
    }
}
