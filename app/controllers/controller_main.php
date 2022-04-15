<?php
    class Controller_Main extends Controller
    {
        function action_index()
        {	
            $this->view->render_template('main_view.php', 'template_view.php');
        }
    }