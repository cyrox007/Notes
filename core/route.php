<?php
class Route {
	private array $routes = [];

	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		$path = preg_replace('#[/]{2,}#', '/', $path);
		return $path;
	}

	public function add(string $method, string $path, array $controller) {
		$path = $this->normalizePath($path);
		$this->routes[] = [
			'path' => $path,
			'method' => strtoupper($method),
			'controller' => $controller,
			'middlewares' => []
		];
	}

	

	public function dispatch() {
		$request_url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$request_url = $this->normalizePath($request_url);
		$request_method = strtoupper($_SERVER['REQUEST_METHOD']);
		
		foreach ($this->routes as $route) {
			$rp = $route['path'];

			$rp = preg_replace('/\{.+?\}/m', "/(?<$1>[^/]+)", $rp);

			if (
				!preg_match('#^'.$rp.'/?$#', $request_url, $params) ||
				$route['method'] !== $request_method
			) continue;

			[$class, $function] = $route['controller'];

			$controllerInstance = new $class;
			
			if (empty($params)) $controllerInstance->{$function}();
			else $controllerInstance->{$function}($params);
		}
	}
}
