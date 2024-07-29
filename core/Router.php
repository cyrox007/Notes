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

	private function getBasePath(): string {
		$basePath = getenv('BASE_PATH');
		return $basePath !== false ? $basePath : '/';
	}

	public function group(string $prefix, callable $callback): void {
		$basePath = $this->getBasePath();
		$currentPrefix = $basePath . $prefix;

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
		return "~^" . preg_replace_callback($pattern, function($matches) {
			$type = strpos($matches[0], 'int:') !== false ? '\d+' : '[\w-]+';
			return "(?P<{$matches[1]}>{$type})";
		}, $path) . "$~";
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

	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		return preg_replace('#[/]{2,}#', '/', $path);
	}

	public function add(string $method, string $path, array $controller, array $middlewares = [], string $name = ''): void {
		$basePath = $this->getBasePath();
		$path = $this->normalizePath($basePath . $path);
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

	public function dispatch(): void {
		$requestUrl = $this->normalizePath(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
		$requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);
		$routeFound = false; // Flag to check if any route matches

		foreach ($this->routes as $route) {
			$pathPattern = $this->create_pattern($route['path']);

			if (!$this->isMatchingRoute($pathPattern, $requestUrl, $route['method'], $requestMethod, $params)) {
				continue;
			}

			$routeFound = true; // route is found

			$request = new \Core\Request();

			if (!$this->executeMiddlewares($route['middlewares'], $request)) {
				continue;
			}

			[$class, $function] = $route['controller'];
			$controllerInstance = new $class();

			$params = $this->clearParams($params);

			$this->invokeController($controllerInstance, $function, $request, $params);
			
			return; // Exit once the correct route is found and handled
		}

		if (!$routeFound) {
			$this->handle404();
		}
	}

	private function handle404(): void {
		header("HTTP/1.0 404 Not Found");
		echo '404 Page Not Found';
		exit; // Ensure no further code is executed
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

	public function redirect(string $to, string $type = 'name', array $params = []): void {
		if ($type === 'url' && filter_var($to, FILTER_VALIDATE_URL)) {
			header("Location: $to");
			exit();
		} elseif ($type === 'name') {
			$route = $this->findRouteByName($to);
			if ($route) {
				$url = $this->buildUrlFromRoute($route['path'], $params);
				header("Location: $url");
				exit();
			} else {
				throw new \Exception("Route for redirect not found: $to");
			}
		} else {
			throw new \Exception("Invalid type provided for redirect: $type");
		}
	}
	
	private function buildUrlFromRoute(string $path, array $params): string {
		foreach ($params as $key => $value) {
			// Define the pattern to match {type:paramName}
			$pattern = '/\{(int|str):' . preg_quote($key, '/') . '\}/';
			if (preg_match($pattern, $path)) {
				// Replace the matched pattern with the value
				$path = preg_replace($pattern, $value, $path, 1);
			} else {
				throw new \Exception("Parameter {$key} not found in route path");
			}
		}
		return $this->normalizePath($path);
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
