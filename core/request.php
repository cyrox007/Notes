<?php
namespace Core;

class Request {
    private $get;
    private $post;
    private $files;
    private $server;
    private $json;
    private $session;

    public function __construct() {
        // Заполнение свойств данными из суперглобальных массивов
        $this->initialize();
        //$this->logRequestData();
        $this->parseJson();
    }

    private function initialize() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->get = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;
        $this->server = $_SERVER;
        $this->session = &$_SESSION;
    }

    public function session($key = null, $default = null) {
        return $key === null ? $this->session : ($this->session[$key] ?? $default);
    }

    public function setSession($key, $value) {
        $_SESSION[$key] = $value;
        $this->session[$key] = $value; // Обновление локальной переменной
        error_log("Set session: [$key] => " . print_r($value, true));
    }

    public function unsetSession($key) {
        unset($_SESSION[$key]);
        unset($this->session[$key]); // Обновление локальной переменной
    }

    private function parseJson() {
        $input = file_get_contents('php://input');
        $this->json = $this->sanitize(json_decode($input, true));
    }

    private function sanitize($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize($value);
            }
            return $data;
        }
    
        return is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : $data;
    }
    
    public function get($key = null, $default = null) {
        $data = $key === null ? $this->get : ($this->get[$key] ?? $default);
        return $this->sanitize($data);
    }
    
    public function post($key = null, $default = null) {
        $data = $key === null ? $this->post : ($this->post[$key] ?? $default);
        return $this->sanitize($data);
    }

    public function files($key = null, $default = null) {
        $data = $key === null? $this->files : ($this->files[$key]?? $default);
        return $data;
    }

    /**
     * Проверить наличие загруженного файла
     */
    public function hasFile(string $key): bool {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * Получить данные о загруженном файле
     */
    public function file(string $key, $default = null) {
        return $this->hasFile($key) ? $this->files[$key] : $default;
    }
    
    public function json($key = null, $default = null) {
        $data = $key === null ? $this->json : ($this->json[$key] ?? $default);
        return $this->sanitize($data);
    }
    
    public function server($key = null, $default = null) {
        $data = $key === null ? $this->server : ($this->server[$key] ?? $default);
        return $this->sanitize($data);
    }

    public function all() {
        return [
            'get' => $this->get,
            'post' => $this->post,
            'files' => $this->files,
            'json' => $this->json,
            'server' => $this->server,
        ];
    }

    private function logRequestData() {
        // Логирование данных для отладки
        error_log('$_GET: ' . print_r($this->get, true));
        error_log('$_POST: ' . print_r($this->post, true));
        error_log('$_SERVER: ' . print_r($this->server, true));
    }
}