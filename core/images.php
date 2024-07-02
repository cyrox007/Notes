<?php
namespace Core;

class Images {
	public $image; // само изображение
	public $image_type; // тип изображения

	// Constructor to initialize image and type if required
	public function __construct($image = null, $image_type = null) {
		$this->image = $image;
		$this->image_type = $image_type;
	}

	// Универсальная функция загрузки изображения
	public static function loadImage($file) {
		// Create an instance to utilize instance methods
		$handler = new self();

		// Load the image file
		$handler->load($file);

		return $handler;
	}

	// Универсальная функция изменения размеров и других обработок
	public function processImage($width, $height) {
		if ($height != $this->getHeight() || $width != $this->getWidth()) {
			$this->resize($width, $height);
		}

		return $this; // Возвращаем объект для работы с ним дальше
	}

	// Функция загрузки изображения
	private function load($filename) {
		$image_info = getimagesize($filename); // Получаем информацию о изображении
		if ($image_info === false) {
			throw new \Exception('Failed to get image size information.');
		}
	
		$this->image_type = $image_info[2]; // Получаем информацию о типе файла
	
		switch ($this->image_type) {
			case IMAGETYPE_JPEG:
				$this->image = imagecreatefromjpeg($filename);
				break;
			case IMAGETYPE_GIF:
				$this->image = imagecreatefromgif($filename);
				break;
			case IMAGETYPE_PNG:
				$this->image = imagecreatefrompng($filename);
				break;
			case IMAGETYPE_WEBP:
				$this->image = imagecreatefromwebp($filename);
				break;
			default:
				throw new \Exception('Unsupported image type.');
		}
		
		if (!$this->image) {
			throw new \Exception('Failed to load image.');
		}
	}

	// Универсальная функция сохранения изображения
	public function saveImage($filename, $image_type = IMAGETYPE_JPEG, $compression = 75, $permissions = null) {
		if ($image_type == IMAGETYPE_JPEG) {
			imagejpeg($this->image, $filename, $compression);
		} elseif ($image_type == IMAGETYPE_GIF) {
			imagegif($this->image, $filename);
		} elseif ($image_type == IMAGETYPE_PNG) {
			imagepng($this->image, $filename);
		} elseif ($image_type == IMAGETYPE_WEBP) {
			imagewebp($this->image, $filename, $compression);
		} elseif ($image_type == 'svg') {
			file_put_contents($filename, $this->imageToSvg());
		}

		if ($permissions !== null) {
			chmod($filename, $permissions);
		}
	}

	private function imageToSvg() {
		ob_start();
		// Convert image to SVG using a placeholder mechanism or an actual conversion tool
		echo '<svg width="100" height="100"><!-- Your SVG content here --></svg>';
		$svg_content = ob_get_clean();
		return $svg_content;
	}

	// возвращаем ширину загруженного изображения
	private function getWidth() {
		return imagesx($this->image);
	}

	// возвращаем высоту загруженного изображения
	private function getHeight() {
		return imagesy($this->image);
	}

	private function resize($width, $height) {
		$new_image = imagecreatetruecolor($width, $height);
		imagecopyresampled($new_image, $this->image, 0, 0, 0, 0, $width, $height, $this->getWidth(), $this->getHeight());
		$this->image = $new_image;
	}
}