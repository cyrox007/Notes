<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use Core\PrivateStoragePath;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Сервер хранит только клиентский AES-GCM-шифротекст.
 * Имена исходных файлов, ключи и дескрипторы расшифровки сюда не передаются.
 */
final class MessengerE2eeMediaService
{
    private const MAX_CIPHER_BYTES = 10 * 1024 * 1024 + 16;

    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    public static function validateUid(string $uid): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $uid) !== 1) {
            throw new InvalidArgumentException('Неверный идентификатор зашифрованного вложения');
        }
    }

    /** @return array{uid:string,size:int} */
    public function upload(int $userId, string $dialogUid, string $uid, array $file): array
    {
        self::validateUid($uid);
        $dialog = $this->requireDialog($userId, $dialogUid);
        $error = $file['error'] ?? null;
        $tmp = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp)
            || !is_uploaded_file($tmp) || !is_int($size)
            || $size < 17 || $size > self::MAX_CIPHER_BYTES
            || filesize($tmp) !== $size) {
            throw new InvalidArgumentException('Загрузите зашифрованный файл допустимого размера');
        }

        $directory = (new PrivateStoragePath())->child('messenger-e2ee');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Защищённое хранилище недоступно');
        }
        if (is_link($directory)) {
            throw new RuntimeException('Символическая ссылка на хранилище запрещена');
        }
        $stored = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.bin';
        if (!move_uploaded_file($tmp, $stored)) {
            throw new RuntimeException('Не удалось записать зашифрованные данные');
        }
        @chmod($stored, 0600);
        try {
            $this->db->execute(
                'INSERT INTO messenger_e2ee_attachments
                  (uid, dialog_id, uploader_user_id, stored_path, size)
                 VALUES (:uid, :dialog, :user, :path, :size)',
                [':uid'=>$uid, ':dialog'=>(int)$dialog['id'], ':user'=>$userId,
                 ':path'=>$stored, ':size'=>$size]
            );
        } catch (Throwable $e) {
            @unlink($stored);
            throw $e;
        }
        return ['uid'=>$uid, 'size'=>$size];
    }

    /** @return array{path:string,size:int} */
    public function download(int $userId, string $uid): array
    {
        self::validateUid($uid);
        $row = $this->db->fetchOne(
            'SELECT a.stored_path, a.size FROM messenger_e2ee_attachments a
             INNER JOIN user_to_dialogs member
               ON member.dialog_id = a.dialog_id AND member.user_id = :user AND member.is_deleted = 0
             INNER JOIN users u ON u.id = member.user_id AND u.is_active = 1
             WHERE a.uid = :uid
               AND (a.event_id IS NOT NULL OR a.uploader_user_id = :user)
             LIMIT 1',
            [':uid'=>$uid, ':user'=>$userId]
        );
        if ($row === null) {
            throw new DomainException('Зашифрованное вложение недоступно', 404);
        }
        $storage = (new PrivateStoragePath())->child('messenger-e2ee');
        $path = (new PrivateStoragePath())->resolveExisting((string)$row['stored_path'], $storage);
        if ($path === null || !is_file($path) || filesize($path) !== (int)$row['size']) {
            throw new RuntimeException('Не удалось прочитать шифротекст');
        }
        return ['path'=>$path,'size'=>(int)$row['size']];
    }

    /** @param list<string> $uids */
    public function bindEvent(int $uploader, int $dialogId, int $eventId, array $uids): void
    {
        if (count($uids) > 5 || count($uids) !== count(array_unique($uids))) {
            throw new InvalidArgumentException('Неверное количество зашифрованных вложений');
        }
        foreach ($uids as $uid) {
            if (!is_string($uid)) {
                throw new InvalidArgumentException('Некорректное зашифрованное вложение');
            }
            self::validateUid($uid);
            $result = $this->db->execute(
                'UPDATE messenger_e2ee_attachments SET event_id = :event
                 WHERE uid = :uid AND dialog_id = :dialog
                   AND uploader_user_id = :user AND (event_id IS NULL OR event_id = :event)',
                [':event'=>$eventId, ':uid'=>$uid, ':dialog'=>$dialogId, ':user'=>$uploader]
            );
            $affected = $result instanceof \PDOStatement ? $result->rowCount() : (int)$result;
            if ($affected !== 1) {
                throw new DomainException('Вложение отсутствует, чужое или уже привязано', 409);
            }
        }
    }

    /** @param list<string> $uids */
    public function assertBoundEvent(int $eventId, array $uids): void
    {
        $rows = $this->db->fetchAll(
            'SELECT uid FROM messenger_e2ee_attachments WHERE event_id = :event ORDER BY uid ASC',
            [':event'=>$eventId]
        );
        $actual = array_map(static fn (array $row): string => (string)$row['uid'], $rows);
        sort($uids, SORT_STRING);
        if ($actual !== $uids) {
            throw new DomainException('Повторная отправка изменила состав вложений', 409);
        }
    }

    /** @return array{id:int} */
    private function requireDialog(int $userId, string $dialogUid): array
    {
        $dialog = $this->db->fetchOne(
            'SELECT d.id FROM dialogs d
             INNER JOIN user_to_dialogs member
               ON member.dialog_id = d.id AND member.user_id = :user AND member.is_deleted = 0
             INNER JOIN users u ON u.id = member.user_id AND u.is_active = 1
             WHERE d.uid = :uid LIMIT 1',
            [':user'=>$userId, ':uid'=>$dialogUid]
        );
        if ($dialog === null) {
            throw new DomainException('Нет доступа к защищённому диалогу', 403);
        }
        return ['id'=>(int)$dialog['id']];
    }
}
