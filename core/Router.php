<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/RouteTemplate.php';
require_once __DIR__ . '/UserActionLog.php';

use InvalidArgumentException;
use RuntimeException;

class Router
{
    private static ?self $instance = null;

    /** @var array<int, array{path: string, method: string, controller: array{0: class-string, 1: non-empty-string}, middlewares: array<class-string>, name?: string}> */
    protected array $routes = [];

    /** @var array<class-string> */
    private array $globalMiddlewares = [];

    private ?string $groupPrefix = null;

    /** @var array<string,string> нормализованный путь маршрута => скомпилированное регулярное выражение */
    private array $compiledPatterns = [];

    /** @var array<string,int> имя маршрута => индекс в $routes */
    private array $routeNameIndex = [];

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
     * Добавляет middleware, который выполняется для каждого совпавшего маршрута
     * до middleware самого маршрута. Такой guard защищает и будущие маршруты,
     * если сам явно не классифицирует операцию как безопасную или восстановительную.
     *
     * @param class-string $middleware
     */
    public function addGlobalMiddleware(string $middleware): self
    {
        if ($middleware === '') {
            throw new InvalidArgumentException('Класс глобального middleware не может быть пустым');
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

    /** Начинает группу маршрутов с заданным префиксом. */
    public function group(string $prefix): self
    {
        $basePath = $this->getBasePath();
        $currentPrefix = $this->groupPrefix !== null
            ? $this->groupPrefix . $prefix
            : $basePath . $prefix;

        $this->groupPrefix = $this->normalizePath($currentPrefix);
        return $this;
    }

    /** Завершает текущую группу маршрутов. */
    public function endGroup(): self
    {
        $this->groupPrefix = null;
        return $this;
    }

    private function createPattern(string $path): string
    {
        return RouteTemplate::compile($path);
    }

    /**
     * @param array<string,mixed>|null $matched
     * @return array<string,mixed>
     */
    private function clearParams(?array $matched, string $routePath): array
    {
        return RouteTemplate::typedParams($matched, $routePath);
    }

    private function normalizePath(string $path): string
    {
        return RouteTemplate::normalize($path);
    }

    /**
     * @param array{0: class-string, 1: non-empty-string} $controller
     * @param array<class-string> $middlewares
     */
    public function add(string $method, string $path, array $controller, array $middlewares = [], string $name = ''): self
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            throw new InvalidArgumentException("Неподдерживаемый HTTP-метод: {$method}");
        }
        if (!$this->validControllerTuple($controller)) {
            throw new InvalidArgumentException('Контроллер маршрута должен быть задан как [класс, метод]');
        }

        $path = $this->routePath($path);
        $compiledPattern = $this->createPattern($path);

        foreach ($this->routes as $existing) {
            if ($existing['method'] === $method && $existing['path'] === $path) {
                throw new RuntimeException("Повторная регистрация маршрута: {$method} {$path}");
            }
        }
        if ($name !== '' && isset($this->routeNameIndex[$name])) {
            throw new RuntimeException("Повторное имя маршрута: {$name}");
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
        $routeIndex = array_key_last($this->routes);
        $this->compiledPatterns[$path] = $compiledPattern;
        if ($name !== '' && is_int($routeIndex)) {
            $this->routeNameIndex[$name] = $routeIndex;
        }

        return $this;
    }

    public function dispatch(): void
    {
        [$requestUrl, $requestMethod] = $this->requestTarget();
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $params = $this->matchRoute($route, $requestUrl);
            if ($params === null) {
                continue;
            }

            $allowedMethods[$route['method']] = true;
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $this->dispatchMatchedRoute($route, $params, $requestMethod);
            return;
        }

        if ($allowedMethods !== []) {
            $methods = array_keys($allowedMethods);
            sort($methods, SORT_STRING);
            $this->handleMethodNotAllowed($methods);
        }

        $this->handle404();
    }

    /** @return array{0:string,1:string} */
    private function requestTarget(): array
    {
        $rawRequestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if (preg_match('/[\x00-\x1F\x7F]/', $rawRequestUri) === 1) {
            $this->handleBadRequest('invalid_request_path');
        }

        $parsedPath = parse_url($rawRequestUri, PHP_URL_PATH);
        if (!is_string($parsedPath)) {
            $this->handleBadRequest('invalid_request_path');
        }

        try {
            $requestUrl = $this->normalizePath($parsedPath);
        } catch (InvalidArgumentException) {
            $this->handleBadRequest('invalid_request_path');
        }

        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return [$requestUrl, $requestMethod];
    }

    /**
     * @param array{path:string,method:string,controller:array{0:class-string,1:non-empty-string},middlewares:array<class-string>,name?:string} $route
     * @return array<string,mixed>|null
     */
    private function matchRoute(array $route, string $requestUrl): ?array
    {
        $pathPattern = $this->compiledPatterns[$route['path']] ?? $this->createPattern($route['path']);
        $params = [];
        if (preg_match($pathPattern, $requestUrl, $params) !== 1) {
            return null;
        }

        return $params;
    }

    /**
     * @param array{path:string,method:string,controller:array{0:class-string,1:non-empty-string},middlewares:array<class-string>,name?:string} $route
     * @param array<string,mixed> $params
     */
    private function dispatchMatchedRoute(array $route, array $params, string $requestMethod): void
    {
        $request = new Request();
        if ($request->hasInvalidJson()) {
            $this->handleBadRequest($request->jsonError() ?? 'invalid_json');
        }

        if (!$this->executeMiddlewares($this->globalMiddlewares, $request)) {
            return;
        }
        if (!$this->executeMiddlewares($route['middlewares'], $request)) {
            return;
        }

        [$controllerInstance, $methodName] = $this->controllerFor($route['controller']);
        $typedParams = $this->clearParams($params, $route['path']);

        UserActionLog::registerHttpMutation(
            (int) $request->session('user_id', 0),
            $requestMethod,
            (string) ($route['name'] ?? ''),
            $route['path']
        );

        $this->invokeController($controllerInstance, $methodName, $request, $typedParams);
    }

    /**
     * @param array{0:class-string,1:non-empty-string} $controller
     * @return array{0:object,1:non-empty-string}
     */
    private function controllerFor(array $controller): array
    {
        [$className, $methodName] = $controller;
        if (!class_exists($className)) {
            throw new RuntimeException("Класс контроллера не существует: {$className}");
        }

        $controllerInstance = new $className();
        if (!method_exists($controllerInstance, $methodName)) {
            throw new RuntimeException("Метод {$methodName} отсутствует в контроллере {$className}");
        }

        return [$controllerInstance, $methodName];
    }

    /** @param array<mixed> $controller */
    private function validControllerTuple(array $controller): bool
    {
        return count($controller) === 2
            && is_string($controller[0] ?? null)
            && trim((string) ($controller[0] ?? '')) !== ''
            && is_string($controller[1] ?? null)
            && trim((string) ($controller[1] ?? '')) !== '';
    }

    private function routePath(string $path): string
    {
        if ($this->groupPrefix !== null) {
            return $this->normalizePath($this->groupPrefix . $path);
        }

        return $this->normalizePath($this->getBasePath() . $path);
    }

    private function handle404(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo '404 Page Not Found';
        exit;
    }

    private function handleBadRequest(string $code): never
    {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'error' => 'invalid_request',
            'code' => $code,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param list<string> $methods */
    private function handleMethodNotAllowed(array $methods): never
    {
        http_response_code(405);
        if ($methods !== []) {
            header('Allow: ' . implode(', ', $methods));
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo '405 Method Not Allowed';
        exit;
    }

    /** @param array<string,mixed> $params */
    private function invokeController(object $controllerInstance, string $methodName, Request $request, array $params): void
    {
        if ($params === []) {
            $controllerInstance->$methodName($request);
            return;
        }

        $controllerInstance->$methodName($request, ...array_values($params));
    }

    /** @param array<class-string> $middlewares */
    private function executeMiddlewares(array $middlewares, Request $request): bool
    {
        foreach ($middlewares as $middleware) {
            if (!class_exists($middleware)) {
                throw new RuntimeException("Класс middleware не существует: {$middleware}");
            }

            $middlewareInstance = new $middleware();
            if (!method_exists($middlewareInstance, 'handle')) {
                throw new RuntimeException("Middleware {$middleware} не содержит метод handle");
            }
            if (!$middlewareInstance->handle($request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Перенаправляет на именованный маршрут или локальный URL того же источника.
     * Произвольные внешние перенаправления этим API намеренно не поддерживаются.
     *
     * @param array<string,mixed> $params
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

            throw new RuntimeException("Маршрут для перенаправления не найден: {$to}");
        }

        throw new InvalidArgumentException("Некорректный тип перенаправления: {$type}");
    }

    /** @param array<string,mixed> $params */
    private function buildUrlFromRoute(string $path, array $params): string
    {
        return RouteTemplate::bind($path, $params);
    }

    /**
     * @return array{path:string,method:string,controller:array{0:class-string,1:non-empty-string},middlewares:array<class-string>,name?:string}|null
     */
    private function findRouteByName(string $name): ?array
    {
        $index = $this->routeNameIndex[$name] ?? null;
        return is_int($index) ? ($this->routes[$index] ?? null) : null;
    }

    public function getRoute(string $name): string
    {
        $route = $this->findRouteByName($name);
        return $route['path'] ?? '';
    }

    /**
     * @return array<int,array{path:string,method:string,controller:array{0:class-string,1:non-empty-string},middlewares:array<class-string>,name?:string}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
