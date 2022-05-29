<?php
    class Controller_Error extends Controller {
        public function action_404() {
            $data['title'] = "Page Not Found!";
            $this->view->render_template('error_page/404_view.php', 'core/template_view.php', $data);
        }
        public function action_invate_error() {
            $data['title'] = "Ошибка! Приглашение не действительно";
            $this->view->render_template('error_page/invate_error_view.php', 'core/template_view.php', $data);
        }
    }