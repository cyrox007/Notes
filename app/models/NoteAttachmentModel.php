<?php
namespace App\Models;

use Core\ORM;

/**
 * Модель вложений заметок (медиа, аудио, файлы)
 */
class NoteAttachmentModel extends ORM {
    protected ?string $_tablename = "note_attachments";

    public int $id = 0;
    public int $note_id = 0;
    public string $file_uid = '';
    public string $file_name = '';
    public string $file_path = '';
    public string $file_type = ''; // image, audio, video, document, voice
    public string $mime_type = '';
    public int $file_size = 0;
    public ?int $duration = null; // длительность для audio/video/voice
    public int $is_encrypted = 1;
    public ?string $encryption_key_ref = null;
    public string $uploaded_at = '';
    public int $is_deleted = 0;

    public ?NoteModel $note = null;

    /**
     * Получить URL файла для доступа
     */
    public function getFileUrl(): string {
        return '/uploads/notes/' . $this->file_uid . '/' . $this->file_name;
    }

    /**
     * Проверить является ли файл голосовым сообщением
     */
    public function isVoice(): bool {
        return $this->file_type === 'voice';
    }

    /**
     * Проверить является ли файл изображением
     */
    public function isImage(): bool {
        return $this->file_type === 'image';
    }

    /**
     * Проверить является ли файл аудио/видео
     */
    public function isMedia(): bool {
        return in_array($this->file_type, ['audio', 'video']);
    }

    /**
     * Получить размер файла в читаемом формате
     */
    public function getFormattedSize(): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }
}
