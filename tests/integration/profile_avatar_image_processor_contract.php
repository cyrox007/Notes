<?php

declare(strict_types=1);

use App\Services\AvatarImageProcessor;

$root = dirname(__DIR__, 2);
require_once $root . '/modules/profile/services/AvatarImageProcessor.php';

function avatarProcessorAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[FAIL] {$message}\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "[FAIL] Расширение GD обязательно для проверки обработки аватара\n");
    exit(1);
}

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'notes-avatar-processor-' . bin2hex(random_bytes(6));
avatarProcessorAssert(mkdir($temp, 0700, true), 'Не удалось создать временный каталог');

$source = $temp . DIRECTORY_SEPARATOR . 'source.png';
$target = $temp . DIRECTORY_SEPARATOR . 'avatar.jpg';

try {
    $image = imagecreatetruecolor(64, 32);
    avatarProcessorAssert($image instanceof GdImage, 'Не удалось создать тестовое изображение');
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 100, 180));
    avatarProcessorAssert(imagepng($image, $source), 'Не удалось записать исходное PNG');
    imagedestroy($image);

    (new AvatarImageProcessor())->writeSquareJpeg($source, $target, 256, 85);

    avatarProcessorAssert(is_file($target), 'Обработанный JPEG не создан');
    $info = getimagesize($target);
    avatarProcessorAssert(is_array($info), 'Обработанный JPEG не читается');
    avatarProcessorAssert(($info[0] ?? 0) === 256 && ($info[1] ?? 0) === 256, 'Размер аватара отличается от 256x256');
    avatarProcessorAssert(($info[2] ?? 0) === IMAGETYPE_JPEG, 'Результат не является JPEG');

    if (PHP_OS_FAMILY !== 'Windows') {
        avatarProcessorAssert((fileperms($target) & 0777) === 0600, 'Права аватара отличаются от 0600');
    }

    fwrite(STDOUT, "[OK] Profile обрабатывает аватар без legacy-класса Core\\Images\n");
} finally {
    @unlink($target);
    @unlink($source);
    @rmdir($temp);
}
