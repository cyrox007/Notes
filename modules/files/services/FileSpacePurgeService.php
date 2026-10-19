<?php

declare(strict_types=1);

namespace App\Services\Files;

use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Окончательная очистка общего пространства после срока хранения.
 *
 * Файл сначала перемещается в карантин на том же диске. Запись о подготовке
 * фиксируется до перемещения, чтобы следующий запуск мог вернуть данные
 * при аварийном завершении процесса. Стирание карантина разрешено только
 * после фиксации удаления метаданных в БД.
 */
final class FileSpacePurgeService
{
    private const int MAX_NODES_PER_ROOT = 500;
    private const string LOCK_KEY = 'notes.filespaces.purge.v1';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $storageRoot = null,
        private readonly ?\Closure $afterStage = null
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function candidates(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('За один запуск можно выбрать от 1 до 50 корневых объектов');
        }
        $stmt = $this->pdo->prepare(
            "SELECT n.id,n.uid,n.name,n.deleted_at,s.trash_retention_days
             FROM file_space_nodes n
             JOIN file_spaces f ON f.id=n.space_id AND f.uid='global'
             JOIN file_space_settings s ON s.space_id=f.id
             LEFT JOIN file_space_nodes parent ON parent.id=n.parent_id
             WHERE n.is_deleted=1 AND n.deleted_at IS NOT NULL
               AND n.deleted_at <= DATE_SUB(NOW(), INTERVAL s.trash_retention_days DAY)
               AND (n.parent_id IS NULL OR parent.is_deleted=0)
             ORDER BY n.deleted_at,n.id LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{processed:int,files:int,bytes:int,remaining:int} */
    public function run(int $limit = 5): array
    {
        $this->lock();
        $processed = 0;
        $files = 0;
        $bytes = 0;
        try {
            $this->recover();
            foreach ($this->candidates($limit) as $candidate) {
                $result = $this->purgeRoot((int) $candidate['id']);
                if ($result === null) {
                    continue;
                }
                $processed++;
                $files += $result['files'];
                $bytes += $result['bytes'];
            }
            $this->recover();
            return [
                'processed' => $processed, 'files' => $files, 'bytes' => $bytes,
                'remaining' => count($this->candidates(50)),
            ];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_KEY . "')");
        }
    }

    /** @return array{files:int,bytes:int}|null */
    private function purgeRoot(int $rootId): ?array
    {
        $candidate = $this->eligibleRoot($rootId);
        if ($candidate === null) {
            return null;
        }
        $batch = bin2hex(random_bytes(16));
        $register = $this->pdo->prepare(
            "INSERT INTO file_space_purge_batches
               (uid,space_id,root_node_id,root_uid,status)
             VALUES (:uid,:space,:root,:root_uid,'preparing')"
        );
        $register->execute([
            ':uid' => $batch, ':space' => (int) $candidate['space_id'],
            ':root' => $rootId, ':root_uid' => (string) $candidate['uid'],
        ]);

        $this->pdo->beginTransaction();
        try {
            $spaceLock = $this->pdo->prepare('SELECT id FROM file_spaces WHERE id=:id FOR UPDATE');
            $spaceLock->execute([':id' => (int) $candidate['space_id']]);
            if ($this->eligibleRoot($rootId) === null) {
                $this->pdo->rollBack();
                $this->rollbackBatch($batch);
                return null;
            }

            $nodes = $this->subtree((int) $candidate['space_id'], $rootId);
            if ($nodes === [] || count($nodes) > self::MAX_NODES_PER_ROOT) {
                throw new RuntimeException('Дерево корзины слишком велико для одной безопасной операции');
            }
            $files = 0;
            $bytes = 0;
            $dir = $this->quarantine($batch, true);
            $origin = $this->storageDirectory();
            foreach ($nodes as $node) {
                if ($node['node_type'] !== 'file') {
                    continue;
                }
                $key = $this->safeKey((string) ($node['storage_key'] ?? ''));
                // Ошибка метаданных не должна позволить очистке удалить файл,
                // на который по-прежнему ссылается другой объект пространства.
                $references = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM file_space_nodes
                     WHERE space_id=:space AND storage_key=:key AND id<>:id'
                );
                $references->execute([
                    ':space' => (int) $candidate['space_id'],
                    ':key' => $key,
                    ':id' => (int) $node['id'],
                ]);
                if ((int) $references->fetchColumn() !== 0) {
                    throw new RuntimeException('Физический файл используется другим объектом');
                }
                $source = $origin . DIRECTORY_SEPARATOR . $key;
                $target = $dir . DIRECTORY_SEPARATOR . $key;
                if (!is_file($source) || is_link($source) || file_exists($target)
                    || !rename($source, $target)) {
                    throw new RuntimeException('Не удалось безопасно перенести файл в карантин: ' . $key);
                }
                $files++;
                $bytes += (int) $node['byte_size'];
            }
            if ($this->afterStage !== null) {
                ($this->afterStage)($batch);
            }

            // Правила файловых папок не имеют FK на узел и удаляются явно.
            $deleteAcl = $this->pdo->prepare(
                'DELETE FROM file_space_acl WHERE space_id=:space AND scope_key=:scope'
            );
            $deleteNode = $this->pdo->prepare(
                'DELETE FROM file_space_nodes WHERE id=:id AND space_id=:space'
            );
            foreach ($nodes as $node) {
                $id = (int) $node['id'];
                $deleteAcl->execute([
                    ':space' => (int) $candidate['space_id'],
                    ':scope' => 'node:' . $id,
                ]);
                $deleteNode->execute([':id' => $id, ':space' => (int) $candidate['space_id']]);
                if ($deleteNode->rowCount() !== 1) {
                    throw new RuntimeException('Не удалось завершить очистку дерева в базе данных');
                }
            }
            $audit = $this->pdo->prepare(
                'INSERT INTO file_space_events
                   (space_id,node_id,actor_user_id,event_code,details_json)
                 VALUES (:space,NULL,NULL,:event,:details)'
            );
            $audit->execute([
                ':space' => (int) $candidate['space_id'],
                ':event' => 'purge.commit',
                ':details' => json_encode([
                    'root_uid' => (string) $candidate['uid'],
                    'nodes' => count($nodes), 'files' => $files,
                    'bytes' => $bytes,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $complete = $this->pdo->prepare(
                "UPDATE file_space_purge_batches
                 SET status='committed',node_count=:nodes,file_count=:files,
                     bytes_total=:bytes,completed_at=NOW()
                 WHERE uid=:uid AND status='preparing'"
            );
            $complete->execute([
                ':nodes' => count($nodes), ':files' => $files,
                ':bytes' => $bytes, ':uid' => $batch,
            ]);
            if ($complete->rowCount() !== 1) {
                throw new RuntimeException('Не удалось зафиксировать журнал очистки');
            }
            $this->pdo->commit();
            return ['files' => $files, 'bytes' => $bytes];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->rollbackBatch($batch);
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function eligibleRoot(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.id,n.uid,n.space_id
             FROM file_space_nodes n
             JOIN file_spaces s ON s.id=n.space_id AND s.uid='global'
             JOIN file_space_settings policy ON policy.space_id=s.id
             LEFT JOIN file_space_nodes parent ON parent.id=n.parent_id
             WHERE n.id=:id AND n.is_deleted=1 AND n.deleted_at IS NOT NULL
               AND n.deleted_at <= DATE_SUB(NOW(), INTERVAL policy.trash_retention_days DAY)
               AND (n.parent_id IS NULL OR parent.is_deleted=0)"
        );
        $stmt->execute([':id' => $id]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($node) ? $node : null;
    }

    /** @return list<array<string,mixed>> Дочерние объекты расположены перед родителями. */
    private function subtree(int $spaceId, int $rootId): array
    {
        $stmt = $this->pdo->prepare(
            'WITH RECURSIVE tree AS (
                SELECT id,parent_id,node_type,storage_key,byte_size,0 AS depth
                FROM file_space_nodes WHERE id=:root AND space_id=:space
                UNION ALL
                SELECT c.id,c.parent_id,c.node_type,c.storage_key,c.byte_size,t.depth+1
                FROM file_space_nodes c JOIN tree t ON c.parent_id=t.id
                WHERE c.space_id=:child_space AND t.depth<64
             )
             SELECT * FROM tree ORDER BY depth DESC,id DESC LIMIT 502'
        );
        $stmt->execute([
            ':root' => $rootId, ':space' => $spaceId, ':child_space' => $spaceId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * При аварии до COMMIT метаданные остаются в БД.
     * Поэтому все файлы незавершённого карантина возвращаются на исходные места.
     */
    private function recover(): void
    {
        $stmt = $this->pdo->query(
            "SELECT uid,status FROM file_space_purge_batches
             WHERE status IN ('preparing','committed') ORDER BY id LIMIT 200"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $batch) {
            $uid = (string) $batch['uid'];
            if ($batch['status'] === 'preparing') {
                $this->rollbackBatch($uid);
                continue;
            }
            $this->finishCommitted($uid);
        }
    }

    private function rollbackBatch(string $uid): void
    {
        $folder = $this->quarantine($uid, false);
        if (is_dir($folder)) {
            $origin = $this->storageDirectory();
            foreach ($this->stagedKeys($folder) as $key) {
                $saved = $folder . DIRECTORY_SEPARATOR . $key;
                $target = $origin . DIRECTORY_SEPARATOR . $key;
                if (file_exists($target) || !rename($saved, $target)) {
                    throw new RuntimeException('Не удалось восстановить файл из карантина: ' . $key);
                }
            }
            if (!rmdir($folder)) {
                throw new RuntimeException('Не удалось завершить восстановление карантина');
            }
        }
        $stmt = $this->pdo->prepare(
            "UPDATE file_space_purge_batches
             SET status='rolled_back',completed_at=NOW() WHERE uid=:uid AND status='preparing'"
        );
        $stmt->execute([':uid' => $uid]);
    }

    private function finishCommitted(string $uid): void
    {
        $folder = $this->quarantine($uid, false);
        if (is_dir($folder)) {
            foreach ($this->stagedKeys($folder) as $key) {
                $path = $folder . DIRECTORY_SEPARATOR . $key;
                if (!unlink($path)) {
                    throw new RuntimeException('Не удалось стереть подтверждённый файл карантина');
                }
            }
            if (!rmdir($folder)) {
                throw new RuntimeException('Не удалось удалить каталог карантина');
            }
        }
        $stmt = $this->pdo->prepare(
            "UPDATE file_space_purge_batches SET status='finished'
             WHERE uid=:uid AND status='committed'"
        );
        $stmt->execute([':uid' => $uid]);
    }

    /** @return list<string> */
    private function stagedKeys(string $dir): array
    {
        $files = scandir($dir);
        if (!is_array($files)) {
            throw new RuntimeException('Не удалось прочитать каталог карантина');
        }
        $keys = [];
        foreach ($files as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }
            $this->safeKey($filename);
            if (!is_file($dir . DIRECTORY_SEPARATOR . $filename)
                || is_link($dir . DIRECTORY_SEPARATOR . $filename)) {
                throw new RuntimeException('В карантине обнаружен небезопасный объект');
            }
            $keys[] = $filename;
        }
        return $keys;
    }

    private function safeKey(string $key): string
    {
        if (preg_match('/^[a-f0-9]{48}\.[a-z0-9]{1,12}$/D', $key) !== 1) {
            throw new RuntimeException('Небезопасный ключ физического файла');
        }
        return $key;
    }

    private function quarantine(string $uid, bool $create): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1) {
            throw new RuntimeException('Неверный идентификатор карантина');
        }
        $base = $this->storageDirectory();
        $container = $base . DIRECTORY_SEPARATOR . '.purge';
        if ($create && !is_dir($container) && !mkdir($container, 0700)) {
            throw new RuntimeException('Не удалось создать защищённый карантин');
        }
        if (is_link($container)) {
            throw new RuntimeException('Ссылка вместо каталога карантина запрещена');
        }
        $folder = $container . DIRECTORY_SEPARATOR . $uid;
        if ($create && !is_dir($folder) && !mkdir($folder, 0700)) {
            throw new RuntimeException('Не удалось подготовить каталог операции очистки');
        }
        if (is_link($folder)) {
            throw new RuntimeException('Ссылка вместо каталога операции запрещена');
        }
        return $folder;
    }

    private function storageDirectory(): string
    {
        $fromEnv = getenv('PRIVATE_STORAGE_PATH');
        $root = $this->storageRoot ?? (
            is_string($fromEnv) && trim($fromEnv) !== ''
                ? trim($fromEnv) : dirname(SITEPATH) . DIRECTORY_SEPARATOR . 'notes-private-storage'
        );
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '' || (!str_starts_with($root, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $root) !== 1)) {
            throw new RuntimeException('Хранилище должно находиться по абсолютному пути');
        }
        $realRoot = realpath($root);
        if ($realRoot !== false) {
            FileSpaceStorageGuard::assertOutsideWebRoot($realRoot);
        }
        $path = $realRoot === false ? '' : $realRoot . '/file_spaces/global';
        $realDir = realpath($path);
        if ($realRoot === false || $realDir === false
            || is_link($root) || is_link($realRoot . '/file_spaces')
            || is_link($path) || !str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Приватное хранилище недоступно или содержит ссылки');
        }
        return $realDir;
    }

    private function lock(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:key,0)');
        $stmt->execute([':key' => self::LOCK_KEY]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Очистка уже выполняется в другом процессе');
        }
    }
}
