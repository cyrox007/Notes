<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Retention cleanup for updater recovery artifacts.
 *
 * Only verified terminal transactions are eligible. Journals are intentionally
 * preserved as lightweight audit/recovery history; this cleaner removes the
 * space-heavy rollback backup and external release candidate after retention.
 */
final class UpdateArtifactCleaner
{
    private const MAX_JOURNAL_BYTES = 262144;
    private const LOCK_FILENAME = '.update-transactions.lock';
    private const TERMINAL_STATES = ['committed', 'rollback_verified'];

    private string $appRoot;
    private string $stateRoot;
    private string $transactionsRoot;
    private string $backupRoot;
    private string $candidateRoot;
    private int $now;

    public function __construct(
        string $stateRoot,
        string $backupRoot,
        string $candidateRoot,
        ?string $appRoot = null,
        ?int $now = null
    ) {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for updater retention');
        }
        $this->appRoot = UpdatePath::normalize($resolvedApp);
        $this->stateRoot = $this->resolveExternalRoot($stateRoot, 'state');
        $this->backupRoot = $this->resolveExternalRoot($backupRoot, 'backup');
        $this->candidateRoot = $this->resolveExternalRoot($candidateRoot, 'candidate');
        $this->transactionsRoot = $this->stateRoot . DIRECTORY_SEPARATOR . 'transactions';
        $this->now = $now ?? time();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(int $olderThanDays = 30, int $keep = 2, bool $apply = false): array
    {
        if ($olderThanDays < 0 || $olderThanDays > 3650) {
            throw new RuntimeException('Updater retention days must be between 0 and 3650');
        }
        if ($keep < 0 || $keep > 100) {
            throw new RuntimeException('Updater retention keep count must be between 0 and 100');
        }

        if (!is_dir($this->transactionsRoot)) {
            return [
                'status' => $apply ? 'applied' : 'preview',
                'can_apply' => true,
                'older_than_days' => $olderThanDays,
                'keep' => $keep,
                'terminal_transactions' => 0,
                'eligible_transactions' => 0,
                'deleted_directories' => 0,
                'deleted_bytes' => 0,
                'transactions' => [],
                'errors' => [],
            ];
        }
        if (is_link($this->transactionsRoot) || !is_writable($this->transactionsRoot)) {
            throw new RuntimeException('Updater transaction journal directory is unsafe or not writable');
        }

        $lockPath = $this->transactionsRoot . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Updater transaction lock path is unsafe');
        }
        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open updater transaction lock for retention');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Cannot acquire updater transaction lock for retention');
        }

        try {
            $plan = $this->buildPlan($olderThanDays, $keep);
            if ($apply && !$plan['can_apply']) {
                throw new RuntimeException('Updater retention is blocked by invalid transaction journal or unsafe artifact path');
            }

            $deletedDirectories = 0;
            $deletedBytes = 0;
            if ($apply) {
                foreach ($plan['transactions'] as &$transaction) {
                    if (!($transaction['eligible'] ?? false)) {
                        continue;
                    }
                    foreach (['backup', 'candidate'] as $kind) {
                        $artifact = $transaction[$kind] ?? null;
                        if (!is_array($artifact) || ($artifact['action'] ?? null) !== 'delete') {
                            continue;
                        }
                        $path = (string) ($artifact['path'] ?? '');
                        $root = $kind === 'backup' ? $this->backupRoot : $this->candidateRoot;
                        $expected = $kind === 'backup'
                            ? (string) $transaction['transaction_id']
                            : 'candidate-' . substr((string) $transaction['package_sha256'], 0, 16);

                        $bytes = $this->deleteDirectory($path, $root, $expected);
                        $artifact['action'] = 'deleted';
                        $artifact['deleted_bytes'] = $bytes;
                        $transaction[$kind] = $artifact;
                        $deletedDirectories++;
                        $deletedBytes += $bytes;
                    }
                }
                unset($transaction);
            }

            $plan['status'] = $apply ? 'applied' : 'preview';
            $plan['deleted_directories'] = $deletedDirectories;
            $plan['deleted_bytes'] = $deletedBytes;
            return $plan;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPlan(int $olderThanDays, int $keep): array
    {
        $journals = [];
        $errors = [];
        $items = scandir($this->transactionsRoot);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot read updater transaction journal directory');
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || $name === self::LOCK_FILENAME) {
                continue;
            }
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $path = $this->transactionsRoot . DIRECTORY_SEPARATOR . $name;
            try {
                $journal = $this->readJournal($path, $name);
                $journals[] = $journal;
            } catch (Throwable $e) {
                $errors[] = [
                    'journal' => $name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $terminal = array_values(array_filter(
            $journals,
            static fn (array $journal): bool => in_array((string) $journal['state'], self::TERMINAL_STATES, true)
        ));
        usort($terminal, static function (array $a, array $b): int {
            $timeCompare = ((int) $b['updated_at']) <=> ((int) $a['updated_at']);
            return $timeCompare !== 0
                ? $timeCompare
                : strcmp((string) $b['transaction_id'], (string) $a['transaction_id']);
        });

        $keptIds = [];
        for ($i = 0; $i < min($keep, count($terminal)); $i++) {
            $keptIds[(string) $terminal[$i]['transaction_id']] = true;
        }

        $cutoff = $this->now - ($olderThanDays * 86400);
        $eligibleIds = [];
        foreach ($terminal as $journal) {
            $id = (string) $journal['transaction_id'];
            if (!isset($keptIds[$id]) && (int) $journal['updated_at'] <= $cutoff) {
                $eligibleIds[$id] = true;
            }
        }

        // A candidate is package-addressed and may be reused by another transaction.
        // Any non-eligible journal protects the candidate for its package, even when
        // candidate metadata has not yet been attached to that journal.
        $protectedPackages = [];
        foreach ($journals as $journal) {
            $id = (string) $journal['transaction_id'];
            if (!isset($eligibleIds[$id])) {
                $protectedPackages[(string) $journal['package_sha256']] = true;
            }
        }

        $planned = [];
        $unsafe = false;
        foreach ($terminal as $journal) {
            $id = (string) $journal['transaction_id'];
            $eligible = isset($eligibleIds[$id]);
            $reason = 'eligible';
            if (!$eligible) {
                $reason = isset($keptIds[$id]) ? 'kept_recent_terminal' : 'retention_age_not_reached';
            }

            $row = [
                'transaction_id' => $id,
                'state' => (string) $journal['state'],
                'updated_at' => (int) $journal['updated_at'],
                'package_sha256' => (string) $journal['package_sha256'],
                'eligible' => $eligible,
                'reason' => $reason,
                'backup' => ['action' => 'keep'],
                'candidate' => ['action' => 'keep'],
            ];

            if ($eligible) {
                try {
                    $backupPath = (string) ($journal['backups']['backup_dir'] ?? '');
                    if ($backupPath === '') {
                        throw new RuntimeException('Terminal updater journal is missing rollback backup path');
                    }
                    $row['backup'] = $this->planDirectory(
                        $backupPath,
                        $this->backupRoot,
                        $id
                    );

                    if (isset($protectedPackages[(string) $journal['package_sha256']])) {
                        $candidatePath = $this->candidatePath($journal);
                        $row['candidate'] = [
                            'action' => 'keep',
                            'reason' => 'candidate_referenced_by_retained_transaction',
                            'path' => $candidatePath,
                        ];
                    } else {
                        $row['candidate'] = $this->planDirectory(
                            $this->candidatePath($journal),
                            $this->candidateRoot,
                            'candidate-' . substr((string) $journal['package_sha256'], 0, 16)
                        );
                    }
                } catch (Throwable $e) {
                    $unsafe = true;
                    $row['eligible'] = false;
                    $row['reason'] = 'blocked_unsafe_artifact';
                    $row['error'] = $e->getMessage();
                    $row['backup'] = ['action' => 'keep'];
                    $row['candidate'] = ['action' => 'keep'];
                }
            }

            $planned[] = $row;
        }

        return [
            'status' => 'preview',
            'can_apply' => $errors === [] && !$unsafe,
            'older_than_days' => $olderThanDays,
            'keep' => $keep,
            'cutoff' => $cutoff,
            'terminal_transactions' => count($terminal),
            'eligible_transactions' => count(array_filter(
                $planned,
                static fn (array $row): bool => ($row['eligible'] ?? false) === true
            )),
            'transactions' => $planned,
            'errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    private function readJournal(string $path, string $filename): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Updater transaction journal path is unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_JOURNAL_BYTES) {
            throw new RuntimeException('Updater transaction journal has an invalid size');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('Cannot read updater transaction journal');
        }
        try {
            $journal = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Updater transaction journal is corrupt', 0, $e);
        }
        if (!is_array($journal) || array_is_list($journal) || ($journal['schema'] ?? null) !== 1) {
            throw new RuntimeException('Updater transaction journal failed schema validation');
        }

        $transactionId = (string) ($journal['transaction_id'] ?? '');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            throw new RuntimeException('Updater transaction journal has invalid transaction id');
        }
        if ($filename !== $transactionId . '.json') {
            throw new RuntimeException('Updater transaction journal filename does not match transaction id');
        }

        $state = (string) ($journal['state'] ?? '');
        if ($state === '') {
            throw new RuntimeException('Updater transaction journal is missing state');
        }
        $updatedAt = $journal['updated_at'] ?? null;
        if (!is_int($updatedAt) || $updatedAt <= 0 || $updatedAt > $this->now + 300) {
            throw new RuntimeException('Updater transaction journal has invalid updated_at');
        }

        $packageSha = strtolower(trim((string) ($journal['package_sha256'] ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $packageSha) !== 1) {
            throw new RuntimeException('Updater transaction journal has invalid package SHA-256');
        }

        $journal['transaction_id'] = $transactionId;
        $journal['state'] = $state;
        $journal['updated_at'] = $updatedAt;
        $journal['package_sha256'] = $packageSha;
        return $journal;
    }

    /** @param array<string,mixed> $journal */
    private function candidatePath(array $journal): string
    {
        $attached = $journal['candidate']['candidate_dir'] ?? null;
        if (is_string($attached) && trim($attached) !== '') {
            return trim($attached);
        }
        return $this->candidateRoot
            . DIRECTORY_SEPARATOR
            . 'candidate-'
            . substr((string) $journal['package_sha256'], 0, 16);
    }

    /** @return array<string,mixed> */
    private function planDirectory(string $path, string $root, string $expectedBasename): array
    {
        if (!file_exists($path) && !is_link($path)) {
            return [
                'action' => 'absent',
                'path' => $path,
                'bytes' => 0,
            ];
        }

        $real = $this->validateArtifactDirectory($path, $root, $expectedBasename);
        return [
            'action' => 'delete',
            'path' => $real,
            'bytes' => $this->directoryBytes($real),
        ];
    }

    private function deleteDirectory(string $path, string $root, string $expectedBasename): int
    {
        if (!file_exists($path) && !is_link($path)) {
            return 0;
        }
        $real = $this->validateArtifactDirectory($path, $root, $expectedBasename);
        $bytes = $this->directoryBytes($real);
        UpdatePath::removeTree($real);
        if (file_exists($real) || is_link($real)) {
            throw new RuntimeException('Updater retention could not remove artifact directory');
        }
        return $bytes;
    }

    private function validateArtifactDirectory(string $path, string $root, string $expectedBasename): string
    {
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Updater retention artifact is not a safe directory');
        }
        $real = realpath($path);
        if (!is_string($real)) {
            throw new RuntimeException('Updater retention artifact cannot be resolved');
        }
        $real = UpdatePath::normalize($real);
        if (!UpdatePath::inside($real, $root) || $real === $root) {
            throw new RuntimeException('Updater retention artifact escaped its configured external root');
        }
        if (basename($real) !== $expectedBasename) {
            throw new RuntimeException('Updater retention artifact name does not match transaction/package identity');
        }
        return $real;
    }

    private function directoryBytes(string $dir): int
    {
        $total = 0;
        $items = scandir($dir);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot scan updater retention artifact');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $total += $this->directoryBytes($path);
                continue;
            }
            if (is_file($path)) {
                $size = filesize($path);
                if (is_int($size) && $size > 0) {
                    $total += $size;
                }
            }
        }
        return $total;
    }

    private function resolveExternalRoot(string $path, string $label): string
    {
        $path = trim($path);
        if (!UpdatePath::isAbsolute($path) || is_link($path)) {
            throw new RuntimeException("Updater {$label} root must be an absolute non-symlink path");
        }
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new RuntimeException("Updater {$label} root cannot be resolved");
        }
        $real = UpdatePath::normalize($real);
        if (UpdatePath::inside($real, $this->appRoot)) {
            throw new RuntimeException("Updater {$label} root must be outside the live application tree");
        }
        return $real;
    }
}
