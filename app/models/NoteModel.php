<?php
namespace App\Models;

use Core\Model;
use Core\Config;
use Core\ORM;

    class NoteModel extends ORM {
        protected $_tablename = "notes";

        public $id;
        public string $uid;
        public string $created_note;
        public string $updated_note;
        public string $notename;
        public string $content;
        public int $user_id;
        
        public ?UserNModel $author = null;
        public ?UserNModel $editor = null;

        public function __construct() {
            $this->author = new UserNModel();
            $this->editor = new UserNModel();
        }

    }