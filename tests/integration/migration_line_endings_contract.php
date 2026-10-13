<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/MigrationManifest.php';

use Core\MigrationManifest;

$sql = "CREATE TABLE example (id INT);\nINSERT INTO example VALUES (1);\n";
$windows = str_replace("\n", "\r\n", $sql);
foreach ([$sql, $windows] as $source) {
    foreach ([$sql, $windows] as $applied) {
        if (!MigrationManifest::matchesChecksum($source, hash('sha256', $applied))) {
            throw new RuntimeException('Known Windows/Git line endings rejected');
        }
    }
    if (MigrationManifest::matchesChecksum($source, hash('sha256', str_replace('VALUES (1)', 'VALUES (2)', $sql)))) {
        throw new RuntimeException('Changed SQL accepted');
    }
    if (MigrationManifest::matchesChecksum($source, 'invalid')) throw new RuntimeException('Invalid digest accepted');
}
echo "[OK] Migration checksums preserve SQL integrity across LF/CRLF without rewriting ledger\n";
