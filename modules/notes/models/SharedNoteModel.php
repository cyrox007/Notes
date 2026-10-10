<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

/**
 * Модель общего доступа к заметкам.
 */
class SharedNoteModel extends ORM
{
    protected ?string $_tablename = 'shared_notes';

    public int $id = 0;
    public int $note_id = 0;
    public int $owner_id = 0;
    public ?int $shared_with_user_id = null;
    public string $share_token = '';
    public string $access_type = 'view'; // просмотр или редактирование
    public ?string $expires_at = null;
    public string $shared_at = '';
    public int $is_active = 1;

    public function isActive(): bool
    {
        if ($this->is_active === 0) {
            return false;
        }

        if ($this->expires_at === null || $this->expires_at === '') {
            return true;
        }

        $expiresAt = strtotime($this->expires_at);
        return $expiresAt !== false && $expiresAt >= time();
    }

    public function getShareUrl(): string
    {
        return '/notes/shared/' . $this->share_token;
    }

    public function canEdit(): bool
    {
        return $this->access_type === 'edit' && $this->isActive();
    }
}
