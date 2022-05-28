<?php
    class Controller_Error extends Controller {
        public function action_404() {
            $data['title'] = "Page Not Found!";
            $this->view->render_template('error_page/404_view.php', 'core/template_view.php', $data);
        }
    }