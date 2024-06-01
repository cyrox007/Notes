<?php
class Route {
	/* static function start() {
		// контроллер и действие по умолчанию
		$controller_name = 'Main';
		$action_name = 'index';

		$routes = explode('/', $_SERVER['REQUEST_URI']);
		$request_path = $_SERVER['REQUEST_URI'];

		foreach(RouterConfig::$routes as $k => $route) {
			$routeKey = false;
			if ($route['path'] === $request_path) {
				$routeKey = RouterConfig::$routes[$k];
			}
		}
		
		if ($routeKey) {
			$model_name = 'Model_' . $route['class'];
			$controller_name = 'Controller_' . $route['class'];
			$action_name = 'action_' . $route['action'];

			$model_file = strtolower($model_name) . '.php';
			$model_path = "app/models/" . $model_file;
			if (file_exists($model_path)) {
				include "app/models/" . $model_file;
			}

			$controller_file = strtolower($controller_name) . '.php';
			$controller_path = "app/controllers/" . $controller_file;
			if (file_exists($controller_path)) {
				include "app/controllers/" . $controller_file;
			} else {
				Route::ErrorPage404();
			}

			$controller = new $controller_name;
			$action = $action_name;

			// вызываем действие контроллера
			if (method_exists($controller, $action)) {
				$controller->$action();
			} else {
				Route::ErrorPage404();
			}
			return;
		}

		if (!$routeKey) {
			Route::ErrorPage404();
			return;
		}
	}

	private static function ErrorPage404() {
		$host = 'http://' . $_SERVER['HTTP_HOST'] . '/';
		header('HTTP/1.1 404 Not Found');
		header("Status: 404 Not Found");
		header('Location:' . $host . 'error/404');
	} */

	private array $routes = [];

	public function add(string $method, string $path, array $controller) {
		$path = $this->normalizePath($path);
		$this->routes[] = [
			'path' => $path,
			'method' => strtoupper($method),
			'controller' => $controller,
			'middlewares' => []
		];
	}

	private function normalizePath(string $path): string {
		$path = trim($path, '/');
		$path = "/{$path}/";
		$path = preg_replace('#[/]{2,}#', '/', $path);
		return $path;
	}

	public function dispatch() {
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$path = $this->normalizePath($path);
		$method = strtoupper($_SERVER['REQUEST_METHOD']);
		
		foreach ($this->routes as $route) {
			$rp = $route['path'];
			if (
				!preg_match("#^{$rp}$#", $path) ||
				$route['method'] !== $method
			) continue;

			[$class, $function] = $route['controller'];

			$controllerInstance = new $class;

			$controllerInstance->{$function}();
		}
	}
}
