<?php
namespace App\Models;

use Core\ORM;

/**
 * Модель тегов для заметок
 */
class NoteTagModel extends ORM {
    protected ?string $_tablename = "note_tags";

    public int $id = 0;
    public int $user_id = 0;
    public string $tag_name = '';
    public string $color = '#000000';
    public string $created_at = '';
}
