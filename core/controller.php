<?php

declare(strict_types=1);

namespace Core;

use Smarty\Smarty;
use App\Middlewares\CSRFMiddleware;

class Controller
{
    protected Smarty $smarty;
    protected Request $request;

    public function __construct()
    {
        ob_start();
        $this->request = new Request();

        $this->smarty = new Smarty();
        
        $this->smarty->setTemplateDir(SITEPATH . '/app/views');
        $this->smarty->setConfigDir(SITEPATH . '/config');
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

    public function __destruct()
    {
        $output = ob_get_clean();
        if ($output !== '' && $output !== false) {
            if (is_string($output)) {
                echo $output;
            } elseif (is_array($output) || is_object($output)) {
                $this->responseJson((array) $output);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getRoutePath(array $params): string
    {
        $routeManager = Router::getInstance();
        
        if (!isset($params['name'])) {
            return '';
        }

        $route = $routeManager->getRoute($params['name']);
        if ($route === '') {
            return '';
        }

        foreach ($params as $key => $value) {
            if ($key !== 'name') {
                $pattern = sprintf('/{%s:%s}/', '[a-zA-Z]+', $key);
                $route = (string) preg_replace($pattern, (string) $value, $route);
            }
        }

        return $route;
    }

    public function getCSRFInputTag(): string
    {
        return Helper::getCSRFInputTag();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function getSession(array $params): ?string
    {
        return $this->request->session($params['key']) ?? null;
    }

    /**
     * @param array<string, mixed> $params
     * @param \Smarty\Smarty $smarty
     */
    public function jsonParse(array $params, Smarty &$smarty): void
    {
        $smarty->assign($params['assign'], json_decode($params['json'], true));
    }

    /**
     * @param array<string, mixed> $params
     * @param \Smarty\Smarty $smarty
     */
    public function smarty_function_file_get_contents(array $params, Smarty &$smarty): string
    {
        return file_get_contents($params['file']) ?: '';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render_template(string $template, ?array $data = null): void
    {
        $siteUrl = rtrim(getenv('SITEURL'), '/');
        $basePath = ltrim(getenv('BASE_PATH'), '/');
        
        $baseUrl = $siteUrl . '/' . $basePath;
        
        $this->smarty->assign('base_url', $baseUrl);

        if ($data !== null) {
            $data = $this->convertObjectsToArray($data);
            foreach ($data as $varKey => $varValue) {
                if (is_array($varValue)) {
                    $data[$varKey] = $this->convertObjectsToArray($varValue);
                }
                $this->smarty->assign($varKey, $varValue);
            }
        }

        $this->smarty->display("{$template}.tpl");
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function convertObjectsToArray(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertObjectsToArray($value);
            }
        }
        
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function responseJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
