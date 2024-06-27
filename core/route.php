<?php
class Route {
	private array $routes = [];
	
	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		$path = preg_replace('#[/]{2,}#', '/', $path);
		return $path;
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

	private function clearParams(array $mached): array {
		$result = [];
		foreach ($mached as $key => $param) {
			if (!is_int($key)) {
				$result[$key] = $param;
			}
		}
		return $result;
	}

	public function dispatch() {
    $request_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $request_url = $this->normalizePath($request_url);

    $request_method = strtoupper($_SERVER['REQUEST_METHOD']);

    foreach ($this->routes as $route) {
        $path_pattern = $this->create_pattern($route['path']);

        if (
            !preg_match($path_pattern, $request_url, $params) ||
            $route['method'] !== $request_method
        ) continue;

        [$class, $function] = $route['controller'];

        // Инициализация контроллера и запроса
        $controllerInstance = new $class;
        $request = new \Core\Request(); // Предположение, что Request находится в \Core

        $params = $this->clearParams($params);

        // Логика вызова функции контроллера
        if (empty($params)) {
            $controllerInstance->{$function}($request); // Передаем объект Request
        } else {
            $controllerInstance->{$function}($request, ...$params); // Передаем объект Request и параметры
        }
    }
}

}
