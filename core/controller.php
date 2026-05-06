<?php

declare(strict_types=1);

namespace Core;

use Smarty\Smarty;
use App\Middlewares\CSRFMiddleware;

/**
 * Базовый класс контроллера
 * 
 * Предоставляет общую функциональность для всех контроллеров:
 * - Инициализация Smarty шаблонизатора
 * - Регистрация пользовательских функций для шаблонов
 * - CSRF защита для всех POST-запросов
 * - Методы рендеринга шаблонов и JSON ответов
 * 
 * @package Core
 */
class Controller
{
    /**
     * @var Smarty Экземпляр шаблонизатора Smarty
     */
    protected Smarty $smarty;
    
    /**
     * @var Request Объект текущего запроса
     */
    protected Request $request;

    /**
     * Конструктор контроллера
     * 
     * Инициализирует:
     * - Буферизацию вывода
     * - Объект запроса
     * - Smarty шаблонизатор с настройками путей
     * - Пользовательские функции для шаблонов
     * - CSRF middleware для защиты POST-запросов
     */
    public function __construct()
    {
        ob_start();
        $this->request = new Request();

        $this->smarty = new Smarty();
        
        // Настройка путей Smarty
        $this->smarty->setTemplateDir(SITEPATH . '/app/views');
        $this->smarty->setConfigDir(SITEPATH . '/config');
        $this->smarty->setCompileDir(SITEPATH . '/compile');
        $this->smarty->setCacheDir(SITEPATH . '/cache');

        // Автоматическое экранирование HTML для безопасности
        $this->smarty->setEscapeHtml(true);
        
        // Регистрация пользовательских функций для шаблонов
        $this->smarty->registerPlugin('function', 'route_path', [$this, 'getRoutePath']);
        $this->smarty->registerPlugin('function', 'csrf_token', [$this, 'getCSRFInputTag']);
        $this->smarty->registerPlugin('function', 'session', [$this, 'getSession']);
        $this->smarty->registerPlugin('function', 'jsonParse', [$this, 'jsonParse']);
        $this->smarty->registerPlugin('function', 'file_get_contents', [$this, 'smarty_function_file_get_contents']);

        // Добавляем CSRF проверку для всех POST-запросов
        $csrfMiddleware = new CSRFMiddleware();
        $csrfMiddleware->handle();
    }

    /**
     * Деструктор контроллера
     * 
     * Обрабатывает буферизированный вывод:
     * - Строки выводятся напрямую
     * - Массивы/объекты конвертируются в JSON ответ
     */
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
     * Генерирует URL для именованного маршрута
     * 
     * Используется в шаблонах как {route_path name='route_name' param1='value1'}
     * 
     * @param array<string, mixed> $params Параметры из шаблона:
     *   - name: имя маршрута (обязательно)
     *   - другие ключи: параметры для подстановки в маршрут
     * @return string Сгенерированный URL или пустая строка если маршрут не найден
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

        // Заменяем параметры вида {type:param} на их значения
        foreach ($params as $key => $value) {
            if ($key !== 'name') {
                $pattern = sprintf('/{%s:%s}/', '[a-zA-Z]+', $key);
                $route = (string) preg_replace($pattern, (string) $value, $route);
            }
        }

        return $route;
    }

    /**
     * Возвращает HTML input поле с CSRF токеном
     * 
     * Используется в шаблонах как {csrf_token}
     * 
     * @return string HTML код скрытого input поля с CSRF токеном
     */
    public function getCSRFInputTag(): string
    {
        return Helper::getCSRFInputTag();
    }

    /**
     * Получает значение из сессии
     * 
     * Используется в шаблонах как {session key='user_uid'}
     * 
     * @param array<string, mixed> $params Параметры из шаблона:
     *   - key: ключ сессионной переменной
     * @return string|null Значение из сессии или null если не найдено
     */
    public function getSession(array $params): ?string
    {
        return $this->request->session($params['key']) ?? null;
    }

    /**
     * Парсит JSON строку и назначает результат в переменную шаблона
     * 
     * Используется в шаблонах как {jsonParse json=$jsonString assign='variable'}
     * 
     * @param array<string, mixed> $params Параметры из шаблона:
     *   - json: JSON строка для парсинга
     *   - assign: имя переменной для назначения результата
     * @param Smarty $smarty Экземпляр Smarty для назначения переменной
     */
    public function jsonParse(array $params, Smarty &$smarty): void
    {
        $smarty->assign($params['assign'], json_decode($params['json'], true));
    }

    /**
     * Читает содержимое файла
     * 
     * Используется в шаблонах как {file_get_contents file='path/to/file'}
     * 
     * @param array<string, mixed> $params Параметры из шаблона:
     *   - file: путь к файлу
     * @param Smarty $smarty Экземпляр Smarty (не используется)
     * @return string Содержимое файла или пустая строка при ошибке
     */
    public function smarty_function_file_get_contents(array $params, Smarty &$smarty): string
    {
        return file_get_contents($params['file']) ?: '';
    }

    /**
     * Рендерит шаблон с данными
     * 
     * @param string $template Имя шаблона без расширения .tpl
     * @param array<string, mixed>|null $data Ассоциативный массив данных для передачи в шаблон
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
     * Рекурсивно конвертирует объекты в массивы
     * 
     * @param mixed $data Данные для конвертации
     * @return mixed Конвертированные данные
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
     * Отправляет JSON ответ
     * 
     * @param array<string, mixed> $data Данные для кодирования в JSON
     */
    protected function responseJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
