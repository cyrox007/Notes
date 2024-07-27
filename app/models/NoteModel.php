<?php
namespace App\Models;

use Core\Model;
use Core\Config;
use Core\ORM;

    class NoteModel extends ORM {
        protected $_tablename = "notes";

        public int $id = 0;
        public string $uid = '';
        public string $created_note = '';
        public string $updated_note = '';
        public string $notename = '';
        public string $content = '';
        public int $user_id = 0;
        
        public ?UserModel $author = null;

        public function __construct() {
            $this->author = new UserModel();
        }

    }