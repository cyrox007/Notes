<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/UpdateCandidateVerifier.php';
require_once __DIR__ . '/UpdateCodeSwitcher.php';
require_once __DIR__ . '/UpdateDatabaseRestorer.php';

use mysqli;
use RuntimeException;

/**
 * Транзакционный фасад updater.
 *
 * Публичный API для UpdateApplyCommand остаётся стабильным, а проверка
 * candidate/backup, пофайловое переключение кода и rollback БД разделены
 * между специализированными классами.
 */
final class UpdateLiveApplier
{
    /** @var list<string> */
    private const PRESERVED_ROOTS = [
        '.git',
        'vendor',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
        '.logs',
    ];

    private string $appRoot;
    private string $parentRoot;
    private UpdateCandidateVerifier $candidateVerifier;
    private UpdateCodeSwitcher $codeSwitcher;
    private UpdateDatabaseRestorer $databaseRestorer;

    public function __construct(?string $appRoot = null)
    {
        $app = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($app) || !is_dir($app) || is_link($app)) {
            throw new RuntimeException('Не удалось безопасно определить live-root приложения');
        }
        $this->appRoot = UpdatePath::normalize($app);

        $parent = realpath(dirname($app));
        if (!is_string($parent) || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Родительский каталог приложения должен быть доступен для записи updater-плана');
        }
        $this->parentRoot = UpdatePath::normalize($parent);

        $this->candidateVerifier = new UpdateCandidateVerifier(
            $this->appRoot,
            self::PRESERVED_ROOTS
        );
        $this->codeSwitcher = new UpdateCodeSwitcher(
            $this->appRoot,
            $this->parentRoot,
            $this->candidateVerifier,
            self::PRESERVED_ROOTS
        );
        $this->databaseRestorer = new UpdateDatabaseRestorer();
    }

    /**
     * Запрещает mutable-путь внутри release-owned корня, пока он не описан
     * отдельным правилом исключения пофайлового updater.
     *
     * @param list<string> $paths
     */
    public function assertMutablePathsSwitchSafe(array $paths): void
    {
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }

            $real = realpath($path);
            if (!is_string($real)) {
                continue;
            }
            $real = UpdatePath::normalize($real);

            if (!UpdatePath::inside($real, $this->appRoot) || $real === $this->appRoot) {
                continue;
            }

            $relative = ltrim(substr($real, strlen($this->appRoot)), '/');
            $top = explode('/', $relative, 2)[0] ?? '';
            if (!in_array($top, self::PRESERVED_ROOTS, true)) {
                throw new RuntimeException(
                    "Mutable-путь {$real} находится внутри релизного корня {$top}; "
                    . 'вынесите его из рабочего дерева или добавьте явное правило сохранения до обновления'
                );
            }
        }
    }

    /**
     * @return array{candidate_dir:string,target_version:string,target_version_code:int,tree_sha256:string,files:int,total_bytes:int,top_level:list<string>}
     */
    public function verifyCandidateTree(string $candidateDir): array
    {
        return $this->candidateVerifier->verifyCandidateTree($candidateDir);
    }

    /**
     * @return array{scratch_dir:string,plan_path:string,plan_sha256:string,transaction_id:string,entries:list<string>}
     */
    public function prepareCodeSwitch(string $transactionId, string $candidateDir, string $backupDir): array
    {
        return $this->codeSwitcher->prepare($transactionId, $candidateDir, $backupDir);
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    public function switchPrepared(array $plan): array
    {
        return $this->codeSwitcher->switchPrepared($plan);
    }

    /**
     * @return array<string,mixed>
     */
    public function restoreCode(string $transactionId, string $backupDir, string $candidateDir): array
    {
        return $this->codeSwitcher->restore($transactionId, $backupDir, $candidateDir);
    }

    /**
     * @param array<string,mixed> $databaseMetadata
     * @return array<string,mixed>
     */
    public function restoreDatabase(mysqli $db, string $backupDir, array $databaseMetadata): array
    {
        return $this->databaseRestorer->restore($db, $backupDir, $databaseMetadata);
    }

    /** @return array{version:string,version_code:int} */
    public function readLiveVersion(): array
    {
        return $this->candidateVerifier->readLiveVersion();
    }
}
