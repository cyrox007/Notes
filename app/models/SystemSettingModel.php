<?php

namespace App\Models;

use Core\ORM;

/**
 * Модель для работы с системными настройками
 */
class SystemSettingModel extends ORM {
    protected ?string $_tablename = "system_settings";
    
    public int $id = 0;
    public string $setting_key = '';
    public ?string $setting_value = null;
    public string $setting_type = 'string';
    public ?string $description = null;
    public string $category = 'general';
    public int $is_editable = 1;
    public string $created_at = '';
    public string $updated_at = '';
}
