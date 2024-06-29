<?php
namespace App\Controller;

use App\Models\NoteModel;
use App\Models\UserModel;
use Core\Controller;
use Core\Request;

    class NoteController extends Controller {
        public function index(Request $request) {
            $userModel = new UserModel();
            $user = $userModel->select('users')->where('uid', '=', $request->session('user_uid'))->first();

            $noteModel = new NoteModel();
            $userNotes = $noteModel->select('notes')->where('user_id', '=', $user['id'])->get();
            $allNotes = $noteModel->select('notes')->get();
            $data = [
                'personalNotes' => $userNotes,
                'allNotes' => $allNotes,
                'user' => $user
            ]; 

            $this->render_template('notes_page/index', $data);
        }

        function action_edit() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

            $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
            $user_info = $this->model->getUser_data($user); // получаем информацию о нем

            if ($user_info['role'] >= $this->config->user_role_inactive) {
                header('Location: /Error/accessDenied');
            }

            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $note_id = $uri[3]; // вытаскиваем id записи из запроса
            $note_info = $this->model->getNote_data($note_id);
            
            if (!$note_info) 
                header("Location: /Error/noteError");
            
            function isAdmin($user, $admin) {
                if ($user > $admin)
                    return false;
                
                return true;
            }
            
            if ($note_info['user_id'] != $user_info['id']){
                if (!isAdmin($user_info['role'], $this->config->user_role_admin))
                    header('Location: /Error/noteError');
            }
            
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
                header('Location: /Notes');
            }
            
            $data = [
                'styles' => [
                    'main-style' => $this->config->base_url().'templates/css/style.css',
                    'font-awesome' => $this->config->base_url().'templates/img/icons/css/font-awesome.css',
                ],
                'script' => $this->config->base_url().'templates/js/script.js',
                
                'tpl_images' => [
                    'logo' => $this->config->base_url().'templates/img/AdminLTELogo.png'
                ],
                'site' => $this->config->site,
                'title' => 'Блокнот: Редактируем > '.$note_info['name_note'],
                
                'user' => $user,
                'user-name' => $user_info['first_name'],
                'user-surname' => $user_info['surname'],
                'user-photo' => $this->config->base_url().$user_info['user_photo'],
                
                'note-name' => $note_info['name_note'],
                'note-author' => $note_info['author'],
                'note-create' => $note_info['date_create'],
                'note-edit' => $note_info['date_edit'],
                'note-content' => $decrypted,
            ];
            
            $this->view->render_template('notes_page/edit_view.php', 'core/template_view.php', $data);
        }

        function action_delete() {
            if ($_SESSION['auth_login'] == null)
                header("Location: /Auth/login");

            $user = $_SESSION['auth_login']; // пользователя авторизованного в сессии
            $user_info = $this->model->getUser_data($user); // получаем информацию о нем

            if ($user_info['role'] >= $this->config->user_role_inactive) {
                header('Location: /Error/accessDenied');
            }

            $uri = explode('/', $_SERVER['REQUEST_URI']); // получаем запрос к файлу
            $note_id = $uri[3]; // вытаскиваем id записи из запроса
            $note_info = $this->model->getNote_data($note_id);
            
            if (!$note_info) 
                header("Location: /Error/noteError");
            
            function isAdmin($user, $admin) {
                if ($user > $admin)
                    return false;
                
                return true;
            }
            
            if ($note_info['user_id'] != $user_info['id']){
                if (!isAdmin($user_info['role'], $this->config->user_role_admin))
                    header('Location: /Error/noteError');
            }

            unlink($note_info['notefile_link']);
            $this->model->deleteNote($note_id);
            header('Location: /Notes');
        }
    }