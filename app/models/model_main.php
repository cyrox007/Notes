<?php
    class Model_Main extends Model {
        public function __construct() {
            $db = new SQLite3("core/table.db");
            
            $db->close();
        }
    }