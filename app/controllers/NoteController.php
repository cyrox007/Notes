<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\NoteModel;
use App\Models\UserModel;
use App\Models\NoteAttachmentModel;
use App\Models\SharedNoteModel;
use App\Helpers\CryptMethods;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use Core\Config;

class NoteController extends Controller
{
    /**
     * Главная страница заметок - список всех заметок пользователя
     */
    public function index(Request $request): void
    {
        $user = UserModel::select()
            ->where('uid', '=', $request->session('user_uid'))
            ->first();

        $sort = $request->get('sort') ?? 'created_note';
        $direction = $request->get('direction') ?? 'desc';

        // Получаем личные заметки пользователя с информацией о вложениях
        $userNotes = NoteModel::select('notes.uid', 'notes.notename', 'notes.created_note', 'notes.updated_note', 'notes.content_type')
            ->leftJoin([NoteAttachmentModel::class, 'attachments'], 'notes.id', '=', 'attachments.note_id')
            ->where('notes.user_id', '=', $user->id)
            ->where('notes.is_deleted', '=', 0)
            ->groupBy('notes.id')
            ->orderBy($sort, $direction)
            ->get();
        
        // Для админов - все заметки
        $allNotes = [];
        if ($user->role >= 900) {
            $allNotes = NoteModel::select(
                'notes.uid', 'notes.notename', 'notes.created_note', 'notes.updated_note', 'notes.content_type',
                'author.username', 'author.uid'
            )
                ->innerJoin([UserModel::class, 'author'], 'notes.user_id', '=', 'author.id')
                ->where('notes.is_deleted', '=', 0)
                ->orderBy($sort, $direction)
                ->get();
        }
        
        $data = [
            'personalNotes' => $userNotes,
            'allNotes' => $allNotes,
            'user' => $user,
        ]; 
        
        $this->render_template('notes_page/index', $data);
    }

    /**
     * Создание новой заметки
     */
    public function create(Request $request): void
    {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $uidNote = bin2hex(random_bytes(16));
        $createdAt = date('Y-m-d H:i:s');
        
        $newNote = new NoteModel();
        $newNote->uid = $uidNote;
        $newNote->notename = $request->post('notename');
        $newNote->content = '';
        $newNote->content_type = 'text';
        $newNote->is_encrypted = 1;
        $newNote->is_deleted = 0;
        $newNote->created_note = $createdAt;
        $newNote->updated_note = $createdAt;
        $newNote->user_id = $user->id;

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $newNote->uid,
            'notename' => $newNote->notename,
            'content' => $newNote->content,
            'content_type' => $newNote->content_type,
            'is_encrypted' => $newNote->is_encrypted,
            'is_deleted' => $newNote->is_deleted,
            'created_note' => $newNote->created_note,
            'updated_note' => $newNote->updated_note,
            'user_id' => $newNote->user_id,
        ], 'notes');
        $dbManager->commit();

        Router::getInstance()->redirect('edit_page', 'name', ['uid' => $uidNote]);
    }

    /**
     * Страница редактирования заметки
     */
    public function edit(Request $request, string $uid): void
    {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $note = NoteModel::select(
            'notes.*',
            'author.username', 'author.uid'
        )
            ->innerJoin([UserModel::class, 'author'], 'notes.user_id', '=', 'author.id')
            ->where('notes.uid', '=', $uid)
            ->where('notes.is_deleted', '=', 0)
            ->first();
        
        if (!$note || $note->user_id !== $user->id) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }
        
        // Расшифровываем контент если он зашифрован
        if ($note->is_encrypted && !empty($note->content)) {
            try {
                $note->content = CryptMethods::decrypt($note->content, $note->uid);
            } catch (\Exception $e) {
                // Если расшифровка не удалась, оставляем как есть
            }
        }
        
        // Получаем вложения
        $attachments = $note->getAttachments();
        
        // Получаем информацию о шеринге
        $shareInfo = $note->getShareInfo();
        
        $data = [
            'user' => $user,
            'note' => $note,
            'attachments' => $attachments,
            'shareInfo' => $shareInfo,
            'shareUrl' => $shareInfo ? Config::get('SITEURL') . '/notes/shared/' . $shareInfo['share_token'] : null,
        ];
        
        $this->render_template('notes_page/edit_view', $data);
    }

    /**
     * Обновление заметки (текстовый контент)
     */
    public function update(Request $request, string $uid): void
    {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $note = NoteModel::select()->where('uid', '=', $uid)->first(true);
        
        if (!$note || $note->user_id !== $user->id) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }

        $content = $request->post('content');
        
        // Шифруем контент перед сохранением
        $encryptedContent = '';
        if (!empty($content)) {
            try {
                $encryptedContent = CryptMethods::encrypt($content, $note->uid);
            } catch (\Exception $e) {
                // Если шифрование не удалось, сохраняем как есть (логировать ошибку)
                $encryptedContent = $content;
            }
        }
        
        $note->content = $encryptedContent;
        $note->content_type = 'text';
        $note->updated_note = date('Y-m-d H:i:s');

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'content' => $note->content,
            'content_type' => $note->content_type,
            'updated_note' => $note->updated_note,
        ], 'notes', (int) $note->id);

        $dbManager->commit();
        
        Router::getInstance()->redirect('notes', 'name');
    }

    /**
     * Загрузка файла/медиа/голосового сообщения в заметку
     */
    public function uploadAttachment(Request $request, string $uid): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $note = NoteModel::select()->where('uid', '=', $uid)->where('is_deleted', '=', 0)->first();
        
        if (!$note || $note->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        // Проверка файла
        if (!$request->hasFile('attachment')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не найден']);
            return;
        }
        
        $file = $request->file('attachment');
        
        // Проверка размера
        $maxSize = (int) getenv('MAX_UPLOAD_SIZE') ?: 10485760;
        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл слишком большой']);
            return;
        }
        
        // Определение типа файла
        $mimeType = $file['type'];
        $fileType = 'document';
        
        if (strpos($mimeType, 'image/') === 0) {
            $fileType = 'image';
        } elseif (strpos($mimeType, 'audio/') === 0) {
            $fileType = 'audio';
        } elseif (strpos($mimeType, 'video/') === 0) {
            $fileType = 'video';
        }
        
        // Проверка на голосовое сообщение (специальный тип или из формы)
        if ($request->post('is_voice') === 'true' || strpos($file['name'], 'voice_') === 0) {
            $fileType = 'voice';
        }
        
        // Генерация уникального имени
        $fileUid = bin2hex(random_bytes(16));
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = $fileUid . '.' . $extension;
        
        // Путь загрузки
        $uploadDir = getenv('NOTES_UPLOAD_DIR') ?: '/var/www/uploads/notes';
        $noteDir = $uploadDir . '/' . $note->uid;
        
        if (!is_dir($noteDir)) {
            mkdir($noteDir, 0755, true);
        }
        
        $filePath = $noteDir . '/' . $safeName;
        
        // Перемещение файла
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла']);
            return;
        }
        
        // Получение длительности для аудио/видео/голоса
        $duration = null;
        if (in_array($fileType, ['audio', 'video', 'voice'])) {
            // Можно использовать getID3 или ffmpeg для получения длительности
            // Пока оставляем null
        }
        
        // Сохранение в БД
        $attachment = new NoteAttachmentModel();
        $attachment->note_id = $note->id;
        $attachment->file_uid = $fileUid;
        $attachment->file_name = $file['name'];
        $attachment->file_path = $filePath;
        $attachment->file_type = $fileType;
        $attachment->mime_type = $mimeType;
        $attachment->file_size = $file['size'];
        $attachment->duration = $duration;
        $attachment->is_encrypted = 1;
        $attachment->uploaded_at = date('Y-m-d H:i:s');
        $attachment->is_deleted = 0;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'note_id' => $attachment->note_id,
            'file_uid' => $attachment->file_uid,
            'file_name' => $attachment->file_name,
            'file_path' => $attachment->file_path,
            'file_type' => $attachment->file_type,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'duration' => $attachment->duration,
            'is_encrypted' => $attachment->is_encrypted,
            'uploaded_at' => $attachment->uploaded_at,
            'is_deleted' => $attachment->is_deleted,
        ], 'note_attachments');
        $dbManager->commit();
        
        echo json_encode([
            'success' => true,
            'attachment' => [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type,
                'file_size' => $attachment->getFormattedSize(),
                'file_url' => '/uploads/notes/' . $note->uid . '/' . $safeName,
                'is_voice' => $attachment->isVoice(),
                'is_image' => $attachment->isImage(),
            ]
        ]);
    }

    /**
     * Удаление вложения
     */
    public function deleteAttachment(Request $request, int $attachmentId): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $attachment = NoteAttachmentModel::select()
            ->innerJoin([NoteModel::class, 'note'], 'note_attachments.note_id', '=', 'note.id')
            ->where('note_attachments.id', '=', $attachmentId)
            ->first();
        
        if (!$attachment || $attachment->note->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        // Safe-удаление
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'is_deleted' => 1,
        ], 'note_attachments', $attachmentId);
        $dbManager->commit();
        
        // Физическое удаление файла можно выполнить позже по крону
        
        echo json_encode(['success' => true]);
    }

    /**
     * Удаление заметки
     */
    public function delete(Request $request, string $uid): void
    {
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();

        $note = NoteModel::select()->where('uid', '=', $uid)->first();

        if (!$note || $note->user_id !== $user->id) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }

        // Safe-удаление
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'is_deleted' => 1,
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'notes', (int) $note->id);

        $dbManager->commit();
        
        Router::getInstance()->redirect('notes', 'name');
    }

    /**
     * Создать ссылку для шаринга заметки
     */
    public function shareNote(Request $request, string $uid): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $note = NoteModel::select()->where('uid', '=', $uid)->where('is_deleted', '=', 0)->first();
        
        if (!$note || $note->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $accessType = $request->post('access_type') ?? 'view';
        $expiresIn = (int) $request->post('expires_in') ?? 0; // в часах, 0 = бессрочно
        
        // Генерация токена
        $shareToken = bin2hex(random_bytes(32));
        $expiresAt = $expiresIn > 0 ? date('Y-m-d H:i:s', time() + $expiresIn * 3600) : null;
        
        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'note_id' => $note->id,
            'owner_id' => $user->id,
            'shared_with_user_id' => null, // публичная ссылка
            'share_token' => $shareToken,
            'access_type' => $accessType,
            'expires_at' => $expiresAt,
            'shared_at' => date('Y-m-d H:i:s'),
            'is_active' => 1,
        ], 'shared_notes');
        $dbManager->commit();
        
        $shareUrl = Config::get('SITEURL') . '/notes/shared/' . $shareToken;
        
        echo json_encode([
            'success' => true,
            'share_url' => $shareUrl,
            'access_type' => $accessType,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Просмотр заметки по ссылке шаринга
     */
    public function viewShared(Request $request, string $token): void
    {
        $share = SharedNoteModel::select()
            ->innerJoin([NoteModel::class, 'note'], 'shared_notes.note_id', '=', 'note.id')
            ->innerJoin([UserModel::class, 'owner'], 'note.user_id', '=', 'owner.id')
            ->where('shared_notes.share_token', '=', $token)
            ->where('shared_notes.is_active', '=', 1)
            ->first();
        
        if (!$share) {
            http_response_code(404);
            die('Заметка не найдена или ссылка неактивна');
        }
        
        // Проверка срока действия
        if ($share->expires_at && strtotime($share->expires_at) < time()) {
            http_response_code(410);
            die('Срок действия ссылки истек');
        }
        
        // Расшифровка контента
        $content = $share->note__content ?? '';
        if (($share->note__is_encrypted ?? 0) && !empty($content)) {
            try {
                $content = CryptMethods::decrypt($content, $share->note__uid ?? '');
            } catch (\Exception $e) {
                $content = '[Ошибка расшифровки]';
            }
        }
        
        // Получение вложений
        $attachments = NoteAttachmentModel::select()
            ->where('note_id', '=', $share->note_id)
            ->where('is_deleted', '=', 0)
            ->get();
        
        $data = [
            'note' => [
                'notename' => $share->note__notename ?? '',
                'content' => $content,
                'content_type' => $share->note__content_type ?? '',
                'created_note' => $share->note__created_note ?? '',
                'updated_note' => $share->note__updated_note ?? '',
                'owner' => $share->owner__username ?? '',
            ],
            'attachments' => $attachments ?: [],
            'canEdit' => ($share->access_type ?? '') === 'edit',
            'shareExpired' => false,
        ];
        
        $this->render_template('notes_page/shared_view', $data);
    }

    /**
     * Деактивировать ссылку шаринга
     */
    public function unshareNote(Request $request, string $uid): void
    {
        header('Content-Type: application/json');
        
        $user = UserModel::select()->where('uid', '=', $request->session('user_uid'))->first();
        
        $note = NoteModel::select()->where('uid', '=', $uid)->where('is_deleted', '=', 0)->first();
        
        if (!$note || $note->user_id !== $user->id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
            return;
        }
        
        $dbManager = DatabaseManager::getInstance();
        $shareRecord = SharedNoteModel::select()
            ->where('note_id', '=', (int) $note->id)
            ->where('owner_id', '=', $user->id)
            ->first();
        
        if ($shareRecord) {
            $dbManager->queueUpdate([
                'is_active' => 0,
            ], 'shared_notes', (int) $shareRecord->id);
        }
        $dbManager->commit();
        
        echo json_encode(['success' => true]);
    }
}
