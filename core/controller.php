<?php
namespace Core;

use Smarty\Smarty;

class Controller {

    public $model;
    public $view;
    public $config;
    public $images;

    protected $smarty;
    
    function __construct() {
        
    }
    
    function action_index() {
        // Do something
    }

    function render_template(string $template, ?array $data = null) {
        $this->smarty = new Smarty();
        
        $this->smarty->setTemplateDir(SITEPATH . "/app/views");
        $this->smarty->setConfigDir(SITEPATH . "/config");
        $this->smarty->setCompileDir(SITEPATH . '/compile');
        $this->smarty->setCacheDir(SITEPATH . '/cache');

        $this->smarty->setEscapeHtml(true);

        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $this->smarty->assign($key, $value);
            }
        }
        
        $this->smarty->display("{$template}.tpl");
    }

    function response_json(array $data): void {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}