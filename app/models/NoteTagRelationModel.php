<?php
namespace App\Models;

use Core\ORM;

/**
 * Модель связей заметок и тегов
 */
class NoteTagRelationModel extends ORM {
    protected ?string $_tablename = "note_tag_relations";

    public int $id = 0;
    public int $note_id = 0;
    public int $tag_id = 0;
    public string $created_at = '';
}
