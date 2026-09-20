<?php
namespace App\Models;

use Core\ORM;

/**
 * Модель общего доступа к заметкам
 */
class SharedNoteModel extends ORM {
    protected ?string $_tablename = "shared_notes";

    public int $id = 0;
    public int $note_id = 0;
    public int $owner_id = 0;
    public ?int $shared_with_user_id = null;
    public string $share_token = '';
    public string $access_type = 'view'; // view, edit
    public ?string $expires_at = null;
    public string $shared_at = '';
    public int $is_active = 1;

    /**
     * Проверить активна ли ссылка
     */
    public function isActive(): bool {
        if (!$this->is_active) {
            return false;
        }
        
        if ($this->expires_at && strtotime($this->expires_at) < time()) {
            return false;
        }
        
        return true;
    }

    /**
     * Получить URL для шаринга
     */
    public function getShareUrl(): string {
        return '/notes/shared/' . $this->share_token;
    }

    /**
     * Проверить доступ на редактирование
     */
    public function canEdit(): bool {
        return $this->access_type === 'edit' && $this->isActive();
    }
}
