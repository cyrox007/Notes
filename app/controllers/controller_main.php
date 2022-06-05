<?php
    class Controller_Main extends Controller {
        public function __construct() {
            $this->config = new Config();
            $this->model = new Model_Main();
            $this->view = new View();
        }
        
        function action_index() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");
            
            $user = $_SESSION['auth_login'];
            $user_info = $this->model->getUser_data($user);
            $data = [
                'styles' => [
                    $this->config->base_url().'templates/style/'.'plugins/fontawesome-free/css/all.min.css',
                    $this->config->base_url().'templates/style/'.'dist/css/adminlte.min.css'
                ],
                'scripts' => [
                    $this->config->base_url().'templates/script/'.'plugins/jquery/jquery.min.js',
                    $this->config->base_url().'templates/script/'.'plugins/bootstrap/js/bootstrap.bundle.min.js',
                    $this->config->base_url().'templates/script/'.'dist/js/adminlte.min.js',
                    $this->config->base_url().'templates/script/'.'/dist/js/demo.js'
                ],
                'tpl_images' => [
                    'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
                ],
                'title' => 'Главная',
                'user' => $user,
                'username' => $user_info['first_name']. " " .$user_info['surname'],
                'userphoto' => $user_info['user_photo']
            ];
            

            $this->view->render_template('main_page/main_view.php', 'core/template_view.php', $data);
        }

        function action_edit() {
            if ($_SESSION['auth_login'] == null)
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
            $this->view->render_template('main_page/edit_view.php', 'core/template_view.php', $data);
        }

        function action_delete() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

            $note_directory = "c855721/";
            $uri = explode('/', $_SERVER['REQUEST_URI']);
            if ($url[3] != " ") {
                $filepath = $note_directory . urldecode($uri[3]);
                unlink($filepath);
            }
            header('Location: /');
        }
    }