<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/AdminBackupFiles.php';
require_once dirname(__DIR__, 3) . '/core/UpdateBackupManager.php';
require_once dirname(__DIR__, 3) . '/core/UpdateDatabaseRestorer.php';
require_once dirname(__DIR__, 3) . '/core/PrivateStorageResolver.php';
require_once dirname(__DIR__, 3) . '/core/Version.php';

use Core\PrivateStorageResolver;
use Core\PrefixMysqli;
use Core\UpdateBackupManager;
use Core\UpdateDatabaseRestorer;
use Core\UpdatePath;
use Core\Version;
use RuntimeException;
use Throwable;

/**
 * Операторские копии отделены от точек отката обновлятора.
 * Ни один произвольный путь и загруженный SQL не принимаются из HTTP.
 */
final class AdminBackupService
{
    private string $applicationRoot;
    private string $privateRoot;
    private string $backupRoot;
    private UpdateBackupManager $manager;
    private AdminBackupFiles $files;
    private ?AdminBackupFiles $legacyFiles = null;

    public function __construct(?string $applicationRoot = null, ?string $privateRoot = null)
    {
        $app = realpath($applicationRoot ?? dirname(__DIR__, 3));
        if ($app === false) {
            throw new RuntimeException('Корневой каталог приложения недоступен');
        }
        $this->applicationRoot = UpdatePath::normalize($app);
        $this->privateRoot = $privateRoot === null
            ? (new PrivateStorageResolver($app))->prepare()
            : $privateRoot;
        $private = realpath($this->privateRoot);
        if ($private === false || is_link($this->privateRoot)
            || UpdatePath::inside($private, $app)) {
            throw new RuntimeException('Для резервных копий требуется внешнее приватное хранилище');
        }
        $this->privateRoot = UpdatePath::normalize($private);
        $this->backupRoot = $this->privateRoot . '/admin-backups';
        $this->manager = new UpdateBackupManager($this->backupRoot, $app, [$private]);
        $this->files = new AdminBackupFiles($this->privateRoot);
        $legacyRoot = $this->applicationRoot . '/uploads';
        if (is_link($legacyRoot)) {
            throw new RuntimeException('Публичный каталог старых вложений не может быть ссылкой');
        }
        if (is_dir($legacyRoot)) {
            $this->legacyFiles = new AdminBackupFiles($legacyRoot);
        }
    }

    /** @return list<array{id:string,created_at:int,version:string,files:int,bytes:int}> */
    public function list(): array
    {
        $items = scandir($this->backupRoot);
        if ($items === false) {
            throw new RuntimeException('Не удалось просмотреть резервные копии');
        }
        $result = [];
        foreach ($items as $id) {
            if (!$this->validId($id)) continue;
            $manifest = $this->readOperatorManifest($id, false);
            if ($manifest === null) continue;
            $result[] = [
                'id' => $id,
                'created_at' => (int) $manifest['created_at'],
                'version' => (string) $manifest['version'],
                'files' => count($manifest['files']),
                'bytes' => array_sum(array_column($manifest['files'], 'size'))
                    + array_sum(array_column($manifest['legacy_files'] ?? [], 'size'))
                    + (int) ($manifest['database_bytes'] ?? 0),
            ];
        }
        usort($result, static fn (array $a, array $b): int =>
            $b['created_at'] <=> $a['created_at']);
        return $result;
    }

    /** @return array<string,mixed> */
    public function create(): array
    {
        $id = 'operator-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
        $maintenance = $this->maintenance();
        $maintenance->enter($id, 'Создание резервной копии администратором');
        try {
            return $this->createInsideMaintenance($id);
        } finally {
            $maintenance->leave($id);
        }
    }

    /** @return array<string,mixed> */
    public function verify(string $id): array
    {
        $manifest = $this->readOperatorManifest($id);
        $directory = $this->backupRoot . '/' . $id;
        $snapshot = $this->manager->verify($directory, $id);
        $hash = hash_file('sha256', $directory . '/backup.json');
        if (!is_string($hash)
            || !hash_equals((string) $manifest['backup_sha256'], $hash)) {
            throw new RuntimeException('Реестр копии не совпадает со снимком базы данных');
        }
        $this->files->verify($directory . '/private', $manifest['files']);
        if ($this->legacyFiles !== null && !is_array($manifest['legacy_files'] ?? null)) {
            throw new RuntimeException('В резервной копии отсутствуют старые вложения');
        }
        if ($this->legacyFiles !== null) {
            $this->legacyFiles->verify($directory . '/legacy-uploads', $manifest['legacy_files']);
        } elseif (!empty($manifest['legacy_files'])) {
            throw new RuntimeException('Каталог старых вложений отсутствует в этой установке');
        }
        if ((string) $manifest['application_root'] !== $this->applicationRoot
            || (string) $manifest['private_root'] !== $this->privateRoot) {
            throw new RuntimeException('Копия принадлежит другой установке');
        }
        return ['id' => $id, 'manifest' => $manifest, 'database' => $snapshot['database']];
    }

    /**
     * Запускается только после повторного подтверждения в контроллере.
     * При сбое после начала изменений пытается восстановить защитную копию.
     *
     * @return array{id:string,safety_id:string}
     */
    public function restore(string $id): array
    {
        $source = $this->verify($id);
        $this->assertCompatible($source);
        $transaction = 'restore-' . bin2hex(random_bytes(10));
        $maintenance = $this->maintenance();
        $maintenance->enter($transaction, 'Восстановление данных из резервной копии');

        $safety = null;
        $changed = false;
        $recovered = true;
        $stage = null;
        $legacyStage = null;
        try {
            // Повторно проверяем источник уже после блокировки изменения данных.
            $source = $this->verify($id);
            $this->assertCompatible($source);
            $safety = $this->createInsideMaintenance(
                'safety-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8))
            );
            $stage = $this->files->prepareRestore(
                $this->backupRoot . '/' . $id . '/private',
                $source['manifest']['files'],
                bin2hex(random_bytes(8))
            );

            if ($this->legacyFiles !== null) {
                $legacyStage = $this->legacyFiles->prepareRestore(
                    $this->backupRoot . '/' . $id . '/legacy-uploads',
                    $source['manifest']['legacy_files'],
                    bin2hex(random_bytes(8))
                );
            }

            // С этого места вмешательство в рабочую БД возможно.
            $changed = true;
            $this->restoreDatabase($source);
            $this->files->applyPrepared(
                $stage,
                $source['manifest']['files'],
                bin2hex(random_bytes(8))
            );
            $stage = null;
            if ($this->legacyFiles !== null && $legacyStage !== null) {
                $this->legacyFiles->applyPrepared(
                    $legacyStage,
                    $source['manifest']['legacy_files'],
                    bin2hex(random_bytes(8))
                );
                $legacyStage = null;
            }
            return ['id' => $id, 'safety_id' => $safety['id']];
        } catch (Throwable $error) {
            if ($changed && $safety !== null) {
                $recovered = false;
                try {
                    $safe = $this->verify($safety['id']);
                    $this->restoreDatabase($safe);
                    $fallback = $this->files->prepareRestore(
                        $this->backupRoot . '/' . $safety['id'] . '/private',
                        $safe['manifest']['files'],
                        bin2hex(random_bytes(8))
                    );
                    $this->files->applyPrepared(
                        $fallback,
                        $safe['manifest']['files'],
                        bin2hex(random_bytes(8))
                    );
                    if ($this->legacyFiles !== null) {
                        $legacyFallback = $this->legacyFiles->prepareRestore(
                            $this->backupRoot . '/' . $safety['id'] . '/legacy-uploads',
                            $safe['manifest']['legacy_files'],
                            bin2hex(random_bytes(8))
                        );
                        $this->legacyFiles->applyPrepared(
                            $legacyFallback, $safe['manifest']['legacy_files'],
                            bin2hex(random_bytes(8))
                        );
                    }
                    $recovered = true;
                } catch (Throwable $rollbackError) {
                    error_log('Критическая ошибка отката резервной копии: ' . $rollbackError->getMessage());
                }
            }
            if (!$recovered) {
                throw new RuntimeException(
                    'Восстановление прервано; защитный откат не завершён. '
                    . 'Система оставлена в обслуживании, требуется ручное восстановление'
                );
            }
            throw $error;
        } finally {
            if ($stage !== null && is_dir($stage)) {
                UpdatePath::removeTree($stage);
            }
            if ($legacyStage !== null && is_dir($legacyStage)) {
                UpdatePath::removeTree($legacyStage);
            }
            if ($recovered) {
                $maintenance->leave($transaction);
            }
        }
    }

    /** @return array<string,mixed> */
    private function createInsideMaintenance(string $id): array
    {
        $db = $this->database();
        try {
            $snapshot = $this->manager->create($id, $db);
            $directory = $snapshot['backup_dir'];
            $files = $this->files->snapshot($directory . '/private');
            $legacyFiles = $this->legacyFiles?->snapshot($directory . '/legacy-uploads') ?? [];
            $manifest = [
                'schema' => 1,
                'id' => $id,
                'created_at' => time(),
                'version' => Version::VERSION,
                'version_code' => Version::VERSION_CODE,
                'application_root' => $this->applicationRoot,
                'private_root' => $this->privateRoot,
                'database_bytes' => (int) $snapshot['database']['bytes'],
                'table_prefix' => (string) $snapshot['database']['table_prefix'],
                'backup_sha256' => hash_file('sha256', $directory . '/backup.json'),
                'files' => $files,
                'legacy_files' => $legacyFiles,
            ];
            $this->writeManifest($directory, $manifest);
            $this->verify($id);
            return ['id' => $id, 'manifest' => $manifest];
        } finally {
            $db->close();
        }
    }

    /** @param array<string,mixed> $source */
    private function assertCompatible(array $source): void
    {
        $manifest = $source['manifest'];
        if ((string) $manifest['version'] !== Version::VERSION
            || (int) $manifest['version_code'] !== Version::VERSION_CODE) {
            throw new RuntimeException('Версия резервной копии отличается от установленной');
        }
        $db = $this->database();
        try {
            $meta = $source['database'];
            if ((string) $meta['table_prefix'] !== $db->namespace->prefix) {
                throw new RuntimeException('Префикс таблиц не совпадает с текущей установкой');
            }
            $expected = array_column($meta['table_metadata'], 'name');
            sort($expected);
            $query = $db->query(
                'SELECT TABLE_NAME FROM information_schema.tables '
                . 'WHERE table_schema=DATABASE() AND table_type="BASE TABLE" ORDER BY TABLE_NAME'
            );
            $actual = [];
            while ($row = $query->fetch_assoc()) {
                $actual[] = (string) $row['TABLE_NAME'];
            }
            sort($actual);
            if ($actual !== $expected) {
                throw new RuntimeException('Схема текущей БД не совпадает с резервной копией');
            }
        } finally {
            $db->close();
        }
    }

    /** @param array<string,mixed> $source */
    private function restoreDatabase(array $source): void
    {
        $db = $this->database();
        try {
            (new UpdateDatabaseRestorer())->restore(
                $db, $this->backupRoot . '/' . $source['id'], $source['database']
            );
        } finally {
            $db->close();
        }
    }

    private function database(): PrefixMysqli
    {
        $db = new PrefixMysqli(
            (string) (getenv('DBHOST') ?: 'localhost'),
            (string) (getenv('DBUSER') ?: ''),
            (string) (getenv('DBPASS') ?: ''),
            (string) (getenv('DBNAME') ?: ''),
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');
        // В общей базе допускаются только таблицы этой установки.
        // Неизвестные триггеры запрещают операторское восстановление.
        $triggers = $db->query('SHOW TRIGGERS');
        while ($row = $triggers->fetch_assoc()) {
            if (!$db->namespace->ownsTable((string) ($row['Table'] ?? ''))) {
                $db->close();
                throw new RuntimeException('Обнаружены сторонние триггеры; восстановление требует изолированной БД');
            }
        }
        return $db;
    }

    /** @return array<string,mixed>|null */
    private function readOperatorManifest(string $id, bool $strict = true): ?array
    {
        if (!$this->validId($id)) {
            throw new RuntimeException('Некорректный идентификатор резервной копии');
        }
        $directory = $this->backupRoot . '/' . $id;
        $path = $directory . '/operator.json';
        if (!is_dir($directory) || is_link($directory) || is_link($path) || !is_file($path)) {
            if (!$strict) return null;
            throw new RuntimeException('Резервная копия отсутствует или не завершена');
        }
        $raw = file_get_contents($path, false, null, 0, 16777217);
        if (!is_string($raw) || strlen($raw) > 16777216) {
            throw new RuntimeException('Реестр резервной копии слишком велик');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 1
            || ($manifest['id'] ?? null) !== $id || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Реестр резервной копии повреждён');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(string $directory, array $manifest): void
    {
        $path = $directory . '/operator.json';
        $tmp = $directory . '/.operator-' . bin2hex(random_bytes(6));
        $bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $old = umask(0077);
        $written = file_put_contents($tmp, $bytes, LOCK_EX);
        umask($old);
        if ($written !== strlen($bytes) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось опубликовать реестр резервной копии');
        }
        @chmod($path, 0600);
    }

    private function validId(string $id): bool
    {
        return preg_match('/^(?:operator|safety)-[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/D', $id) === 1;
    }

    private function maintenance(): \App\Services\MaintenanceModeService
    {
        $configured = trim((string) (getenv('UPDATE_STATE_PATH') ?: ''));
        $privateConfigured = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
        if ($configured === '' && $privateConfigured === '') {
            throw new RuntimeException(
                'Не задан общий режим обслуживания. Настройте PRIVATE_STORAGE_PATH или UPDATE_STATE_PATH'
            );
        }
        $stateRoot = $configured !== '' ? $configured : rtrim($privateConfigured, '/\\') . '/updates';
        return new MaintenanceModeService($stateRoot, $this->applicationRoot);
    }
}
