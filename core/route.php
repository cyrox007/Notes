<?php
class Route {
	private array $routes = [];
	
	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		return preg_replace('#[/]{2,}#', '/', $path);
	}

	public function add(string $method, string $path, array $controller): void {
		$path = $this->normalizePath($path);
		$this->routes[] = [
			'path' => $path,
			'method' => strtoupper($method),
			'controller' => $controller,
			'middlewares' => []
		];
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

			[$class, $function] = $route['controller'];
			$controllerInstance = new $class;
			$request = new \Core\Request();

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


}
