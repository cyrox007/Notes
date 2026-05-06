<?php

declare(strict_types=1);

namespace Core;

class Router
{
    private static ?self $instance = null;
    
    /** @var array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}> */
    protected array $routes = [];

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

    /**
     * @param callable(self): void $callback
     */
    public function group(string $prefix, callable $callback): void
    {
        $basePath = $this->getBasePath();
        $currentPrefix = $basePath . $prefix;

        $addWithPrefix = function (string $method, string $path, array $handler, array $middlewares = [], string $name = '') use ($currentPrefix): void {
            $fullPath = $this->normalizePath($currentPrefix . $path);
            $this->add($method, $fullPath, $handler, $middlewares, $name);
        };

        $callback($this);
    }

    private function createPattern(string $path): string
    {
        $pattern = '/\{(?:int:|str:)([a-zA-Z0-9_]+)\}/';
        
        $replacedPath = preg_replace_callback($pattern, static function (array $matches): string {
            $type = str_contains($matches[0], 'int:') ? '\d+' : '[\w-]+';
            return "(?P<{$matches[1]}>{$type})";
        }, $path);
        
        return '~^' . $replacedPath . '$~';
    }

    /**
     * @param array<string, mixed> $matched
     * @return array<string, mixed>
     */
    private function clearParams(array $matched): array
    {
        return array_filter(
            $matched,
            static fn ($key): bool => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );
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
    public function add(string $method, string $path, array $controller, array $middlewares = [], string $name = ''): void
    {
        $basePath = $this->getBasePath();
        $path = $this->normalizePath($basePath . $path);
        
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
    }

    public function dispatch(): void
    {
        $requestUrl = $this->normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);
        $routeFound = false;

        foreach ($this->routes as $route) {
            $pathPattern = $this->createPattern($route['path']);

            if (!$this->isMatchingRoute($pathPattern, $requestUrl, $route['method'], $requestMethod, $params)) {
                continue;
            }

            $routeFound = true;

            $request = new Request();

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
        echo '404 Page Not Found';
        exit;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isMatchingRoute(string $pathPattern, string $requestUrl, string $routeMethod, string $requestMethod, array &$params): bool
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
     * @param array<string, mixed> $params
     */
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
            $pattern = '/\{(int|str):' . preg_quote($key, '/') . '\}/';
            
            if (preg_match($pattern, $path) === 1) {
                $path = (string) preg_replace($pattern, (string) $value, $path, 1);
            } else {
                throw new \RuntimeException("Parameter {$key} not found in route path");
            }
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
