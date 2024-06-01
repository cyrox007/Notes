<?php
namespace Core;

class Images {
    var $image; // само изображение
    var $image_type; // тип изображения

    // проверка и сохранение
    public function checkAvatar_save($file, $filename) {
        $save_dir = 'app/uploads/us_avatars/';
        $avatar_file = $save_dir . $filename;
        $this->load($file);
        //$avatar_info = getimagesize($file);
        $avatar_w = 150; // ширина аватара 
        $avatar_h = 150; // высота аватара
        
        if ($avatar_h != $this->getHeight() || $avatar_w != $this->getWidth()) {
            $this->resize($avatar_w, $avatar_h);
        }
        $this->save($avatar_file);
        return $avatar_file;
    }
    
    // возвращаем ширину загруженного изображения
    public function getWidth() {
        return imagesx($this->image);
    }

    // возвращаем высоту загруженного изображения
    public function getHeight() {
        return imagesy($this->image);
    }
    
    // Изменение высоты. Получает новый размер.
    public function resizeToHeight($height) {
        $ratio = $height / $this->getHeight();
        $width = $this->getWidth() * $ratio;
        $this->resize($width,$height);
    }
    
    // Изменение ширины. Получает новый размер.
    public function resizeToWidth($width) {
        $ratio = $width / $this->getWidth();
        $height = $this->getheight() * $ratio;
        $this->resize($width,$height);
    }

    // масштабирование изображения. Принимает процент от 100
    public function scale($scale) {
        $width = $this->getWidth() * $scale / 100;
        $height = $this->getheight() * $scale / 100;
        $this->resize($width, $height);
    }

    // функция ресайза изображения принимает ширину и высоту
    public function resize($width, $height) {
        $new_image = imagecreatetruecolor($width, $height);
        imagecopyresampled($new_image, $this->image, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
        $this->image = $new_image;
    }

    // загружаем изображение, принимает изображение с которым будем работать
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
    
    // сохраняем изображение. Принимает путь и имя сохранения, тип файла, степень сжатия, значение доступа к файлу
    public function save($filename, $image_type=IMAGETYPE_JPEG, $compression=75, $permissions=null) {
        if( $image_type == IMAGETYPE_JPEG ) {
            imagejpeg($this->image, $filename, $compression);
        } else if( $image_type == IMAGETYPE_GIF ) {
            imagegif($this->image, $filename);
        } elseif( $image_type == IMAGETYPE_PNG ) {
            imagepng($this->image, $filename);
        }

        if( $permissions != null) {
            chmod($filename, $permissions);
        }
    }
}