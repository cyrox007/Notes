<?php
class Route {
	private static $instance;
    protected $routes = [];

    private function __construct() {}
    private function __clone() {}

	public static function getInstance(): Route {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
	
	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		return preg_replace('#[/]{2,}#', '/', $path);
	}

	public function add(string $method, string $path, array $controller, array $middlewares = [], string $name = ''): void {
		$path = $this->normalizePath($path);
		$route = [
			'path' => $path,
			'method' => strtoupper($method),
			'controller' => $controller,
			'middlewares' => $middlewares
		];

		if (!empty($name)) {
			$route['name'] = $name;
		}

		$this->routes[] = $route;
	}

	public function group(string $prefix, callable $callback): void {
		$currentPrefix = $prefix;

		// Создаем временную функцию для сохранения префикса
		$addWithPrefix = function(string $method, string $path, array $handler, array $middlewares = [], string $name = '') use ($currentPrefix) {
			$fullPath = $this->normalizePath($currentPrefix . $path);
			$this->add($method, $fullPath, $handler, $middlewares, $name);
		};

		// Вызываем колбэк, передавая временную функцию как аргумент
		$callback($addWithPrefix);
	}

	private function create_pattern(string $path): string {
		$pattern = '/{(?:int:|str:)([a-zA-Z0-9_]+)}/';
		return "~^" . preg_replace($pattern, "(?P<$1>\w+)", $path) . "$~";
	}

	private function clearParams(array $matched): array {
		$result = [];
		foreach ($matched as $key => $param) {
			if (!is_int($key)) {
				$result[$key] = $param;
			}
		}
		return $result;
	}

	public function dispatch(): void {
		$requestUrl = $this->normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
		$requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);

		foreach ($this->routes as $route) {
			$pathPattern = $this->create_pattern($route['path']);

			if (!$this->isMatchingRoute($pathPattern, $requestUrl, $route['method'], $requestMethod, $params)) {
				continue;
			}

			$request = new \Core\Request();

			if (!$this->executeMiddlewares($route['middlewares'], $request)) {
				continue;
			}

			[$class, $function] = $route['controller'];
			$controllerInstance = new $class;
			

			$params = $this->clearParams($params);

			$this->invokeController($controllerInstance, $function, $request, $params);
		}
	}

	private function isMatchingRoute(string $pathPattern, string $requestUrl, string $routeMethod, string $requestMethod, &$params): bool {
		return preg_match($pathPattern, $requestUrl, $params) && $routeMethod === $requestMethod;
	}

	private function invokeController(object $controllerInstance, string $function, \Core\Request $request, array $params): void {
		if (empty($params)) {
			$controllerInstance->{$function}($request);
		} else {
			$controllerInstance->{$function}($request, ...$params);
		}
	}

	private function executeMiddlewares(array $middlewares, \Core\Request $request): bool {
		foreach ($middlewares as $middleware) {
			$middlewareInstance = new $middleware();
	
			if (!$middlewareInstance->handle($request)) {
				return false;
			}
		}
		return true;
	}

	public function redirect(string $to, string $type = 'url'): void {
        if ($type === 'url' && filter_var($to, FILTER_VALIDATE_URL)) {
            header("Location: $to");
        } elseif ($type === 'name') {
            $route = $this->findRouteByName($to);
            if ($route) {
                $url = $route['path'];
                header("Location: $url");
            } else {
                throw new \Exception("Route for redirect not found: $to");
            }
        } else {
			throw new \Exception("Invalid type provided for redirect: $type");
		}
        exit();
    }

	private function findRouteByName(string $name): ?array {
        foreach ($this->routes as $route) {
            if (isset($route['name']) && $route['name'] === $name) {
                return $route;
            }
        }
        return null;
    }

	public function getRoute(string $name): string {
		$route = $this->findRouteByName($name);
		return $route['path'] ?? '';
	}

	
	private function findRouteByPath(string $path): ?array {
        foreach ($this->routes as $route) {
            if (isset($route['path']) && $route['path'] === $path) {
                return $route;
            }
        }
        return null;
    }
}
