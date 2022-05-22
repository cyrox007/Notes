<?php
    class Controller_Main extends Controller {
        public function __construct() {
            $this->model = new Model_Main();
            $this->view = new View();
        }
        
        function action_index() {
            if ($_SESSION['key'] == null)
                header("Location: /Auth/login");

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

        function action_edit() {
            if ($_SESSION['key'] == null)
                header("Location: /Auth/login");
            
            /* получаем файл и содержимое */
            $note_directory = "c855721/"; // папка с файлами заметок
            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $param = $uri[3];
            
            $fname = mb_substr(urldecode($uri[3]), 16, -4); // декодируем и обрежаем название файла для получение его имени
            $filepath = $note_directory . urldecode($uri[3]); // получаем путь к файлу
            $file_data = file_get_contents($filepath); // получаем содержимое файла

            /* расшифровываем содежимое и выводим в поле ввода */
            $decode_data_base64 = base64_decode($file_data); // декодируем содержмое файла из base64

            $key = "592e6419d1d04634848f40f22f9f71a7450800611f4e497cdd71b7cef3e3450ae63fd149609d36eb";
            $method = "AES-192-CBC";

            $decrypted = openssl_decrypt($decode_data_base64, $method, $key);
            
            /* получаем содержимое поля ввода и зашифровываем обратно */
            if (isset($_POST['textarea'])) {
                $textarea = $_POST['textarea'];

                $key = "592e6419d1d04634848f40f22f9f71a7450800611f4e497cdd71b7cef3e3450ae63fd149609d36eb";
                $method = "AES-192-CBC";
                
                $encrypted = openssl_encrypt($textarea, $method, $key);
                $raw = base64_encode($encrypted);

                file_put_contents($filepath, $raw);
                header('Location: /');
            }

            $data = [
                "title" => $fname,
                "text" => $decrypted,
            ];
            $this->view->render_template('edit_view.php', 'template_view.php', $data);
        }

        function action_delete() {
            if ($_SESSION['key'] == null)
                header("Location: /Auth/login");

            $note_directory = "c855721/";
            $uri = explode('/', $_SERVER['REQUEST_URI']);
            if ($url[3] != " ") {
                $filepath = $note_directory . urldecode($uri[3]);
                unlink($filepath);
            }
            header('Location: /');
        }

        function action_404() {
            $this->view->render_template('404_view.php', 'template_view.php');
        }
    }