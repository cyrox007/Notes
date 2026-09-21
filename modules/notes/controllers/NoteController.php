<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Models\UserModel;
use App\Services\ListQuery;
use Core\Config;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use InvalidArgumentException;
use RuntimeException;

final class NoteController extends Controller
{
    /** @var array<string,string> */
    private const SORT_COLUMNS = [
        'notename' => 'notes.notename',
        'created_note' => 'notes.created_note',
        'updated_note' => 'notes.updated_note',
    ];

    public function index(Request $request): void
    {
        $user = $this->currentUser($request);
        $query = ListQuery::fromRequest($request, self::SORT_COLUMNS, 'created_note');
        $db = DatabaseManager::getInstance();

        $personalWhere = ['user_id = :user_id', 'is_deleted = 0'];
        $personalParams = [':user_id' => (int) $user->id];
        if ($query['q'] !== '') {
            $personalWhere[] = 'notename LIKE :q';
            $personalParams[':q'] = '%' . $query['q'] . '%';
        }
        $personalWhereSql = implode(' AND ', $personalWhere);
        $personalTotal = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM notes WHERE ' . $personalWhereSql,
            $personalParams
        );
        $personalNotes = $db->fetchAll(
            'SELECT uid,notename,created_note,updated_note,content_type
             FROM notes
             WHERE ' . $personalWhereSql . '
             ORDER BY ' . $query['sort_column'] . ' ' . $query['direction_sql'] . ', id DESC
             LIMIT ' . (int) $query['limit'] . ' OFFSET ' . (int) $query['offset'],
            $personalParams
        );

        $isAdmin = Config::isAdminRole((int) $user->role);
        $allNotes = [];
        $allTotal = 0;
        if ($isAdmin) {
            $allWhere = ['notes.is_deleted = 0'];
            $allParams = [];
            if ($query['q'] !== '') {
                $allWhere[] = '(notes.notename LIKE :q OR author.username LIKE :q OR author.email LIKE :q)';
                $allParams[':q'] = '%' . $query['q'] . '%';
            }
            $allWhereSql = implode(' AND ', $allWhere);
            $allTotal = (int) $db->fetchValue(
                'SELECT COUNT(*)
                 FROM notes
                 INNER JOIN users author ON author.id = notes.user_id
                 WHERE ' . $allWhereSql,
                $allParams
            );
            $allNotes = $db->fetchAll(
                'SELECT
                    notes.uid,notes.notename,notes.created_note,notes.updated_note,notes.content_type,
                    author.username AS author_username,author.id AS author_id
                 FROM notes
                 INNER JOIN users author ON author.id = notes.user_id
                 WHERE ' . $allWhereSql . '
                 ORDER BY ' . $query['sort_column'] . ' ' . $query['direction_sql'] . ', notes.id DESC
                 LIMIT ' . (int) $query['limit'] . ' OFFSET ' . (int) $query['offset'],
                $allParams
            );
        }

        $pagination = ListQuery::pagination($query, max($personalTotal, $allTotal));
        $this->render_template('@notes/index', [
            'personalNotes' => $personalNotes,
            'allNotes' => $allNotes,
            'user' => $user,
            'isAdmin' => $isAdmin,
            'pagination' => $pagination,
        ]);
    }

    public function create(Request $request): void
    {
        $user = $this->currentUser($request);
        $uid = bin2hex(random_bytes(16));
        $name = $this->noteName((string) $request->post('notename', ''));
        $content = (string) $request->post('content', '');
        $this->assertContentLength($content);

        try {
            $encrypted = CryptMethods::encrypt($content, $uid);
        } catch (\Throwable $e) {
            error_log('Note encryption failed on create: ' . $e->getMessage());
            throw new RuntimeException('Не удалось безопасно сохранить заметку');
        }

        $now = date('Y-m-d H:i:s');
        DatabaseManager::getInstance()->execute(
            'INSERT INTO notes (
                uid,user_id,notename,content,content_type,is_encrypted,
                created_note,updated_note,is_deleted,deleted_at
             ) VALUES (
                :uid,:user_id,:notename,:content,"text",1,
                :created_note,:updated_note,0,NULL
             )',
            [
                ':uid' => $uid,
                ':user_id' => (int) $user->id,
                ':notename' => $name,
                ':content' => $encrypted,
                ':created_note' => $now,
                ':updated_note' => $now,
            ]
        );

        Router::getInstance()->redirect('edit_page', 'name', ['uid' => $uid]);
    }

    public function edit(Request $request, string $uid): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $note = $db->fetchOne(
            'SELECT
                n.id,n.uid,n.user_id,n.notename,n.content,n.content_type,n.is_encrypted,
                n.created_note,n.updated_note,n.is_deleted,n.deleted_at,
                u.username AS author_username,u.id AS author_id
             FROM notes n
             INNER JOIN users u ON u.id = n.user_id
             WHERE n.uid = :uid AND n.user_id = :user_id AND n.is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => (int) $user->id]
        );
        if (!$note) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }

        if ((int) $note['is_encrypted'] === 1) {
            try {
                $note['content'] = CryptMethods::decrypt((string) $note['content'], (string) $note['uid']);
            } catch (\Throwable $e) {
                error_log('Note decrypt failed for ' . $uid . ': ' . $e->getMessage());
                http_response_code(500);
                echo 'Содержимое заметки временно недоступно';
                return;
            }
        }

        $attachments = $db->fetchAll(
            'SELECT id,file_uid,file_name,file_type,mime_type,file_size,duration,uploaded_at
             FROM note_attachments
             WHERE note_id = :note_id AND is_deleted = 0
             ORDER BY id ASC',
            [':note_id' => (int) $note['id']]
        );
        foreach ($attachments as &$attachment) {
            $attachment['formatted_size'] = $this->formatBytes((int) ($attachment['file_size'] ?? 0));
        }
        unset($attachment);

        $shareInfo = $db->fetchOne(
            'SELECT share_token,access_type,expires_at,shared_at
             FROM shared_notes
             WHERE note_id = :note_id
               AND owner_id = :owner_id
               AND shared_with_user_id IS NULL
               AND is_active = 1
               AND (expires_at IS NULL OR expires_at >= :now)
             ORDER BY id DESC
             LIMIT 1',
            [
                ':note_id' => (int) $note['id'],
                ':owner_id' => (int) $user->id,
                ':now' => date('Y-m-d H:i:s'),
            ]
        );

        $this->render_template('@notes/edit_view', [
            'user' => $user,
            'note' => $note,
            'attachments' => $attachments,
            'shareInfo' => $shareInfo ?: null,
        ]);
    }

    public function update(Request $request, string $uid): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $note = $db->fetchOne(
            'SELECT id,uid FROM notes
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => (int) $user->id]
        );
        if (!$note) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }

        $name = $this->noteName((string) $request->post('notename', ''));
        $content = (string) $request->post('content', '');
        $this->assertContentLength($content);
        try {
            $encrypted = CryptMethods::encrypt($content, (string) $note['uid']);
        } catch (\Throwable $e) {
            error_log('Note encryption failed on update for ' . $uid . ': ' . $e->getMessage());
            throw new RuntimeException('Не удалось безопасно сохранить заметку');
        }

        $db->execute(
            'UPDATE notes
             SET notename = :notename,
                 content = :content,
                 content_type = "text",
                 is_encrypted = 1,
                 updated_note = :updated_note
             WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
            [
                ':notename' => $name,
                ':content' => $encrypted,
                ':updated_note' => date('Y-m-d H:i:s'),
                ':id' => (int) $note['id'],
                ':user_id' => (int) $user->id,
            ]
        );

        Router::getInstance()->redirect('notes', 'name');
    }

    public function delete(Request $request, string $uid): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();
        $note = $db->fetchOne(
            'SELECT id FROM notes
             WHERE uid = :uid AND user_id = :user_id AND is_deleted = 0
             LIMIT 1',
            [':uid' => $uid, ':user_id' => (int) $user->id]
        );
        if (!$note) {
            Router::getInstance()->redirect('notes', 'name');
            return;
        }

        $noteId = (int) $note['id'];
        $userId = (int) $user->id;
        $db->beginTransaction();
        try {
            $db->execute(
                'UPDATE note_attachments SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP
                 WHERE note_id = :note_id AND is_deleted = 0',
                [':note_id' => $noteId]
            );
            $db->execute(
                'UPDATE shared_notes SET is_active = 0
                 WHERE note_id = :note_id AND owner_id = :owner_id AND is_active = 1',
                [':note_id' => $noteId, ':owner_id' => $userId]
            );
            $db->execute(
                'UPDATE notes
                 SET is_deleted = 1, deleted_at = :deleted_at
                 WHERE id = :id AND user_id = :user_id AND is_deleted = 0',
                [
                    ':deleted_at' => date('Y-m-d H:i:s'),
                    ':id' => $noteId,
                    ':user_id' => $userId,
                ]
            );
            $db->endTransaction(true);
        } catch (\Throwable $e) {
            $db->endTransaction(false);
            throw $e;
        }

        Router::getInstance()->redirect('notes', 'name');
    }

    private function currentUser(Request $request): UserModel
    {
        $id = (int) $request->session('user_id', 0);
        $user = $id > 0
            ? UserModel::select('id', 'uid', 'username', 'firstname', 'lastname', 'avatar', 'role', 'is_active')
                ->where('id', '=', $id)
                ->where('is_active', '=', 1)
                ->first()
            : null;
        if (!$user) {
            throw new RuntimeException('Пользователь не найден или заблокирован');
        }
        return $user;
    }

    private function noteName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') return 'Без названия';
        if (mb_strlen($name) > 255) throw new InvalidArgumentException('Название заметки слишком длинное');
        return $name;
    }

    private function assertContentLength(string $content): void
    {
        if (strlen($content) > 60000) {
            throw new InvalidArgumentException('Текст заметки слишком большой');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return round($value, 2) . ' ' . $units[$unit];
    }
}
