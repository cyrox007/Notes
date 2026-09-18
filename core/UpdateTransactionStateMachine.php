<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Extends the external updater journal with the live-apply/rollback state graph.
 *
 * UpdateTransactionJournal owns immutable identity + rollback backup attachment.
 * This class deliberately writes the same journal file and takes the same lock,
 * so recovery has one DB-independent source of truth across the destructive part
 * of an update.
 */
final class UpdateTransactionStateMachine
{
    private const MAX_BYTES = 262144;
    private const LOCK_FILENAME = '.update-transactions.lock';

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'backup_verified' => ['candidate_verified'],
        'candidate_verified' => ['preflight_verified'],
        'preflight_verified' => ['live_mutation_started'],
        'live_mutation_started' => ['code_switched', 'rollback_started'],
        'code_switched' => ['migrations_applied', 'rollback_started'],
        'migrations_applied' => ['postcheck_verified', 'rollback_started'],
        'postcheck_verified' => ['committed', 'rollback_started'],
        'rollback_started' => ['code_restored', 'rollback_failed'],
        'code_restored' => ['database_restored', 'rollback_failed'],
        'database_restored' => ['rollback_verified', 'rollback_failed'],
        // Recovery is explicitly retryable after an interrupted/failed rollback.
        'rollback_failed' => ['rollback_started'],
    ];

    private UpdateTransactionJournal $journal;
    private string $transactionsRoot;
    private string $appRoot;

    public function __construct(string $stateRoot, ?string $appRoot = null)
    {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Application root cannot be resolved for updater state machine');
        }
        $this->appRoot = $this->normalize($resolvedApp);
        $this->journal = new UpdateTransactionJournal($stateRoot, $resolvedApp);
        $this->transactionsRoot = dirname($this->journal->path('stateprobe01'));
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function recordCandidate(string $transactionId, array $candidate): array
    {
        $candidate = $this->validateCandidate($candidate);
        $state = $this->journal->load($transactionId);
        if ((int) ($state['target_version_code'] ?? -1) !== (int) $candidate['target_version_code']) {
            throw new RuntimeException('Release candidate version does not match updater transaction');
        }

        return $this->transition(
            $transactionId,
            'candidate_verified',
            ['candidate' => $candidate],
            'External release candidate tree was re-hashed and attached to the transaction'
        );
    }

    /** @param array<string,mixed> $preflight @return array<string,mixed> */
    public function markPreflightVerified(string $transactionId, array $preflight): array
    {
        return $this->transition(
            $transactionId,
            'preflight_verified',
            ['preflight' => $preflight],
            'Live health, migration dry-run, release candidate and rollback backup passed final preflight'
        );
    }

    /** @param array<string,mixed> $apply @return array<string,mixed> */
    public function markLiveMutationStarted(string $transactionId, array $apply): array
    {
        return $this->transition(
            $transactionId,
            'live_mutation_started',
            ['live_mutation_started' => true, 'apply' => $apply],
            'Destructive boundary crossed; every subsequent failure requires rollback'
        );
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markCodeSwitched(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'code_switched', ['apply_step' => $details], 'Verified candidate code switched into the live release');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markMigrationsApplied(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'migrations_applied', ['migration_step' => $details], 'Candidate database migrations completed');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markPostcheckVerified(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'postcheck_verified', ['postcheck' => $details], 'Updated runtime passed exact-version and health checks');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markCommitted(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'committed', ['commit' => $details], 'Update transaction committed; maintenance may be released');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markRollbackStarted(string $transactionId, array $details = []): array
    {
        return $this->transition($transactionId, 'rollback_started', ['rollback' => $details], 'Rollback started while maintenance remains active');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markCodeRestored(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'code_restored', ['rollback_code' => $details], 'Application code restored from verified rollback snapshot');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markDatabaseRestored(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'database_restored', ['rollback_database' => $details], 'MySQL database restored from verified rollback dump');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markRollbackVerified(string $transactionId, array $details): array
    {
        return $this->transition($transactionId, 'rollback_verified', ['rollback_verify' => $details], 'Restored version and healthcheck passed; maintenance may be released');
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    public function markRollbackFailed(string $transactionId, array $details): array
    {
        $state = $this->journal->load($transactionId);
        $current = (string) ($state['state'] ?? '');
        if (!in_array($current, ['rollback_started', 'code_restored', 'database_restored', 'rollback_failed'], true)) {
            throw new RuntimeException("Cannot mark rollback failure from updater transaction state {$current}");
        }
        if ($current === 'rollback_failed') {
            return $state;
        }
        return $this->transition($transactionId, 'rollback_failed', ['rollback_failure' => $details], 'Rollback did not verify; maintenance MUST remain active');
    }

    /** @return array<string,mixed> */
    public function load(string $transactionId): array
    {
        return $this->journal->load($transactionId);
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    private function transition(string $transactionId, string $nextState, array $patch, string $note): array
    {
        return $this->withLock(function () use ($transactionId, $nextState, $patch, $note): array {
            $path = $this->journal->path($transactionId);
            $journal = $this->readPath($path);
            $current = (string) ($journal['state'] ?? '');

            if ($current === $nextState) {
                foreach ($patch as $key => $value) {
                    if (array_key_exists($key, $journal) && $journal[$key] !== $value) {
                        throw new RuntimeException("Updater transaction {$nextState} state is bound to different {$key}");
                    }
                }
                return $journal;
            }

            $allowed = self::TRANSITIONS[$current] ?? [];
            if (!in_array($nextState, $allowed, true)) {
                throw new RuntimeException("Invalid updater transaction transition {$current} -> {$nextState}");
            }

            foreach ($patch as $key => $value) {
                $journal[$key] = $value;
            }
            if ($nextState === 'live_mutation_started') {
                $journal['live_mutation_started'] = true;
            }
            $now = time();
            $journal['state'] = $nextState;
            $journal['updated_at'] = $now;
            $history = is_array($journal['history'] ?? null) ? $journal['history'] : [];
            $history[] = ['at' => $now, 'state' => $nextState, 'note' => $note];
            $journal['history'] = $history;

            $this->writeAtomic($path, $journal);
            $updated = $this->readPath($path);
            $event = $nextState === 'rollback_failed'
                ? 'update.transaction.rollback_failed'
                : 'update.transaction.state';
            $severity = $nextState === 'rollback_failed'
                ? 'error'
                : (str_starts_with($nextState, 'rollback_') ? 'warning' : 'info');
            OperationalTelemetry::emit($event, $severity, [
                'transaction_hash' => hash('sha256', $transactionId),
                'from' => $current,
                'to' => $nextState,
            ]);
            return $updated;
        });
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function validateCandidate(array $candidate): array
    {
        foreach (['candidate_dir', 'tree_manifest', 'tree_sha256', 'target_version', 'target_version_code', 'files', 'total_bytes'] as $key) {
            if (!array_key_exists($key, $candidate)) {
                throw new RuntimeException("Release candidate metadata is missing {$key}");
            }
        }
        $dirInput = is_string($candidate['candidate_dir']) ? $candidate['candidate_dir'] : '';
        $dir = realpath($dirInput);
        if (!is_string($dir) || !is_dir($dir) || is_link($dirInput)) {
            throw new RuntimeException('Release candidate directory is missing or unsafe');
        }
        $dir = $this->normalize($dir);
        if ($this->inside($dir, $this->appRoot)) {
            throw new RuntimeException('Release candidate directory must remain outside the live application tree');
        }

        $treeInput = is_string($candidate['tree_manifest']) ? $candidate['tree_manifest'] : '';
        $tree = realpath($treeInput);
        if (!is_string($tree) || !is_file($tree) || is_link($treeInput)) {
            throw new RuntimeException('Release candidate tree manifest is missing or unsafe');
        }
        $tree = $this->normalize($tree);
        if (!$this->inside($tree, $dir) || $tree === $dir) {
            throw new RuntimeException('Release candidate tree manifest escaped candidate directory');
        }
        $hash = strtolower(trim((string) $candidate['tree_sha256']));
        $actual = hash_file('sha256', $tree);
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || !is_string($actual) || !hash_equals($hash, $actual)) {
            throw new RuntimeException('Release candidate tree manifest SHA-256 mismatch');
        }

        $bytes = file_get_contents($tree);
        try {
            $manifest = is_string($bytes) ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Release candidate tree manifest is invalid JSON', 0, $e);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('Release candidate tree manifest must be an object');
        }
        if (
            !hash_equals((string) ($candidate['target_version'] ?? ''), (string) ($manifest['target_version'] ?? ''))
            || (int) ($candidate['target_version_code'] ?? -1) !== (int) ($manifest['target_version_code'] ?? -2)
            || (int) ($candidate['files'] ?? -1) !== (int) ($manifest['file_count'] ?? -2)
            || (int) ($candidate['total_bytes'] ?? -1) !== (int) ($manifest['total_bytes'] ?? -2)
        ) {
            throw new RuntimeException('Release candidate metadata does not match its tree manifest');
        }

        $candidate['candidate_dir'] = $dir;
        $candidate['tree_manifest'] = $tree;
        $candidate['tree_sha256'] = $hash;
        return $candidate;
    }

    /** @return array<string,mixed> */
    private function readPath(string $path): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Updater transaction journal path is unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
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
        return $journal;
    }

    /** @param array<string,mixed> $journal */
    private function writeAtomic(string $path, array $journal): void
    {
        $bytes = json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('Updater transaction journal exceeds size limit');
        }
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.journal-live-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($tmp, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary updater transaction journal');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete updater transaction journal');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically publish updater transaction transition');
        }
        @chmod($path, 0600);
    }

    /** @return mixed */
    private function withLock(callable $callback): mixed
    {
        $lockPath = $this->transactionsRoot . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Updater transaction lock path is unsafe');
        }
        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open updater transaction lock');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Cannot acquire updater transaction lock');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
