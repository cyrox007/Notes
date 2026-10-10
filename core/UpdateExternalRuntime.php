<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/PrivateStorageResolver.php';
require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/Version.php';

/**
 * Подготавливает независимый runtime destructive-фазы вне live-tree.
 *
 * В runtime попадает только замкнутое множество файлов, нужных для
 * UpdateApplyCommand и recovery. Копия повторно проверяется по SHA-256 до
 * запуска. Сам класс никогда не меняет live-файлы приложения.
 */
final class UpdateExternalRuntime
{
    private const SCHEMA = 1;

    /** @var list<string> */
    private const FILES = [
        'bin/update_external_apply.php',
        'bin/update_web_entry.php',
        'config/update_trusted_keys.php',
        'core/UpdateWebTransaction.php',
        'core/UpdateWebRuntimeLauncher.php',
        'app/services/MaintenanceModeService.php',
        'core/Environment.php',
        'core/SchemaReadiness.php',
        'core/WebSocketEndpoint.php',
        'core/HostingCompatibility.php',
        'core/Version.php',
        'core/UpdatePath.php',
        'core/UpdateStepBudget.php',
        'core/UpdateStepCheckpoint.php',
        'core/UpdateFileMutator.php',
        'core/UpdateTransactionJournal.php',
        'core/UpdateTransactionStateMachine.php',
        'core/UpdateBackupManager.php',
        'core/UpdateLiveApplier.php',
        'core/UpdateApplyOperationLock.php',
        'core/UpdateRollbackCodeRestorer.php',
        'core/UpdateApplyCommand.php',
        'core/SecurityEventLog.php',
        'core/ServiceLog.php',
        'core/MigrationManifest.php',
        'core/MigrationBaseline.php',
        'core/UpdateMigrationPreflight.php',
        'core/UpdateCommandRunner.php',
        'core/UpdateProcessRunner.php',
        'core/UpdateCandidateVerifier.php',
        'core/UpdateCodeSwitcher.php',
        'core/UpdateDatabaseRestorer.php',
        'core/UpdateDatabaseRestoreSteps.php',
        'core/ModuleManifest.php',
        'core/DatabaseOwnership.php',
    ];

    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $resolved = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($appRoot ?? $resolved)) {
            throw new RuntimeException('Не удалось определить корень приложения для внешнего updater runtime');
        }

        $this->appRoot = UpdatePath::normalize($resolved);
    }

    /**
     * Повторно проверяет runtime по метаданным journal. Live-код при этом
     * не используется как источник истины: после destructive boundary он
     * может быть частично переключён.
     *
     * @param array<string,mixed> $recorded
     * @return array{
     *   runtime_root:string,
     *   entrypoint:string,
     *   manifest:string,
     *   manifest_sha256:string,
     *   source_version:string,
     *   source_version_code:int,
     *   files:int
     * }
     */
    public function verifyRecorded(array $recorded, int $expectedSourceVersionCode): array
    {
        foreach (['runtime_root', 'entrypoint', 'manifest', 'manifest_sha256'] as $key) {
            if (!is_string($recorded[$key] ?? null) || trim((string) $recorded[$key]) === '') {
                throw new RuntimeException("Journal не содержит {$key} внешнего updater runtime");
            }
        }

        $runtimeRoot = (string) $recorded['runtime_root'];
        $manifestPath = (string) $recorded['manifest'];
        $manifestReal = realpath($manifestPath);
        $runtimeReal = realpath($runtimeRoot);
        if (!is_string($runtimeReal) || !is_dir($runtimeReal) || is_link($runtimeRoot)) {
            throw new RuntimeException('Записанный внешний updater runtime недоступен');
        }
        if (!is_string($manifestReal) || !is_file($manifestReal) || is_link($manifestPath)) {
            throw new RuntimeException('Записанный manifest внешнего updater runtime недоступен');
        }

        $runtimeReal = UpdatePath::normalize($runtimeReal);
        $manifestReal = UpdatePath::normalize($manifestReal);
        if (!UpdatePath::inside($manifestReal, $runtimeReal)) {
            throw new RuntimeException('Manifest вышел за границу записанного внешнего updater runtime');
        }

        $bytes = file_get_contents($manifestReal);
        $actualSha = hash_file('sha256', $manifestReal);
        $recordedSha = strtolower(trim((string) $recorded['manifest_sha256']));
        if (!is_string($bytes)
            || !is_string($actualSha)
            || preg_match('/^[0-9a-f]{64}$/D', $recordedSha) !== 1
            || !hash_equals($recordedSha, $actualSha)) {
            throw new RuntimeException('Записанный manifest внешнего updater runtime не прошёл SHA-256');
        }

        $verified = $this->verifyRuntime($runtimeReal, $bytes, $actualSha);
        if ((int) $verified['source_version_code'] !== $expectedSourceVersionCode) {
            throw new RuntimeException('Внешний updater runtime относится к другой исходной версии транзакции');
        }
        if (!hash_equals(
            UpdatePath::normalize((string) $recorded['entrypoint']),
            UpdatePath::normalize((string) $verified['entrypoint'])
        )) {
            throw new RuntimeException('Journal указывает на другой entrypoint внешнего updater runtime');
        }

        return $verified;
    }

    /**
     * @return array{
     *   runtime_root:string,
     *   entrypoint:string,
     *   manifest:string,
     *   manifest_sha256:string,
     *   source_version:string,
     *   source_version_code:int,
     *   files:int
     * }
     */
    public function prepare(): array
    {
        $private = (new PrivateStorageResolver($this->appRoot))->prepareAndPublish();
        $runtimeParent = rtrim($private, '/\\')
            . DIRECTORY_SEPARATOR . 'updates'
            . DIRECTORY_SEPARATOR . 'runtime';
        $this->ensureExternalDirectory($runtimeParent);

        $manifest = $this->sourceManifest();
        $manifestBytes = $this->manifestBytes($manifest);
        $manifestSha = hash('sha256', $manifestBytes);
        $runtimeId = Version::VERSION_CODE . '-' . substr($manifestSha, 0, 16);
        $final = $runtimeParent . DIRECTORY_SEPARATOR . 'source-' . $runtimeId;

        if (is_dir($final) && !is_link($final)) {
            return $this->verifyRuntime($final, $manifestBytes, $manifestSha);
        }
        if (file_exists($final) || is_link($final)) {
            throw new RuntimeException('Путь внешнего updater runtime занят небезопасным объектом');
        }

        $temporary = $runtimeParent . DIRECTORY_SEPARATOR . '.runtime-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0077);
        $created = @mkdir($temporary, 0700, false);
        umask($oldUmask);
        if (!$created || !is_dir($temporary)) {
            throw new RuntimeException('Не удалось создать временный внешний updater runtime');
        }

        try {
            foreach ($manifest['files'] as $relative => $metadata) {
                $source = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $target = $temporary . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->copyVerified(
                    $source,
                    $target,
                    (int) $metadata['size'],
                    (string) $metadata['sha256']
                );
            }

            $manifestPath = $temporary . DIRECTORY_SEPARATOR . 'runtime.json';
            $this->writeExclusive($manifestPath, $manifestBytes);

            if (!@rename($temporary, $final)) {
                throw new RuntimeException('Не удалось атомарно опубликовать внешний updater runtime');
            }

            return $this->verifyRuntime($final, $manifestBytes, $manifestSha);
        } catch (Throwable $e) {
            if (is_dir($temporary) && !is_link($temporary)) {
                UpdatePath::removeTree($temporary);
            }
            throw $e;
        }
    }

    /**
     * @return array{schema:int,source_version:string,source_version_code:int,files:array<string,array{size:int,sha256:string}>}
     */
    private function sourceManifest(): array
    {
        $files = [];

        $pending = self::FILES;
        for ($index = 0; $index < count($pending); ++$index) {
            $relative = $pending[$index];
            if (isset($files[$relative])) continue;
            if (!UpdatePath::safeRelative($relative)) {
                throw new RuntimeException('Список внешнего updater runtime содержит небезопасный путь');
            }

            $path = $this->appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || is_link($path) || !is_readable($path)) {
                throw new RuntimeException('Не найден обязательный файл внешнего updater runtime: ' . $relative);
            }

            $size = filesize($path);
            $sha = hash_file('sha256', $path);
            if (!is_int($size) || $size < 1 || !is_string($sha)) {
                throw new RuntimeException('Не удалось проверить файл внешнего updater runtime: ' . $relative);
            }

            $files[$relative] = ['size' => $size, 'sha256' => $sha];
            $source = file_get_contents($path);
            if (!is_string($source)) throw new RuntimeException('Не удалось прочитать runtime: ' . $relative);
            foreach ($this->literalDependencies($relative, $source) as $dependency) {
                if (!isset($files[$dependency])) $pending[] = $dependency;
            }
        }

        $this->assertDependencyClosure(array_keys($files));
        ksort($files, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'source_version' => Version::VERSION,
            'source_version_code' => Version::VERSION_CODE,
            'files' => $files,
        ];
    }

    /**
     * Проверяет замкнутость локальных PHP-зависимостей recovery-runtime до
     * публикации его копии. Ошибка состава runtime должна остановить обновление
     * до destructive-фазы, а не проявиться уже во время rollback.
     *
     * @param list<string> $files
     */
    private function assertDependencyClosure(array $files): void
    {
        $known = array_fill_keys($files, true);

        foreach ($files as $relative) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $path = $this->appRoot
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $source = file_get_contents($path);
            if (!is_string($source)) {
                throw new RuntimeException(
                    'Не удалось проверить зависимости внешнего updater runtime: ' . $relative
                );
            }

            foreach ($this->literalDependencies($relative, $source) as $dependency) {
                if (isset($known[$dependency])) {
                    continue;
                }

                throw new RuntimeException(
                    'Внешний updater runtime не замкнут: '
                    . $relative
                    . ' требует '
                    . $dependency
                );
            }
        }
    }

    /** @return list<string> */
    private function literalDependencies(string $relative, string $source): array
    {
        $dependencies = [];

        if (preg_match_all(
            '~\b(?:require_once|require)\s+__DIR__\s*\.\s*[\'"]/([^\'"]+\.php)[\'"]\s*;~',
            $source,
            $sameDirectoryMatches
        ) === false) {
            throw new RuntimeException('Не удалось разобрать зависимости updater runtime');
        }

        foreach ($sameDirectoryMatches[1] ?? [] as $suffix) {
            $dependencies[] = $this->resolveDependency($relative, (string) $suffix, 0);
        }

        if (preg_match_all(
            '~\b(?:require_once|require)\s+dirname\(\s*__DIR__\s*(?:,\s*(\d+)\s*)?\)'
                . '\s*\.\s*[\'"]/([^\'"]+\.php)[\'"]\s*;~',
            $source,
            $parentMatches,
            PREG_SET_ORDER
        ) === false) {
            throw new RuntimeException('Не удалось разобрать родительские зависимости updater runtime');
        }

        foreach ($parentMatches as $match) {
            $levels = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 1;
            $dependencies[] = $this->resolveDependency(
                $relative,
                (string) ($match[2] ?? ''),
                max(1, $levels)
            );
        }

        if (preg_match_all(
            '~\b(?:require_once|require)\s+\$runtimeReal\s*\.\s*[\'"]/([^\'"]+\.php)[\'"]\s*;~',
            $source,
            $runtimeRootMatches
        ) === false) {
            throw new RuntimeException('Не удалось разобрать зависимости entrypoint updater runtime');
        }

        foreach ($runtimeRootMatches[1] ?? [] as $suffix) {
            $candidate = ltrim(str_replace('\\', '/', (string) $suffix), '/');
            if (!UpdatePath::safeRelative($candidate)) {
                throw new RuntimeException('Entrypoint updater runtime содержит небезопасную зависимость');
            }
            $dependencies[] = $candidate;
        }

        return array_values(array_unique($dependencies));
    }

    private function resolveDependency(string $sourceRelative, string $suffix, int $parentLevels): string
    {
        $sourceParts = explode('/', str_replace('\\', '/', $sourceRelative));
        array_pop($sourceParts);

        for ($level = 0; $level < $parentLevels; $level++) {
            if ($sourceParts === []) {
                throw new RuntimeException(
                    'Зависимость updater runtime вышла выше корня приложения: ' . $sourceRelative
                );
            }
            array_pop($sourceParts);
        }

        foreach (explode('/', str_replace('\\', '/', ltrim($suffix, '/'))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($sourceParts === []) {
                    throw new RuntimeException(
                        'Зависимость updater runtime вышла выше корня приложения: ' . $sourceRelative
                    );
                }
                array_pop($sourceParts);
                continue;
            }

            $sourceParts[] = $part;
        }

        $candidate = implode('/', $sourceParts);
        if (!UpdatePath::safeRelative($candidate)) {
            throw new RuntimeException(
                'Updater runtime содержит небезопасную локальную зависимость: ' . $candidate
            );
        }

        return $candidate;
    }

    /** @param array<string,mixed> $manifest */
    private function manifestBytes(array $manifest): string
    {
        return json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }

    /**
     * @return array{
     *   runtime_root:string,
     *   entrypoint:string,
     *   manifest:string,
     *   manifest_sha256:string,
     *   source_version:string,
     *   source_version_code:int,
     *   files:int
     * }
     */
    private function verifyRuntime(string $runtimeRoot, string $expectedManifest, string $expectedManifestSha): array
    {
        $real = realpath($runtimeRoot);
        if (!is_string($real) || !is_dir($real) || is_link($runtimeRoot)) {
            throw new RuntimeException('Внешний updater runtime недоступен для проверки');
        }

        $real = UpdatePath::normalize($real);
        if (UpdatePath::inside($real, $this->appRoot) || UpdatePath::inside($this->appRoot, $real)) {
            throw new RuntimeException('Внешний updater runtime оказался внутри live-tree приложения');
        }

        $manifestPath = $real . DIRECTORY_SEPARATOR . 'runtime.json';
        $bytes = is_file($manifestPath) && !is_link($manifestPath)
            ? file_get_contents($manifestPath)
            : false;
        if (!is_string($bytes) || !hash_equals($expectedManifest, $bytes)) {
            throw new RuntimeException('Manifest внешнего updater runtime не совпадает с исходным кодом');
        }

        $manifestSha = hash_file('sha256', $manifestPath);
        if (!is_string($manifestSha) || !hash_equals($expectedManifestSha, $manifestSha)) {
            throw new RuntimeException('SHA-256 manifest внешнего updater runtime не совпадает');
        }

        $manifest = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Manifest внешнего updater runtime повреждён');
        }

        foreach ($manifest['files'] as $relative => $metadata) {
            if (!is_string($relative) || !is_array($metadata) || !UpdatePath::safeRelative($relative)) {
                throw new RuntimeException('Manifest внешнего updater runtime содержит некорректный файл');
            }

            $path = $real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $sha = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (
                !is_int($size)
                || $size !== (int) ($metadata['size'] ?? -1)
                || !is_string($sha)
                || !hash_equals((string) ($metadata['sha256'] ?? ''), $sha)
            ) {
                throw new RuntimeException('Проверка внешнего updater runtime не пройдена: ' . $relative);
            }
        }

        $entrypoint = $real . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'update_external_apply.php';
        if (!is_file($entrypoint) || is_link($entrypoint)) {
            throw new RuntimeException('Внешний updater runtime не содержит entrypoint');
        }

        return [
            'runtime_root' => $real,
            'entrypoint' => $entrypoint,
            'manifest' => $manifestPath,
            'manifest_sha256' => $manifestSha,
            'source_version' => (string) ($manifest['source_version'] ?? ''),
            'source_version_code' => (int) ($manifest['source_version_code'] ?? 0),
            'files' => count($manifest['files']),
        ];
    }

    private function ensureExternalDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Каталог внешнего updater runtime не должен быть symlink');
        }

        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $created = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($path)) {
                throw new RuntimeException('Не удалось создать каталог внешнего updater runtime');
            }
        }

        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Каталог внешнего updater runtime недоступен на запись');
        }

        $real = UpdatePath::normalize($real);
        if (UpdatePath::inside($real, $this->appRoot) || UpdatePath::inside($this->appRoot, $real)) {
            throw new RuntimeException('Каталог внешнего updater runtime должен находиться вне live-tree');
        }

        @chmod($real, 0700);
    }

    private function copyVerified(string $source, string $target, int $expectedSize, string $expectedSha): void
    {
        $parent = dirname($target);
        if (!is_dir($parent)) {
            $oldUmask = umask(0077);
            $created = @mkdir($parent, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($parent)) {
                throw new RuntimeException('Не удалось создать каталог файла внешнего updater runtime');
            }
        }

        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Не удалось скопировать файл внешнего updater runtime');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $expectedSize || !fflush($output)) {
                throw new RuntimeException('Копирование внешнего updater runtime завершилось не полностью');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        $sha = hash_file('sha256', $target);
        if (!is_string($sha) || !hash_equals($expectedSha, $sha)) {
            @unlink($target);
            throw new RuntimeException('SHA-256 копии внешнего updater runtime не совпадает');
        }

        @chmod($target, 0600);
    }

    private function writeExclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось создать manifest внешнего updater runtime');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Не удалось полностью записать manifest внешнего updater runtime');
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);
    }
}
