<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdateCandidateVerifier.php';
require_once __DIR__ . '/UpdatePath.php';

use RuntimeException;
use Throwable;

/**
 * Filesystem mutation boundary for release-owned code.
 *
 * All candidate/backup verification is delegated to UpdateCandidateVerifier.
 * This class only prepares scratch trees and performs controlled rename-based
 * switches/rollbacks.
 */
final class UpdateCodeSwitcher
{
    /** @param list<string> $preservedRoots */
    public function __construct(
        private readonly string $appRoot,
        private readonly string $parentRoot,
        private readonly UpdateCandidateVerifier $verifier,
        private readonly array $preservedRoots
    ) {}

    /**
     * @return array{scratch_dir:string,new_dir:string,old_dir:string,entries:list<string>}
     */
    public function prepare(string $transactionId, string $candidateDir, string $backupDir): array
    {
        $this->validateTransactionId($transactionId);
        $candidate = $this->verifier->verifyCandidateTree($candidateDir);
        $backup = $this->verifier->loadCodeManifest($backupDir);
        $candidateTops = $candidate['top_level'];
        $backupTops = $this->verifier->topLevelsFromEntries($backup['entries']);

        $entries = array_values(array_unique(array_merge($candidateTops, $backupTops)));
        $entries = array_values(array_filter(
            $entries,
            fn (string $name): bool => !$this->isPreservedRoot($name) && !$this->isPreservedEnvName($name)
        ));
        sort($entries, SORT_STRING);
        if ($entries === []) {
            throw new RuntimeException('Updater live switch has no release-owned entries');
        }

        $scratch = $this->scratchPath('apply', $transactionId);
        if (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Updater live switch scratch already exists; use recovery before retrying apply');
        }

        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made || !is_dir($scratch)) {
            throw new RuntimeException('Cannot create updater live switch scratch directory');
        }

        $newDir = $scratch . '/new';
        $oldDir = $scratch . '/old';
        if (!mkdir($newDir, 0700) || !mkdir($oldDir, 0700)) {
            UpdatePath::removeTree($scratch);
            throw new RuntimeException('Cannot initialize updater live switch scratch directories');
        }

        try {
            foreach ($candidateTops as $name) {
                if ($this->isPreservedRoot($name) || $this->isPreservedEnvName($name)) {
                    continue;
                }
                $source = $candidate['candidate_dir'] . '/' . $name;
                if (!file_exists($source) && !is_link($source)) {
                    throw new RuntimeException("Candidate top-level entry disappeared during preparation: {$name}");
                }
                $this->copyEntry($source, $newDir . '/' . $name);
            }

            $this->writePlan($scratch . '/plan.json', [
                'schema' => 1,
                'transaction_id' => $transactionId,
                'mode' => 'apply',
                'application_root' => $this->appRoot,
                'candidate_dir' => $candidate['candidate_dir'],
                'candidate_tree_sha256' => $candidate['tree_sha256'],
                'entries' => $entries,
            ]);

            $this->verifier->verifyPreparedCandidate($newDir, $candidate['candidate_dir'], $candidateTops);
        } catch (Throwable $e) {
            UpdatePath::removeTree($scratch);
            throw $e;
        }

        return [
            'scratch_dir' => $scratch,
            'new_dir' => $newDir,
            'old_dir' => $oldDir,
            'entries' => $entries,
        ];
    }

    /**
     * @param array{scratch_dir:string,new_dir:string,old_dir:string,entries:list<string>} $plan
     * @return array<string,mixed>
     */
    public function switchPrepared(array $plan): array
    {
        $scratch = UpdatePath::normalize((string) ($plan['scratch_dir'] ?? ''));
        if (!is_dir($scratch) || is_link($scratch) || dirname($scratch) !== $this->parentRoot) {
            throw new RuntimeException('Updater live switch scratch path is unsafe');
        }

        $newDir = UpdatePath::normalize((string) ($plan['new_dir'] ?? ''));
        $oldDir = UpdatePath::normalize((string) ($plan['old_dir'] ?? ''));
        if ($newDir !== $scratch . '/new' || $oldDir !== $scratch . '/old' || !is_dir($newDir) || !is_dir($oldDir)) {
            throw new RuntimeException('Updater live switch scratch layout is invalid');
        }

        $switched = [];
        foreach (($plan['entries'] ?? []) as $entry) {
            $entry = (string) $entry;
            if (!UpdatePath::safeTopLevel($entry) || $this->isPreservedRoot($entry) || $this->isPreservedEnvName($entry)) {
                throw new RuntimeException('Updater switch plan contains unsafe top-level entry');
            }

            $live = $this->appRoot . '/' . $entry;
            $old = $oldDir . '/' . $entry;
            $new = $newDir . '/' . $entry;
            $hadLive = file_exists($live) || is_link($live);
            $hasNew = file_exists($new) || is_link($new);

            if ($hadLive) {
                if (file_exists($old) || is_link($old) || !@rename($live, $old)) {
                    throw new RuntimeException("Cannot move live release entry into transaction scratch: {$entry}");
                }
            }

            if ($hasNew && !@rename($new, $live)) {
                if ($hadLive && !file_exists($live) && !is_link($live)) {
                    @rename($old, $live);
                }
                throw new RuntimeException("Cannot activate candidate release entry: {$entry}");
            }

            $switched[] = $entry;
        }

        return ['scratch_dir' => $scratch, 'entries' => $switched, 'switched_at' => time()];
    }

    /** @return array<string,mixed> */
    public function restore(string $transactionId, string $backupDir, string $candidateDir): array
    {
        $this->validateTransactionId($transactionId);
        $backup = $this->verifier->loadCodeManifest($backupDir);
        $candidate = $this->verifier->verifyCandidateTree($candidateDir);
        $backupTops = $this->verifier->topLevelsFromEntries($backup['entries']);

        $entries = array_values(array_unique(array_merge($backupTops, $candidate['top_level'])));
        $entries = array_values(array_filter(
            $entries,
            fn (string $name): bool => !$this->isPreservedRoot($name) && !$this->isPreservedEnvName($name)
        ));
        sort($entries, SORT_STRING);

        $scratch = $this->scratchPath('rollback', $transactionId);
        if (is_dir($scratch) && !is_link($scratch)) {
            UpdatePath::removeTree($scratch);
        } elseif (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Updater rollback scratch path is unsafe');
        }

        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made) {
            throw new RuntimeException('Cannot create updater rollback scratch directory');
        }

        $restoreDir = $scratch . '/restore';
        $failedDir = $scratch . '/failed-release';
        if (!mkdir($restoreDir, 0700) || !mkdir($failedDir, 0700)) {
            UpdatePath::removeTree($scratch);
            throw new RuntimeException('Cannot initialize updater rollback scratch directories');
        }

        $backupCodeRoot = $backup['backup_dir'] . '/code';
        foreach ($backupTops as $name) {
            $source = $backupCodeRoot . '/' . $name;
            if (!file_exists($source) && !is_link($source)) {
                throw new RuntimeException("Verified code backup is missing top-level entry: {$name}");
            }
            $this->copyEntry($source, $restoreDir . '/' . $name);
        }

        foreach ($entries as $entry) {
            $live = $this->appRoot . '/' . $entry;
            $failed = $failedDir . '/' . $entry;
            $restore = $restoreDir . '/' . $entry;

            if (file_exists($live) || is_link($live)) {
                if (!@rename($live, $failed)) {
                    throw new RuntimeException("Cannot quarantine failed release entry during rollback: {$entry}");
                }
            }

            if ((file_exists($restore) || is_link($restore)) && !@rename($restore, $live)) {
                throw new RuntimeException("Cannot restore rollback code entry: {$entry}");
            }
        }

        $this->verifier->verifyLiveAgainstBackup($backup);

        return ['scratch_dir' => $scratch, 'entries' => $entries, 'restored_at' => time()];
    }

    private function copyEntry(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException('Updater refuses symlink while preparing code switch');
        }

        if (is_file($source)) {
            $this->ensureDirectory(dirname($destination));
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Cannot copy updater release file into switch scratch');
            }

            try {
                $copied = stream_copy_to_stream($input, $output);
                if (!is_int($copied) || !fflush($output)) {
                    throw new RuntimeException('Updater release file copy did not complete');
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            $mode = fileperms($source);
            @chmod($destination, is_int($mode) ? ($mode & 0777) : 0644);
            return;
        }

        if (!is_dir($source)) {
            throw new RuntimeException('Updater release contains unsupported filesystem entry');
        }

        $this->ensureDirectory($destination);
        $items = scandir($source);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot read updater release directory');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->copyEntry($source . '/' . $item, $destination . '/' . $item);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Updater scratch directory path collides with existing entry');
        }

        $old = umask(0022);
        $ok = @mkdir($path, 0755, true);
        umask($old);
        if (!$ok && !is_dir($path)) {
            throw new RuntimeException('Cannot create updater scratch directory');
        }
        @chmod($path, 0755);
    }

    /** @param array<string,mixed> $payload */
    private function writePlan(string $path, array $payload): void
    {
        $bytes = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write updater switch plan');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete updater switch plan');
            }
        } finally {
            fclose($handle);
        }

        @chmod($path, 0600);
    }

    private function scratchPath(string $mode, string $transactionId): string
    {
        return $this->parentRoot . '/.' . basename($this->appRoot) . '.update-' . $mode . '-' . $transactionId;
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id');
        }
    }

    private function isPreservedRoot(string $name): bool
    {
        return in_array($name, $this->preservedRoots, true);
    }

    private function isPreservedEnvName(string $name): bool
    {
        return $name === '.env' || str_starts_with($name, '.env.');
    }
}
