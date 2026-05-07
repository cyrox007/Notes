<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

/**
 * Модель для работы с файлами и папками пользователей
 */
class FileModel extends ORM {
    protected ?string $_tablename = "user_files";
    
    public int $id = 0;
    public ?string $uid = '';
    public int $user_id = 0;
    public int $parent_id = 0;
    public string $name = '';
    public string $type = 'file'; // file | folder
    public string $mime_type = '';
    public int $size = 0;
    public string $path = '';
    public string $extension = '';
    public int $is_deleted = 0;
    public string $created_at = '';
    public string $updated_at = '';
}
