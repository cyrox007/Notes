<?php
class Images {
    var $image; // само изображение
    var $image_type; // тип изображения

    public function load($filename) {
        $image_info = getimagesize($filename); // получаем информацию о изображении
        $this->image_type = $image_info[2]; // получаем информацию о типе файла

        if( $this->image_type == IMAGETYPE_JPEG ) {
            $this->image = imagecreatefromjpeg($filename);
        } else if( $this->image_type == IMAGETYPE_GIF ) {
            $this->image = imagecreatefromgif($filename);
        } else if( $this->image_type == IMAGETYPE_PNG ) {
            $this->image = imagecreatefrompng($filename);
        }
    }
    
    public function save($filename) {
        # code...
    }
}