<?php
    class Controller_Main extends Controller
    {
        function action_index() {
            if ($_SESSION['key'] == null)
                header("Location: /login");

            $note_directory = "c855721/";
            $dtime = date('Ymd_His');
            
            if(isset($_POST['name'])) {
                $filename = $dtime.'_'.$_POST['name'].".txt";
                $file = fopen($note_directory.$filename, "w");
                fclose($file);
            
                $location = 'edit/'.$filename;
                header('Location: '.$location);
            }
            $files = array_diff(scandir($note_directory), ['.', '..', '.htaccess']);
            $files = array_reverse($files);

            $data = [
                'title' => 'Главная',
                'files' => $files
            ];

            $this->view->render_template('main_view.php', 'template_view.php', $data);
        }

        function action_login() {
            $default_key = "f6f4061a1bddc1c04d8109b39f581270"; // test0

            if (isset($_POST['key'])) {
                if (md5($_POST['key']) === $default_key) {
                    $_SESSION['key'] = 'auth';
                    header("Location: /");
                }
            }

            $data = [
                'title' => 'Авторизация'
            ];
            $this->view->render_template('login_view.php', 'template_view.php', $data);
        }

        function action_edit() {
            if ($_SESSION['key'] == null)
                header("Location: /login");

            $note_directory = "c855721/";
            $uri = explode('/', $_SERVER['REQUEST_URI']);
            $fname = mb_substr(urldecode($uri[2]), 16, -4);
            $filepath = $note_directory . urldecode($uri[2]);
            $text = file_get_contents($filepath);;
            
            if (isset($_POST['textarea'])) {
                $text = $_POST['textarea'];
                file_put_contents($filepath, $text);
                header('Location: /');
            }

            $data = [
                "title" => $fname,
                "text" => $text,
            ];
            $this->view->render_template('edit_view.php', 'template_view.php', $data);
        }

        function action_delete() {
            if ($_SESSION['key'] == null)
                header("Location: /login");

            $note_directory = "c855721/";
            $uri = explode('/', $_SERVER['REQUEST_URI']);
            if ($url[2] != " ") {
                $filepath = $note_directory . urldecode($uri[2]);
                unlink($filepath);
            }
            header('Location: /');
        }

        function action_404() {
            $this->view->render_template('404_view.php', 'template_view.php');
        }
    }