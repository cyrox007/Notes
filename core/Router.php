<?php

declare(strict_types=1);

namespace Core;

class Router
{
    private static ?self $instance = null;

    /** @var array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}> */
    protected array $routes = [];

    /** @var array<class-string> */
    private array $globalMiddlewares = [];

    /** @var string|null */
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

    /**
     * Register middleware that runs for every matched route before route-specific
     * middleware. A global guard therefore also protects future routes unless
     * the guard itself explicitly classifies an operation as safe/recovery-only.
     *
     * @param class-string $middleware
     */
    public function addGlobalMiddleware(string $middleware): self
    {
        if ($middleware === '') {
            throw new \InvalidArgumentException('Global middleware class cannot be empty');
        }
        if (!in_array($middleware, $this->globalMiddlewares, true)) {
            $this->globalMiddlewares[] = $middleware;
        }
        return $this;
    }

    private function getBasePath(): string
    {
        $basePath = getenv('BASE_PATH');
        return $basePath !== false ? $basePath : '/';
    }

    /**
     * Start a route group with a given prefix.
     * Returns $this to allow method chaining.
     *
     * Usage:
     * $router->group('/admin')
     *       ->get('/', [AdminController::class, 'index'])
     *       ->post('/save', [AdminController::class, 'save']);
     */
    public function group(string $prefix): self
    {
        $basePath = $this->getBasePath();
        $currentPrefix = $this->groupPrefix !== null
            ? $this->groupPrefix . $prefix
            : $basePath . $prefix;

        $this->groupPrefix = $this->normalizePath($currentPrefix);

        return $this;
    }

    /**
     * End the current group scope.
     */
    public function endGroup(): self
    {
        $this->groupPrefix = null;
        return $this;
    }

    private function createPattern(string $path): string
    {
        $tokens = preg_split(
            '/(\{(?:int|str):[A-Za-z0-9_]+\})/',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($tokens === false) {
            throw new \RuntimeException('Unable to compile route pattern');
        }

        $pattern = '';
        foreach ($tokens as $token) {
            if (preg_match('/^\{(int|str):([A-Za-z0-9_]+)\}$/', $token, $matches) === 1) {
                $valuePattern = $matches[1] === 'int' ? '\\d+' : '[A-Za-z0-9_-]+';
                $pattern .= '(?P<' . $matches[2] . '>' . $valuePattern . ')';
                continue;
            }

            $pattern .= preg_quote($token, '~');
        }

        return '~^' . $pattern . '$~D';
    }

    /**
     * @param array<string, mixed> $matched
     * @return array<string, mixed>
     */
    private function clearParams(array|null $matched): array
    {
        if ($matched === null) {
            return [];
        }

        $filtered = array_filter(
            $matched,
            static fn ($key): bool => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($filtered as $key => $value) {
            if (is_string($value) && ctype_digit($value)) {
                $filtered[$key] = (int) $value;
            }
        }

        return $filtered;
    }

    private function normalizePath(string $path): string
    {
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
        $basePath = $this->getBasePath();
        if ($this->groupPrefix !== null) {
            $path = $this->normalizePath($this->groupPrefix . $path);
        } else {
            $path = $this->normalizePath($basePath . $path);
        }

        $route = [
            'path' => $path,
            'method' => strtoupper($method),
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
        $rawRequestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (preg_match('/[\r\n\x00]/', $rawRequestUri) === 1) {
            $this->handleBadRequest();
        }

        $parsedPath = parse_url($rawRequestUri, PHP_URL_PATH);
        if (!is_string($parsedPath)) {
            $this->handleBadRequest();
        }

        $requestUrl = $this->normalizePath($parsedPath);
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $routeFound = false;

        foreach ($this->routes as $route) {
            $pathPattern = $this->createPattern($route['path']);

            $params = null;

            if (!$this->isMatchingRoute($pathPattern, $requestUrl, $route['method'], $requestMethod, $params)) {
                continue;
            }

            $routeFound = true;

            $request = new Request();

            if (!$this->executeMiddlewares($this->globalMiddlewares, $request)) {
                return;
            }

            if (!$this->executeMiddlewares($route['middlewares'], $request)) {
                continue;
            }

            [$className, $methodName] = $route['controller'];

            if (!class_exists($className)) {
                throw new \RuntimeException("Controller class does not exist: {$className}");
            }

            $controllerInstance = new $className();

            if (!method_exists($controllerInstance, $methodName)) {
                throw new \RuntimeException("Method {$methodName} does not exist in controller {$className}");
            }

            $params = $this->clearParams($params);

            $this->invokeController($controllerInstance, $methodName, $request, $params);

            return;
        }

        if (!$routeFound) {
            $this->handle404();
        }
    }

    private function handle404(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo '404 Page Not Found';
        exit;
    }

    private function handleBadRequest(): never
    {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo '400 Bad Request';
        exit;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isMatchingRoute(string $pathPattern, string $requestUrl, string $routeMethod, string $requestMethod, array|null &$params): bool
    {
        return preg_match($pathPattern, $requestUrl, $params) === 1 && $routeMethod === $requestMethod;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function invokeController(object $controllerInstance, string $methodName, Request $request, array $params): void
    {
        if ($params === []) {
            $controllerInstance->$methodName($request);
        } else {
            $controllerInstance->$methodName($request, ...array_values($params));
        }
    }

    /**
     * @param array<class-string> $middlewares
     */
    private function executeMiddlewares(array $middlewares, Request $request): bool
    {
        foreach ($middlewares as $middleware) {
            if (!class_exists($middleware)) {
                throw new \RuntimeException("Middleware class does not exist: {$middleware}");
            }

            $middlewareInstance = new $middleware();

            if (!method_exists($middlewareInstance, 'handle')) {
                throw new \RuntimeException("Middleware {$middleware} does not have a handle method");
            }

            if (!$middlewareInstance->handle($request)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Redirects to a named route or to a same-origin local URL.
     * Arbitrary external redirects are intentionally not supported by this core API.
     *
     * @param array<string, mixed> $params
     */
    public function redirect(string $to, string $type = 'name', array $params = []): void
    {
        if ($type === 'url') {
            $siteUrl = (string) Config::get('SITEURL', '');
            $location = RedirectPolicy::resolveLocal($to, $siteUrl);
            header("Location: {$location}", true, 302);
            exit;
        }

        if ($type === 'name') {
            $route = $this->findRouteByName($to);
            if ($route !== null) {
                $url = $this->buildUrlFromRoute($route['path'], $params);
                header("Location: {$url}", true, 302);
                exit;
            }

            throw new \RuntimeException("Route for redirect not found: {$to}");
        }

        throw new \InvalidArgumentException("Invalid type provided for redirect: {$type}");
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildUrlFromRoute(string $path, array $params): string
    {
        foreach ($params as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
                throw new \InvalidArgumentException('Invalid route parameter name');
            }

            $pattern = '/\{(int|str):' . preg_quote($key, '/') . '\}/';
            if (preg_match($pattern, $path, $matches) !== 1) {
                throw new \RuntimeException("Parameter {$key} not found in route path");
            }

            $type = $matches[1];
            if ($type === 'int') {
                if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) {
                    throw new \InvalidArgumentException("Route parameter {$key} must be an integer");
                }
                $replacement = (string) $value;
            } else {
                if (!(is_string($value) || is_int($value))) {
                    throw new \InvalidArgumentException("Route parameter {$key} must be a scalar string identifier");
                }
                $replacement = (string) $value;
                if (preg_match('/^[A-Za-z0-9_-]+$/', $replacement) !== 1) {
                    throw new \InvalidArgumentException("Route parameter {$key} contains invalid characters");
                }
            }

            $path = (string) preg_replace($pattern, $replacement, $path, 1);
        }

        if (preg_match('/\{(?:int|str):[A-Za-z0-9_]+\}/', $path) === 1) {
            throw new \RuntimeException('Missing parameter for named route redirect');
        }

        return $this->normalizePath($path);
    }

    /**
     * @return array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}|null
     */
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

    /**
     * @return array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}|null
     */
    private function findRouteByPath(string $path): ?array
    {
        foreach ($this->routes as $route) {
            if (isset($route['path']) && $route['path'] === $path) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Get all registered routes
     *
     * @return array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
