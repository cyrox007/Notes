<?php
namespace App\Models;

use Core\ORM;

/**
 * Модель вложений заметок.
 *
 * Файлы хранятся вне document root и выдаются через авторизованный
 * /notes/attachment/{file_uid}. Значение is_encrypted описывает реальное
 * шифрование байтов файла; новые вложения пока не шифруются и имеют 0.
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
    public ?int $duration = null;
    public int $is_encrypted = 0;
    public ?string $encryption_key_ref = null;
    public string $uploaded_at = '';
    public int $is_deleted = 0;

    public ?NoteModel $note = null;

    public function getFileUrl(): string {
        $baseSegment = trim((string) getenv('BASE_PATH'), '/');
        $basePath = $baseSegment !== '' ? '/' . $baseSegment : '';
        return $basePath . '/notes/attachment/' . rawurlencode($this->file_uid);
    }

    public function isVoice(): bool {
        return $this->file_type === 'voice';
    }

    public function isImage(): bool {
        return $this->file_type === 'image';
    }

    public function isMedia(): bool {
        return in_array($this->file_type, ['audio', 'video'], true);
    }

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
