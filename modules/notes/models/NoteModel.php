<?php
namespace App\Models;

use Core\Model;
use Core\Config;
use Core\ORM;

    class NoteModel extends ORM {
        protected ?string $_tablename = "notes";

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
        public array $attachments = [];
        public array $tags = [];
        public ?array $shared_with = null;

        public function __construct() {
            $this->author = new UserModel();
        }

        /**
         * Получить все вложения заметки
         */
        public function getAttachments(): array {
            if (empty($this->id)) {
                return [];
            }
            
            $attachments = NoteAttachmentModel::select()
                ->where('note_id', '=', $this->id)
                ->where('is_deleted', '=', 0)
                ->get();
            
            return $attachments ?: [];
        }

        /**
         * Получить теги заметки
         */
        public function getTags(): array {
            if (empty($this->id)) {
                return [];
            }
            
            $tags = NoteTagModel::select('note_tags.*')
                ->innerJoin([NoteTagRelationModel::class, 'relation'], 'note_tags.id', '=', 'relation.tag_id')
                ->where('relation.note_id', '=', $this->id)
                ->get();
            
            return $tags ?: [];
        }

        /**
         * Проверить доступ на чтение/запись
         */
        public function canAccess(int $userId): bool {
            return $this->user_id === $userId;
        }

        /**
         * Получить информацию о шеринге
         */
        public function getShareInfo(): ?array {
            if (empty($this->id)) {
                return null;
            }
            
            $share = SharedNoteModel::select()
                ->where('note_id', '=', $this->id)
                ->where('is_active', '=', 1)
                ->first();
            
            return $share ?: null;
        }
    }