<?php
namespace Core;

use Route;
use Smarty\Smarty;

use App\Middlewares\CSRFMiddleware;

class Controller {

    protected $smarty;
    protected $request;

    public function __construct() {
        ob_start();
        $this->request = new Request();

        $this->smarty = new Smarty();
        
        $this->smarty->setTemplateDir(SITEPATH . "/app/views");
        $this->smarty->setConfigDir(SITEPATH . "/config");
        $this->smarty->setCompileDir(SITEPATH . '/compile');
        $this->smarty->setCacheDir(SITEPATH . '/cache');

        $this->smarty->setEscapeHtml(true);
        
        // Регистрация пользовательской функции для получения маршрута
        $this->smarty->registerPlugin('function', 'route_path', [$this, 'getRoutePath']);
        $this->smarty->registerPlugin('function', 'csrf_token', [$this, 'getCSRFInputTag']);
        $this->smarty->registerPlugin('function', 'session', [$this, 'getSession']);
        $this->smarty->registerPlugin('function', 'jsonParse', [$this, 'jsonParse']);

        $this->smarty->registerPlugin('function', 'file_get_contents', [$this, 'smarty_function_file_get_contents']);

        // Добавляем CSRF проверку для всех POST-запросов
        $csrfMiddleware = new CSRFMiddleware();
        $csrfMiddleware->handle();
    }

    public function __destruct() {
        $output = ob_get_clean();
        if (!empty($output)) {
            if (is_string($output)) {
                echo $output;
            } elseif (is_array($output) || is_object($output)) {
                $this->response_json((array) $output);
            }
        }
    }

    public function getRoutePath($params) {
        $routeManager = Route::getInstance(); // Используем синглтон
        if (!isset($params['name'])) {
            return '';
        }

        $route = $routeManager->getRoute($params['name']);
        if (!$route) {
            return '';
        }

        foreach ($params as $key => $value) {
            if ($key !== 'name') {
                $pattern = sprintf("/{%s:%s}/", '[a-zA-Z]+', $key);
                $route = preg_replace($pattern, $value, $route);
            }
        }

        return $route;
    }


    public function getCSRFInputTag(): string {
        return Helper::getCSRFInputTag();
    }

    public function getSession($params): ?string {
        return $this->request->session($params['key']) ?? null;
    }

    public function jsonParse($params, &$smarty) {
        $smarty->assign($params['assign'], json_decode($params['json'], true));
    }

    public function smarty_function_file_get_contents($params, &$smarty) {
        return file_get_contents($params['file']);
    }

    protected function render_template(string $template, ?array $data = null) {
        $site_url = rtrim(getenv('SITEURL'), '/');  // Получение значения переменной окружения SITEURL и удаление лишних слешей с конца URL
        $base_path = ltrim(getenv('BASE_PATH'), '/');  // Получение значения переменной окружения BASE_PATH и удаление лишних слешей с начала пути
        
        $base_url = $site_url . '/' . $base_path;  // Объединение SITEURL и BASE_PATH 
        
        $this->smarty->assign('base_url', $base_url);

        if (!empty($data)) {
            $data = $this->convertObjectsToArray($data);
            foreach ($data as $varKey => $varValue) {
                //$varValue = $this->convertObjectsToArray($varValue);
                if (is_array($varValue)) {
                    $data[$varKey] = $this->convertObjectsToArray($varValue);
                }
                $this->smarty->assign($varKey, $varValue);
            }
        }

        $this->smarty->display("{$template}.tpl");
    }

    private function convertObjectsToArray($data) {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertObjectsToArray($value);
            }
        }
        //var_dump($data);
        return $data;
    } 

    function response_json(array $data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
