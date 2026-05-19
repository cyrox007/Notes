<?php

namespace App\Models;

use Core\ORM;

/**
 * Модель для работы с квотами хранилища пользователей
 */
class UserStorageQuotaModel extends ORM {
    protected ?string $_tablename = "user_storage_quotas";
    
    public int $id = 0;
    public int $user_id = 0;
    public int $used_storage = 0;
    public string $last_calculated_at = '';
}
