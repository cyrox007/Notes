<?php
namespace App\Controller;

use Core\Controller;

    class Error extends Controller {
        public function __construct() {
            $this->config = new Config();
            $this->view = new View();
        }
        public function action_404() {
            $data = [
                'style' =>  $this->config->base_url().'templates/css/style.css',
                'script' => $this->config->base_url().'templates/js/script.js',
                'svg' => $this->config->base_url().'templates/img/Frame.svg',
                'site' => $this->config->site,
                'title' => 'Page Not Found!'
            ];
            $this->view->render_template('error_page/404_view.php', 'error_page/error_wrapper.php', $data);
        }
        public function action_invate_error() {
            $data = [
                'style' =>  $this->config->base_url().'templates/css/style.css',
                'script' => $this->config->base_url().'templates/js/script.js',
                'svg' => $this->config->base_url().'templates/img/Frame.svg',
                'site' => $this->config->site,
                'title' => 'Ошибка! Приглашение не действительно'
            ];
            $this->view->render_template('error_page/invate_error_view.php', 'error_page/error_wrapper.php', $data);
        }
        public function action_noteError() {
            $data = [
                'style' =>  $this->config->base_url().'templates/css/style.css',
                'script' => $this->config->base_url().'templates/js/script.js',
                'svg' => $this->config->base_url().'templates/img/Frame.svg',
                'site' => $this->config->site,
                'title' => 'Ошибка! Такой записи не существует'
            ];
            $this->view->render_template('error_page/404_view.php', 'error_page/error_wrapper.php', $data);
        }

        public function action_accessDenied() {
            $data = [
                'style' =>  $this->config->base_url().'templates/css/style.css',
                'script' => $this->config->base_url().'templates/js/script.js',
                'svg' => $this->config->base_url().'templates/img/Frame.svg',
                'site' => $this->config->site,
                'title' => 'Ошибка! Доступ к странице запрещен'
            ];
            $this->view->render_template('error_page/accessDenied_view.php', 'error_page/error_wrapper.php', $data);
        }
    }