<?php
namespace Core;

use Smarty\Smarty;

class Controller {

    protected $smarty;

    public function __construct() {
        ob_start();
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
