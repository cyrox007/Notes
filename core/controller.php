<?php
namespace Core;

class Controller {

    public $model;
    public $view;
    public $config;
    public $images;
    
    function __construct() {
        $this->view = new View();
    }
    
    function action_index() { }
}