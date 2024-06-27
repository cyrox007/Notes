<?php
namespace Core;

class Request {
    private $get;
    private $post;
    private $server;
    private $json;

    public function __construct() {
        // Заполнение свойств данными из суперглобальных массивов
        $this->initialize();
        $this->logRequestData();
        $this->parseJson();
    }

    private function initialize() {
        $this->get = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
    }

    private function parseJson() {
        $input = file_get_contents('php://input');
        $this->json = json_decode($input, true);
    }
    
    public function get($key = null, $default = null) {
        return $key === null ? $this->get : ($this->get[$key] ?? $default);
    }

    public function post($key = null, $default = null) {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    public function json($key = null, $default = null) {
        return $key === null ? $this->json : ($this->json[$key] ?? $default);
    }

    public function server($key = null, $default = null) {
        return $key === null ? $this->server : ($this->server[$key] ?? $default);
    }

    public function all() {
        return [
            'get' => $this->get,
            'post' => $this->post,
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