<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

class NoteModel extends ORM
{
    protected ?string $_tablename = 'notes';

    public int $id = 0;
    public string $uid = '';
    public string $created_note = '';
    public string $updated_note = '';
    public string $notename = '';
    public string $content = '';
    public string $content_type = 'text';
    public int $is_encrypted = 1;
    public int $is_deleted = 0;
    public ?string $deleted_at = null;
    public int $user_id = 0;

    public ?UserModel $author = null;

    /** @var list<NoteAttachmentModel> */
    public array $attachments = [];

    /** @var list<NoteTagModel> */
    public array $tags = [];

    /** @var list<int>|null */
    public ?array $shared_with = null;

    public function __construct()
    {
        $this->author = new UserModel();
    }

    /** @return list<NoteAttachmentModel> */
    public function getAttachments(): array
    {
        if ($this->id <= 0) {
            return [];
        }

        $attachments = NoteAttachmentModel::select()
            ->where('note_id', '=', $this->id)
            ->where('is_deleted', '=', 0)
            ->get();

        return $attachments ?: [];
    }

    /** @return list<NoteTagModel> */
    public function getTags(): array
    {
        if ($this->id <= 0) {
            return [];
        }

        $tags = NoteTagModel::select('note_tags.*')
            ->innerJoin([NoteTagRelationModel::class, 'relation'], 'note_tags.id', '=', 'relation.tag_id')
            ->where('relation.note_id', '=', $this->id)
            ->get();

        return $tags ?: [];
    }

    public function canAccess(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    /** @return array<string,mixed>|null */
    public function getShareInfo(): ?array
    {
        if ($this->id <= 0) {
            return null;
        }

        $share = SharedNoteModel::select()
            ->where('note_id', '=', $this->id)
            ->where('is_active', '=', 1)
            ->first();

        return $share ?: null;
    }
}
