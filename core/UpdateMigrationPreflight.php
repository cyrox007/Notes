<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use RuntimeException;

require_once __DIR__ . '/MigrationManifest.php';
require_once __DIR__ . '/ModuleManifest.php';
require_once __DIR__ . '/DatabaseOwnership.php';

/**
 * Read-only migration preflight for a verified release candidate.
 *
 * No PHP from the candidate is executed. The current updater process reads only
 * bounded JSON manifests and SQL bytes from the candidate, then compares them
 * with the live schema_migrations ledger when one exists.
 */
final class UpdateMigrationPreflight
{
    private MigrationManifest $manifest;
    private ?DatabaseOwnership $ownership = null;

    public function __construct(string $releaseRoot)
    {
        $this->manifest = new MigrationManifest($releaseRoot);
        if (is_dir(rtrim($releaseRoot, '/\\') . '/modules')) {
            $this->ownership = DatabaseOwnership::fromPackageRoot($releaseRoot);
        }
    }

    /**
     * @return array{
     *   status:string,
     *   ledger_present:bool,
     *   legacy_untracked:bool,
     *   applied:int,
     *   pending:int,
     *   pending_migrations:list<array{name:string,sha256:string,statements:int}>,
     *   manifest_sha256:string,
     *   sql_set_sha256:string
     * }
     */
    public function check(mysqli $db): array
    {
        $manifest = $this->manifest->load();
        $canonicalFiles = $manifest['migrations'];
        $files = $this->ownership !== null
            ? $this->ownership->migrationNamesInCanonicalOrder($canonicalFiles)
            : $canonicalFiles;
        $selected = array_fill_keys($files, true);
        $target = [];
        $sqlSetHash = hash_init('sha256');

        // Validate every immutable historical entry so an existing ledger remains
        // verifiable even when the target package no longer contains its module.
        foreach ($canonicalFiles as $index => $filename) {
            $sql = $this->manifest->readMigration($filename);
            $sha = hash('sha256', $sql);
            $statements = $this->parseStatements($sql, $filename);
            $target[$filename] = [
                'index' => (int) $index,
                'sha256' => $sha,
                'statements' => count($statements),
            ];
            if (isset($selected[$filename])) {
                hash_update($sqlSetHash, $filename . "\0" . $sha . "\n");
            }
        }

        $ledgerPresent = $this->tableExists($db, 'schema_migrations');
        $applied = [];
        if ($ledgerPresent) {
            $result = $db->query('SELECT id,migration,checksum FROM schema_migrations ORDER BY id ASC');
            $lastTargetIndex = -1;
            while ($row = $result->fetch_assoc()) {
                $name = (string) ($row['migration'] ?? '');
                $checksum = strtolower((string) ($row['checksum'] ?? ''));
                if ($name === '' || !isset($target[$name])) {
                    throw new RuntimeException(
                        'Current migration ledger contains an entry absent from target release: ' . $name
                    );
                }
                if (isset($applied[$name])) {
                    throw new RuntimeException('Current migration ledger contains a duplicate: ' . $name);
                }
                if (preg_match('/^[0-9a-f]{64}$/', $checksum) !== 1
                    || !hash_equals($target[$name]['sha256'], $checksum)) {
                    throw new RuntimeException('Applied migration checksum mismatch in target release: ' . $name);
                }

                $targetIndex = (int) $target[$name]['index'];
                if ($targetIndex <= $lastTargetIndex) {
                    throw new RuntimeException('Target migration manifest changes the order of already applied migrations');
                }
                $lastTargetIndex = $targetIndex;
                $applied[$name] = true;
            }
        }

        $pending = [];
        foreach ($files as $filename) {
            if (isset($applied[$filename])) {
                continue;
            }
            $pending[] = [
                'name' => $filename,
                'sha256' => (string) $target[$filename]['sha256'],
                'statements' => (int) $target[$filename]['statements'],
            ];
        }

        $selectedApplied = count(array_intersect_key($applied, $selected));
        return [
            'status' => 'ok',
            'ledger_present' => $ledgerPresent,
            // Published Beta4 predates a reliable ledger for installer-created
            // schema. In that case we can still prove target SQL is bounded and
            // syntactically split-safe; the migrator remains responsible for its
            // legacy reconciliation after the code switch.
            'legacy_untracked' => !$ledgerPresent,
            'applied' => $selectedApplied,
            'pending' => count($pending),
            'pending_migrations' => $pending,
            'manifest_sha256' => $manifest['sha256'],
            'sql_set_sha256' => hash_final($sqlSetHash),
        ];
    }

    /** @return list<string> */
    private function parseStatements(string $sql, string $filename): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $lines = preg_split('/\R/u', $sql);
        if ($lines === false) {
            throw new RuntimeException('Target migration is not valid UTF-8: ' . $filename);
        }

        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                if (trim($buffer) !== '') {
                    throw new RuntimeException(
                        'Target migration changes DELIMITER before statement ended: ' . $filename
                    );
                }
                $delimiter = (string) $match[1];
                if ($delimiter === '' || strlen($delimiter) > 32 || preg_match('/\s/', $delimiter) === 1) {
                    throw new RuntimeException('Target migration uses an invalid DELIMITER: ' . $filename);
                }
                continue;
            }

            if (trim($line) === '' && trim($buffer) === '') {
                continue;
            }

            $buffer .= $line . "\n";
            $trimmed = rtrim($buffer);
            if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
                continue;
            }

            $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
            $buffer = '';
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException('Target migration contains an unterminated SQL statement: ' . $filename);
        }
        return $statements;
    }

    private function tableExists(mysqli $db, string $table): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'
        );
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }
}
