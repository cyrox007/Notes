<?php

declare(strict_types=1);

namespace Core;

final class ViewContext
{
    public function __construct(private Request $request)
    {
    }

    /**
     * Smarty-compatible route helper input: ['name' => 'route', ...params].
     *
     * @param array<string,mixed> $params
     */
    public function routePath(array $params): string
    {
        $name = isset($params['name']) ? (string) $params['name'] : '';
        if ($name === '') {
            return '';
        }

        $route = Router::getInstance()->getRoute($name);
        if ($route === '') {
            return '';
        }

        foreach ($params as $key => $value) {
            if ($key === 'name') {
                continue;
            }

            $key = (string) $key;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $pattern = '/\{[A-Za-z]+:' . preg_quote($key, '/') . '\}/';
            $route = (string) preg_replace($pattern, (string) $value, $route);
        }

        return $route;
    }

    /** @param array<string,mixed> $params */
    public function routePlugin(array $params, mixed $template = null): string
    {
        return $this->routePath($params);
    }

    /** @param array<string,mixed> $params */
    public function csrfPlugin(array $params = [], mixed $template = null): string
    {
        return Helper::getCSRFInputTag();
    }

    /** @param array<string,mixed> $params */
    public function sessionPlugin(array $params, mixed $template = null): ?string
    {
        $key = isset($params['key']) ? (string) $params['key'] : '';
        if ($key === '') {
            return null;
        }

        $value = $this->request->session($key);
        return $value === null ? null : (string) $value;
    }

    public function route(string $name, array $params = []): string
    {
        return $this->routePath(['name' => $name] + $params);
    }

    public function csrfInput(): string
    {
        return Helper::getCSRFInputTag();
    }

    public function session(string $key, mixed $default = null): mixed
    {
        return $this->request->session($key, $default);
    }
}
