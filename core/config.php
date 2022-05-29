<?php
class Config {

    public $hash_key = "592e6419d1d04634848f40f22f9f71a7450800611f4e497cdd71b7cef3e3450ae63fd149609d36eb"; //SSL Key Code
    public $hash_method = "AES-192-CBC"; 
    // вынести в отдельный класс хелпер
    public function base_url() {
        return ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/';
    }

}