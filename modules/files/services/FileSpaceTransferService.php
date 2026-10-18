<?php

declare(strict_types=1);

namespace App\Services\Files;

use Core\MutationCommandStore;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Безопасная передача файлов общего пространства.
 * Физическое имя случайное; пользовательский адрес никогда не становится путём на диске.
 */
final class FileSpaceTransferService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FileSpaceAccessService $access,
        private readonly MutationCommandStore $commands = new MutationCommandStore(),
        private readonly ?string $storageRoot = null,
        private readonly ?\Closure $moveFile = null
    ) {
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function upload(
        int $actorId,
        ?int $parentId,
        array $file,
        int $expectedVersion,
        string $commandId,
        int $maxBytes
    ): array {
        if ($expectedVersion < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException('Не указана версия каталога или лимит загрузки');
        }
        $upload = $this->inspectUpload($file, $maxBytes);
        $hash = $this->commands->requestHash([
            'parent_id' => $parentId,
            'name' => $upload['name'],
            'size' => $upload['size'],
            'sha256' => $upload['sha256'],
            'expected_version' => $expectedVersion,
        ]);
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Загрузка общего файла требует отдельную транзакцию');
        }

        $createdPath = null;
        $this->pdo->beginTransaction();
        try {
            $space = $this->access->space();
            $version = $this->lockContainer((int) $space['id'], $parentId);
            $this->access->require($actorId, 'global', $parentId, 'upload');
            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $actorId, $commandId,
                'files', 'files.file.upload', 'file_space',
                'global:' . ($parentId === null ? 'root' : 'node:' . $parentId), $hash
            );
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }
            if ($version !== $expectedVersion) {
                $result = [
                    'success' => false,
                    'error' => 'version_conflict',
                    'message' => 'Содержимое каталога изменено. Обновите его и повторите загрузку.',
                    'expected_version' => $expectedVersion,
                    'current_version' => $version,
                ];
                $this->finish($receipt, $result, $version, MutationCommandStore::STATUS_CONFLICT);
                $this->pdo->commit();
                return $result;
            }

            $this->assertFreeName((int) $space['id'], $parentId, $upload['name']);
            $directory = $this->directory(true);
            $storageKey = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
            $createdPath = $directory . DIRECTORY_SEPARATOR . $storageKey;
            $moved = $this->moveFile !== null
                ? ($this->moveFile)($upload['temporary_path'], $createdPath)
                : move_uploaded_file($upload['temporary_path'], $createdPath);
            if (!$moved || !is_file($createdPath) || is_link($createdPath)) {
                throw new RuntimeException('Не удалось безопасно сохранить загруженный файл');
            }
            @chmod($createdPath, 0600);

            $uid = bin2hex(random_bytes(16));
            $stmt = $this->pdo->prepare(
                'INSERT INTO file_space_nodes
                 (uid,space_id,parent_id,node_type,name,created_by,updated_by,
                  mime_type,byte_size,storage_key)
                 VALUES (:uid,:space_id,:parent_id,"file",:name,:actor,:editor,
                         :mime_type,:byte_size,:storage_key)'
            );
            $stmt->execute([
                ':uid' => $uid,
                ':space_id' => (int) $space['id'],
                ':parent_id' => $parentId,
                ':name' => $upload['name'],
                ':actor' => $actorId,
                ':editor' => $actorId,
                ':mime_type' => $upload['mime_type'],
                ':byte_size' => $upload['size'],
                ':storage_key' => $storageKey,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();
            $nextVersion = $this->bumpContainer((int) $space['id'], $parentId, $expectedVersion);
            $event = $this->pdo->prepare(
                'INSERT INTO file_space_events (space_id,node_id,actor_user_id,event_code,details_json)
                 VALUES (:space_id,:node_id,:actor_id,"file.upload",:details)'
            );
            $event->execute([
                ':space_id' => (int) $space['id'],
                ':node_id' => $fileId,
                ':actor_id' => $actorId,
                ':details' => json_encode([
                    'parent_id' => $parentId,
                    'name' => $upload['name'],
                    'size' => $upload['size'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            $result = [
                'success' => true,
                'file_id' => $fileId,
                'file_uid' => $uid,
                'version' => $nextVersion,
                'replayed' => false,
            ];
            $this->finish($receipt, $result, $nextVersion, MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($createdPath !== null && is_file($createdPath)) {
                @unlink($createdPath);
            }
            throw $error;
        }
    }

    /**
     * Содержимое заменяется под случайным новым ключом.
     * Старые байты не удаляются до подтверждения транзакции БД.
     *
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function replace(
        int $actorId, int $fileId, array $file, int $expectedVersion,
        int $expectedParentVersion, string $commandId, int $maxBytes
    ): array {
        if ($fileId < 1 || $expectedVersion < 1 || $expectedParentVersion < 1) {
            throw new InvalidArgumentException('Укажите текущую версию файла и каталога');
        }
        $upload = $this->inspectUpload($file, $maxBytes);
        $hash = $this->commands->requestHash([
            'file_id' => $fileId, 'sha256' => $upload['sha256'],
            'size' => $upload['size'], 'name' => $upload['name'],
            'expected_version' => $expectedVersion,
            'expected_parent_version' => $expectedParentVersion,
        ]);
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Замена общего файла требует отдельную транзакцию');
        }
        $newPath = null;
        $oldPath = null;
        $this->pdo->beginTransaction();
        try {
            $space = $this->access->space();
            $lock = $this->pdo->prepare('SELECT id FROM file_spaces WHERE id=:id FOR UPDATE');
            $lock->execute([':id' => (int) $space['id']]);
            $nodeQuery = $this->pdo->prepare(
                'SELECT id,uid,space_id,parent_id,created_by,name,node_type,storage_key,version
                 FROM file_space_nodes
                 WHERE id=:id AND space_id=:space AND is_deleted=0 LIMIT 1 FOR UPDATE'
            );
            $nodeQuery->execute([':id' => $fileId, ':space' => (int) $space['id']]);
            $node = $nodeQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($node) || $node['node_type'] !== 'file') {
                throw new DomainException('Файл не найден или недоступен', 404);
            }
            $parent = $node['parent_id'] === null ? null : (int) $node['parent_id'];
            $this->access->require($actorId, 'global', $fileId, 'view');
            $own = $node['created_by'] !== null && (int) $node['created_by'] === $actorId;
            $this->access->require($actorId, 'global', $fileId, $own ? 'replace_own' : 'replace_others');
            if (strtolower((string) pathinfo((string) $node['name'], PATHINFO_EXTENSION)) !== $upload['extension']) {
                throw new InvalidArgumentException('При замене содержимого расширение файла должно оставаться прежним');
            }
            $parentVersion = $this->lockContainer((int) $space['id'], $parent);
            $receipt = $this->commands->acquire(
                $this->pdo, 'user:' . $actorId, $commandId, 'files',
                'files.file.replace', 'file_space_node', (string) $node['uid'], $hash
            );
            if (!$receipt['owner']) {
                $result = $this->commands->storedResponse($receipt['record']);
                $this->pdo->commit();
                return $result;
            }

            if ((int) $node['version'] !== $expectedVersion
                || $parentVersion !== $expectedParentVersion) {
                $result = [
                    'success' => false, 'error' => 'version_conflict',
                    'message' => 'Файл изменился. Обновите страницу перед заменой.',
                    'current_version' => (int) $node['version'],
                    'current_parent_version' => $parentVersion,
                ];
                $this->finish($receipt, $result, (int) $node['version'], MutationCommandStore::STATUS_CONFLICT);
                $this->pdo->commit();
                return $result;
            }

            $directory = $this->directory(true);
            $key = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
            $newPath = $directory . DIRECTORY_SEPARATOR . $key;
            $moved = $this->moveFile !== null
                ? ($this->moveFile)($upload['temporary_path'], $newPath)
                : move_uploaded_file($upload['temporary_path'], $newPath);
            if (!$moved || is_link($newPath) || !is_file($newPath)) {
                throw new RuntimeException('Не удалось безопасно записать новую версию файла');
            }
            @chmod($newPath, 0600);

            $oldKey = (string) $node['storage_key'];
            if (preg_match('/^[a-f0-9]{48}\.[a-z0-9]{1,12}$/D', $oldKey) === 1) {
                $oldPath = $directory . DIRECTORY_SEPARATOR . $oldKey;
            }
            $update = $this->pdo->prepare(
                'UPDATE file_space_nodes SET storage_key=:key,mime_type=:mime,byte_size=:size,
                    version=version+1,updated_by=:actor,updated_at=NOW()
                 WHERE id=:id AND version=:expected AND is_deleted=0'
            );
            $update->execute([
                ':key' => $key, ':mime' => $upload['mime_type'], ':size' => $upload['size'],
                ':actor' => $actorId, ':id' => $fileId, ':expected' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Файл был изменён параллельно');
            }
            $nextParent = $this->bumpContainer((int) $space['id'], $parent, $expectedParentVersion);
            $event = $this->pdo->prepare(
                'INSERT INTO file_space_events (space_id,node_id,actor_user_id,event_code,details_json)
                 VALUES (:space,:node,:actor,"file.replace",:details)'
            );
            $event->execute([
                ':space' => (int) $space['id'], ':node' => $fileId, ':actor' => $actorId,
                ':details' => json_encode(['size' => $upload['size']], JSON_THROW_ON_ERROR),
            ]);
            $result = [
                'success' => true, 'file_id' => $fileId, 'version' => $expectedVersion + 1,
                'parent_version' => $nextParent, 'replayed' => false,
            ];
            $this->finish($receipt, $result, $expectedVersion + 1, MutationCommandStore::STATUS_APPLIED);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($newPath !== null && is_file($newPath)) {
                @unlink($newPath);
            }
            throw $error;
        }

        // Старое содержимое можно удалить только после успешной фиксации нового.
        if ($oldPath !== null && is_file($oldPath) && !is_link($oldPath) && !@unlink($oldPath)) {
            error_log('Не удалось очистить прежнюю физическую версию общего файла: ' . basename($oldPath));
        }
        return $result;
    }

    /** @return array{path:string,name:string,mime_type:string,size:int} */
    public function download(int $actorId, int $fileId): array
    {
        $space = $this->access->space();
        $stmt = $this->pdo->prepare(
            'SELECT id,space_id,name,node_type,mime_type,byte_size,storage_key
             FROM file_space_nodes
             WHERE id=:id AND space_id=:space_id AND is_deleted=0 LIMIT 1'
        );
        $stmt->execute([':id' => $fileId, ':space_id' => (int) $space['id']]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file) || $file['node_type'] !== 'file') {
            throw new DomainException('Файл не найден или недоступен', 404);
        }
        $this->access->require($actorId, 'global', $fileId, 'view');
        $this->access->require($actorId, 'global', $fileId, 'download');

        $key = (string) $file['storage_key'];
        if (preg_match('/^[a-f0-9]{48}\.[a-z0-9]{1,12}$/D', $key) !== 1) {
            throw new RuntimeException('Некорректный защищённый ключ хранения');
        }
        $directory = $this->directory(false);
        $candidate = $directory . DIRECTORY_SEPARATOR . $key;
        $actual = realpath($candidate);
        if ($actual === false || is_link($candidate)
            || dirname($actual) !== realpath($directory)
            || !is_file($actual) || !is_readable($actual)) {
            throw new DomainException('Файл временно недоступен', 404);
        }

        return [
            'path' => $actual,
            'name' => (string) $file['name'],
            'mime_type' => (string) $file['mime_type'],
            'size' => (int) filesize($actual),
        ];
    }

    /** @param array<string,mixed> $file @return array{name:string,size:int,mime_type:string,extension:string,sha256:string,temporary_path:string} */
    private function inspectUpload(array $file, int $maxBytes): array
    {
        if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Файл не загружен полностью');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || !is_readable($tmp) || is_link($tmp)) {
            throw new InvalidArgumentException('Некорректный временный файл');
        }
        if ($this->moveFile === null && !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Загрузка не подтверждена PHP');
        }

        $size = (int) filesize($tmp);
        if ($size < 1 || $size > $maxBytes || $size !== (int) ($file['size'] ?? -1)) {
            throw new InvalidArgumentException('Размер файла превышает установленный лимит');
        }
        $original = str_replace('\\', '/', (string) ($file['name'] ?? ''));
        $original = trim(basename($original));
        if ($original === '' || mb_strlen($original) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $original)) {
            throw new InvalidArgumentException('Недопустимое имя файла');
        }
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(FileUploadTypes::ALLOWED_UPLOADS[$extension])) {
            throw new InvalidArgumentException('Этот тип файла запрещён');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, FileUploadTypes::ALLOWED_UPLOADS[$extension], true)) {
            throw new InvalidArgumentException('Расширение файла не соответствует содержимому');
        }
        $hash = hash_file('sha256', $tmp);
        if (!is_string($hash)) {
            throw new RuntimeException('Не удалось проверить содержимое загруженного файла');
        }
        return [
            'name' => $original,
            'size' => $size,
            'mime_type' => $mime,
            'extension' => $extension,
            'sha256' => $hash,
            'temporary_path' => $tmp,
        ];
    }

    private function lockContainer(int $spaceId, ?int $parentId): int
    {
        if ($parentId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT version FROM file_spaces WHERE id=:id AND is_archived=0 FOR UPDATE'
            );
            $stmt->execute([':id' => $spaceId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT version FROM file_space_nodes
                 WHERE id=:id AND space_id=:space_id AND node_type="folder"
                   AND is_deleted=0 FOR UPDATE'
            );
            $stmt->execute([':id' => $parentId, ':space_id' => $spaceId]);
        }
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new DomainException('Папка не найдена', 404);
        }
        return (int) $version;
    }

    private function bumpContainer(int $spaceId, ?int $parentId, int $version): int
    {
        $stmt = $this->pdo->prepare(
            $parentId === null
                ? 'UPDATE file_spaces SET version=version+1 WHERE id=:id AND version=:version'
                : 'UPDATE file_space_nodes SET version=version+1 WHERE id=:id AND version=:version'
        );
        $stmt->execute([':id' => $parentId ?? $spaceId, ':version' => $version]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Изменения каталога не были зафиксированы');
        }
        return $version + 1;
    }

    private function assertFreeName(int $spaceId, ?int $parentId, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM file_space_nodes WHERE space_id=:space_id AND is_deleted=0
             AND name=:name AND '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id=:parent_id') . ' LIMIT 1'
        );
        $params = [':space_id' => $spaceId, ':name' => $name];
        if ($parentId !== null) {
            $params[':parent_id'] = $parentId;
        }
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('В этом каталоге уже есть объект с таким именем');
        }
    }

    private function directory(bool $create): string
    {
        $configured = $this->storageRoot;
        if ($configured === null) {
            $fromEnv = getenv('PRIVATE_STORAGE_PATH');
            $configured = is_string($fromEnv) && trim($fromEnv) !== ''
                ? trim($fromEnv) : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage';
        }
        $base = rtrim($configured, DIRECTORY_SEPARATOR);
        if ($base === '' || !str_starts_with($base, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $base) !== 1) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH должен быть абсолютным путём');
        }
        if ($create && !is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Не удалось создать приватное хранилище');
        }
        $baseReal = realpath($base);
        if ($baseReal === false || is_link($base)) {
            throw new RuntimeException('Недоступен безопасный корень хранилища');
        }
        $path = $baseReal . DIRECTORY_SEPARATOR . 'file_spaces' . DIRECTORY_SEPARATOR . 'global';
        if ($create && !is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать пространство файлов');
        }
        $actual = realpath($path);
        if ($actual === false || !str_starts_with($actual, $baseReal . DIRECTORY_SEPARATOR)
            || is_link($baseReal . DIRECTORY_SEPARATOR . 'file_spaces')
            || is_link($path)) {
            throw new RuntimeException('Недопустимое расположение общего хранилища');
        }
        return $actual;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $result */
    private function finish(array $receipt, array $result, int $version, string $status): void
    {
        $this->commands->complete(
            $this->pdo, (int) $receipt['record']['id'], $receipt['token'],
            $status, $result, $version
        );
    }
}
