<?php
    class Controller_Notes extends Controller {
        public function __construct() {
            $this->config = new Config();
            $this->model = new Model_Notes();
            $this->view = new View();
        }
        
        function action_index() {
            if ($_SESSION['auth_login'] == null) // проверим факт авторизованности
                header("Location: /Auth/login");

            $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
            $user_info = $this->model->getUser_data($user); // получаем информацию о нем
            
            if($_SERVER['REQUEST_METHOD'] == 'POST') {
                
                    $dtime = date('Ymd_His'); // текущее дата и время
                    $filename = $dtime.'_'.$_POST['note-name'].".txt"; // формируем имя заметки 
                    $filepath = $this->config->dir_notes.$filename; // формируем путь к заметке
                    $file = fopen($filepath, "w"); // создаем файл 
                    fclose($file); // закрываем файл
                    
                    $new_note = $this->model->createNewNote($_POST['note-name'], $filepath, $user, $user_info['id']);
                    
                    $location = 'Notes/edit/'.$new_note;
                    header('Location: ' .$location);
            }

            $allNotes = $this->model->getAllNotes();

            function isAdmin($user_role, $admin) {
                if ($user_role > $admin)
                    return false;
                
                return true;
            }

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
                'title' => 'Блокнот',
                'notes' => $allNotes,
                'user' => $user,
                'user_id' => $user_info['id'],
                'admin' => isAdmin($user_info['role'], $this->config->user_role_admin),
                'username' => $user_info['first_name']. " " .$user_info['surname'],
                'userphoto' => $this->config->base_url().$user_info['user_photo']
            ];

            $this->view->render_template('notes_page/main_view.php', 'core/template_view.php', $data);
        }

        function action_edit() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

            $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
            $user_info = $this->model->getUser_data($user); // получаем информацию о нем

            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $note_id = $uri[3]; // вытаскиваем id записи из запроса
            $note_info = $this->model->getNote_data($note_id);
            
            if (!$note_info) 
                header("Location: /Error/noteError");
            
            function isAdmin($user_role) {
                if ($user_role > $this->config->user_role_admin)
                    return false;
                
                return true;
            }
            
            if ($note_info['user_id'] != $user_info['id']){
                if (!isAdmin($user_info['role']))
                    header('Location: /Error/noteError');
            }
            
            /* получаем файл и содержимое */
            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $param = $uri[3];
            
            $file_data = file_get_contents($note_info['notefile_link']); // получаем содержимое файла

            /* расшифровываем содежимое и выводим в поле ввода */
            $decode_data_base64 = base64_decode($file_data); // декодируем содержмое файла из base64

            $key = $this->config->hash_key;
            $method = $this->config->hash_method;

            $decrypted = openssl_decrypt($decode_data_base64, $method, $key);
            
            /* получаем содержимое поля ввода и зашифровываем обратно */
            if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                $textarea = $_POST['content']; // получаем содержимое поля ввода

                $key = $this->config->hash_key; // ключь
                $method = $this->config->hash_method; // метод
                
                $encrypted = openssl_encrypt($textarea, $method, $key); // хешируем
                $raw = base64_encode($encrypted); // теперь в base64

                file_put_contents($note_info['notefile_link'], $raw); // пишем в файл
                $this->model->update_note($note_id, date("Y-m-d H:i:s"));
                header('Location: /');
            }
            
            $data = [
                'styles' => [
                    $this->config->base_url().'templates/style/'.'plugins/fontawesome-free/css/all.min.css',
                    $this->config->base_url().'templates/style/'.'dist/css/adminlte.min.css',
                ],
                'scripts' => [
                    $this->config->base_url().'templates/script/'.'plugins/jquery/jquery.min.js',
                    $this->config->base_url().'templates/script/'.'plugins/bootstrap/js/bootstrap.bundle.min.js',
                    $this->config->base_url().'templates/script/'.'dist/js/adminlte.min.js',
                    $this->config->base_url().'templates/script/'.'/dist/js/demo.js',
                ],
                'page_style' => [
                    $this->config->base_url().'templates/resource/'.'summernote/summernote-bs4.css'
                ],
                'page_script' => [
                    $this->config->base_url().'templates/resource/'.'summernote/summernote-bs4.min.js'
                ],
                'call_script' => [
                    "$(function () {
                        // Summernote
                        $('.textarea').summernote()
                      })"
                ],
                'tpl_images' => [
                    'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
                ],
                'title' => 'Блокнот: Редактируем > '.$note_info['name_note'],
                'name_note' => $note_info['name_note'],
                'user' => $user,
                'username' => $user_info['first_name']. " " .$user_info['surname'],
                'userphoto' => $this->config->base_url().$user_info['user_photo'],
                'content' => $decrypted
            ];
            
            $this->view->render_template('notes_page/edit_view.php', 'core/template_view.php', $data);
        }

        function action_delete() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

            $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
            $user_info = $this->model->getUser_data($user); // получаем информацию о нем

            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $note_id = $uri[3]; // вытаскиваем id записи из запроса
            $note_info = $this->model->getNote_data($note_id);
            
            if (!$note_info) 
                header("Location: /Error/noteError");
            
            function isAdmin($user_role) {
                if ($user_role > $this->config->user_role_admin)
                    return false;
                
                return true;
            }
            
            if ($note_info['user_id'] != $user_info['id']){
                if (!isAdmin($user_info['role']))
                    header('Location: /Error/noteError');
            }

            unlink($note_info['notefile_link']);
            $this->model->deleteNote($note_id);
            header('Location: /Notes');
        }
    }