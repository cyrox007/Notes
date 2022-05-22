<?php
    class Controller_Error extends Controller {
        public function action_404() {
            $data['title'] = "Page Not Found!";
            $this->view->render_template('404_view.php', 'template_view.php', $data);
        }
    }