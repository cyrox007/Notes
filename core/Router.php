<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use RuntimeException;

class Router
{
    private static ?self $instance = null;

    /** @var array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}> */
    protected array $routes = [];

    private ?string $groupPrefix = null;

    private function __construct() {}

    private function __clone() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function getBasePath(): string
    {
        $basePath = getenv('BASE_PATH');
        return $basePath !== false ? $basePath : '/';
    }

    public function group(string $prefix): self
    {
        $basePath = $this->getBasePath();
        $currentPrefix = $this->groupPrefix !== null
            ? $this->groupPrefix . $prefix
            : $basePath . $prefix;

        $this->groupPrefix = $this->normalizePath($currentPrefix);
        return $this;
    }

    public function endGroup(): self
    {
        $this->groupPrefix = null;
        return $this;
    }

    private function createPattern(string $path): string
    {
        $placeholderPattern = '/\{(int|str):([A-Za-z][A-Za-z0-9_]*)\}/';
        preg_match_all($placeholderPattern, $path, $matches, PREG_OFFSET_CAPTURE);

        $compiled = '';
        $cursor = 0;
        $names = [];
        $count = count($matches[0] ?? []);

        for ($index = 0; $index < $count; $index++) {
            [$placeholder, $offset] = $matches[0][$index];
            $type = (string) $matches[1][$index][0];
            $name = (string) $matches[2][$index][0];

            if (isset($names[$name])) {
                throw new RuntimeException("Duplicate route parameter: {$name}");
            }
            $names[$name] = true;

            $compiled .= preg_quote(substr($path, $cursor, $offset - $cursor), '~');
            $valuePattern = $type === 'int' ? '\\d+' : '[A-Za-z0-9_-]+';
            $compiled .= '(?P<' . $name . '>' . $valuePattern . ')';
            $cursor = $offset + strlen($placeholder);
        }

        $compiled .= preg_quote(substr($path, $cursor), '~');
        return '~^' . $compiled . '$~D';
    }

    /**
     * @param array<string, mixed>|null $matched
     * @return array<string, mixed>
     */
    private function clearParams(?array $matched, string $routePath): array
    {
        if ($matched === null) {
            return [];
        }

        $types = [];
        if (preg_match_all('/\{(int|str):([A-Za-z][A-Za-z0-9_]*)\}/', $routePath, $definitions, PREG_SET_ORDER)) {
            foreach ($definitions as $definition) {
                $types[(string) $definition[2]] = (string) $definition[1];
            }
        }

        $filtered = array_filter(
            $matched,
            static fn ($key): bool => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($filtered as $key => $value) {
            if (($types[$key] ?? null) === 'int') {
                $filtered[$key] = (int) $value;
            }
        }

        return $filtered;
    }

    private function normalizePath(string $path): string
    {
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Route path contains control characters');
        }

        $path = trim($path, '/');
        $path = "/{$path}/";
        return (string) preg_replace('#/{2,}#', '/', $path);
    }

    /**
     * @param array{0: class-string, 1: non-empty-string} $controller
     * @param array<class-string> $middlewares
     */
    public function add(string $method, string $path, array $controller, array $middlewares = [], string $name = ''): self
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            throw new InvalidArgumentException("Unsupported HTTP method: {$method}");
        }

        if (count($controller) !== 2 || !is_string($controller[0] ?? null) || !is_string($controller[1] ?? null) || $controller[1] === '') {
            throw new InvalidArgumentException('Route controller must be [class, method]');
        }

        $basePath = $this->getBasePath();
        $path = $this->groupPrefix !== null
            ? $this->normalizePath($this->groupPrefix . $path)
            : $this->normalizePath($basePath . $path);

        // Compile immediately so invalid/duplicate placeholders fail during bootstrap,
        // not only when the first request happens to hit the route.
        $this->createPattern($path);

        foreach ($this->routes as $existing) {
            if ($existing['method'] === $method && $existing['path'] === $path) {
                throw new RuntimeException("Duplicate route registration: {$method} {$path}");
            }
            if ($name !== '' && ($existing['name'] ?? '') === $name) {
                throw new RuntimeException("Duplicate route name: {$name}");
            }
        }

        $route = [
            'path' => $path,
            'method' => $method,
            'controller' => $controller,
            'middlewares' => $middlewares,
        ];

        if ($name !== '') {
            $route['name'] = $name;
        }

        $this->routes[] = $route;
        return $this;
    }

    public function dispatch(): void
    {
        $rawPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!is_string($rawPath)) {
            $this->handle400('invalid_request_path');
            return;
        }

        $requestUrl = $this->normalizePath($rawPath);
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $pathPattern = $this->createPattern($route['path']);
            $params = [];
            if (preg_match($pathPattern, $requestUrl, $params) !== 1) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $request = new Request();
            if ($request->hasInvalidJson()) {
                $this->handle400($request->jsonError() ?? 'invalid_json');
                return;
            }

            if (!$this->executeMiddlewares($route['middlewares'], $request)) {
                // A matched route was explicitly denied. Never continue scanning for
                // another route that might have weaker middleware.
                return;
            }

            [$className, $methodName] = $route['controller'];
            if (!class_exists($className)) {
                throw new RuntimeException("Controller class does not exist: {$className}");
            }

            $controllerInstance = new $className();
            if (!method_exists($controllerInstance, $methodName)) {
                throw new RuntimeException("Method {$methodName} does not exist in controller {$className}");
            }

            $params = $this->clearParams($params, $route['path']);
            $this->invokeController($controllerInstance, $methodName, $request, $params);
            return;
        }

        if ($pathMatched) {
            $this->handle405();
            return;
        }
        $this->handle404();
    }

    private function handle400(string $code): void
    {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'invalid_request', 'code' => $code], JSON_UNESCAPED_SLASHES);
    }

    private function handle404(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Page Not Found';
    }

    private function handle405(): void
    {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo '405 Method Not Allowed';
    }

    /** @param array<string, mixed> $params */
    private function invokeController(object $controllerInstance, string $methodName, Request $request, array $params): void
    {
        if ($params === []) {
            $controllerInstance->$methodName($request);
        } else {
            $controllerInstance->$methodName($request, ...array_values($params));
        }
    }

    /** @param array<class-string> $middlewares */
    private function executeMiddlewares(array $middlewares, Request $request): bool
    {
        foreach ($middlewares as $middleware) {
            if (!class_exists($middleware)) {
                throw new RuntimeException("Middleware class does not exist: {$middleware}");
            }

            $middlewareInstance = new $middleware();
            if (!method_exists($middlewareInstance, 'handle')) {
                throw new RuntimeException("Middleware {$middleware} does not have a handle method");
            }

            if (!$middlewareInstance->handle($request)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $params */
    public function redirect(string $to, string $type = 'name', array $params = []): void
    {
        if ($type === 'url' && filter_var($to, FILTER_VALIDATE_URL) !== false) {
            header("Location: {$to}");
            exit;
        }

        if ($type === 'name') {
            $route = $this->findRouteByName($to);
            if ($route !== null) {
                $url = $this->buildUrlFromRoute($route['path'], $params);
                header("Location: {$url}");
                exit;
            }
            throw new RuntimeException("Route for redirect not found: {$to}");
        }

        throw new InvalidArgumentException("Invalid type provided for redirect: {$type}");
    }

    /** @param array<string, mixed> $params */
    private function buildUrlFromRoute(string $path, array $params): string
    {
        preg_match_all('/\{(int|str):([A-Za-z][A-Za-z0-9_]*)\}/', $path, $matches, PREG_SET_ORDER);
        $expected = [];

        foreach ($matches as $match) {
            $type = (string) $match[1];
            $key = (string) $match[2];
            $expected[$key] = true;

            if (!array_key_exists($key, $params)) {
                throw new RuntimeException("Missing route parameter: {$key}");
            }

            $rawValue = $params[$key];
            if (!is_scalar($rawValue)) {
                throw new InvalidArgumentException("Route parameter {$key} must be scalar");
            }
            $value = (string) $rawValue;

            if ($type === 'int') {
                if ($value === '' || !ctype_digit($value)) {
                    throw new InvalidArgumentException("Route parameter {$key} must be an integer");
                }
                $replacement = $value;
            } else {
                if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
                    throw new InvalidArgumentException("Route parameter {$key} contains invalid characters");
                }
                $replacement = rawurlencode($value);
            }

            $path = str_replace((string) $match[0], $replacement, $path);
        }

        foreach (array_keys($params) as $key) {
            if (!isset($expected[(string) $key])) {
                throw new InvalidArgumentException("Unexpected route parameter: {$key}");
            }
        }

        if (preg_match('/\{(?:int|str):[^}]+\}/', $path) === 1) {
            throw new RuntimeException('Unresolved route parameter remains in URL');
        }

        return $this->normalizePath($path);
    }

    /** @return array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}|null */
    private function findRouteByName(string $name): ?array
    {
        foreach ($this->routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                return $route;
            }
        }
        return null;
    }

    public function getRoute(string $name): string
    {
        $route = $this->findRouteByName($name);
        return $route['path'] ?? '';
    }

    /** @return array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}> */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
