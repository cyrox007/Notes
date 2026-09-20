<?php

declare(strict_types=1);

use Core\MigrationManifest;
use Core\UpdateMigrationPreflight;

$root = dirname(__DIR__, 2);
require_once $root . '/core/MigrationManifest.php';
require_once $root . '/core/UpdateMigrationPreflight.php';

function migrationPreflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function migrationPreflightRemoveTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            migrationPreflightRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function migrationPreflightWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path))) {
        migrationPreflightAssert(mkdir(dirname($path), 0700, true), 'cannot create fixture directory');
    }
    migrationPreflightAssert(file_put_contents($path, $bytes) === strlen($bytes), 'cannot write fixture ' . $path);
}

/** @param list<string> $names */
function migrationPreflightManifest(string $root, array $names): void
{
    $bytes = json_encode([
        'schema' => 1,
        'product' => 'workspace-organizer',
        'migrations' => $names,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    migrationPreflightWrite($root . '/database/migrations/manifest.json', $bytes);
}

$dbName = trim((string) (getenv('MIGRATION_PREFLIGHT_DBNAME') ?: ''));
migrationPreflightAssert($dbName !== '', 'MIGRATION_PREFLIGHT_DBNAME is required');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    (string) (getenv('DBHOST') ?: '127.0.0.1'),
    (string) (getenv('DBUSER') ?: 'root'),
    (string) (getenv('DBPASS') ?: ''),
    $dbName,
    (int) (getenv('DBPORT') ?: 3306)
);
$db->set_charset('utf8mb4');

$temp = sys_get_temp_dir() . '/wo-migration-preflight-' . bin2hex(random_bytes(6));
migrationPreflightAssert(mkdir($temp, 0700, true), 'cannot create migration preflight temp root');

try {
    $migrationRoot = $temp . '/database/migrations';
    migrationPreflightAssert(mkdir($migrationRoot, 0700, true), 'cannot create migration fixture root');
    $alpha = '20260916_alpha.sql';
    $beta = '20260916_beta.sql';
    $alphaSql = "CREATE TABLE IF NOT EXISTS alpha_fixture (id INT PRIMARY KEY);\n";
    $betaSql = "DELIMITER $$\nCREATE PROCEDURE beta_fixture()\nBEGIN\n  SELECT 1;\nEND$$\nDELIMITER ;\n";
    migrationPreflightWrite($migrationRoot . '/' . $alpha, $alphaSql);
    migrationPreflightWrite($migrationRoot . '/' . $beta, $betaSql);
    migrationPreflightManifest($temp, [$alpha, $beta]);

    $db->query('DROP TABLE IF EXISTS schema_migrations');
    $db->query(
        'CREATE TABLE schema_migrations ('
        . 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
        . 'migration VARCHAR(190) NOT NULL,'
        . 'checksum CHAR(64) NOT NULL,'
        . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
        . 'UNIQUE KEY uq_schema_migrations_name (migration)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $stmt = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?)');
    $alphaHash = hash('sha256', $alphaSql);
    $stmt->bind_param('ss', $alpha, $alphaHash);
    $stmt->execute();
    $stmt->close();

    $checked = (new UpdateMigrationPreflight($temp))->check($db);
    migrationPreflightAssert(($checked['status'] ?? '') === 'ok', 'preflight did not return ok');
    migrationPreflightAssert(($checked['ledger_present'] ?? false) === true, 'ledger was not detected');
    migrationPreflightAssert(($checked['legacy_untracked'] ?? true) === false, 'tracked DB marked legacy');
    migrationPreflightAssert(($checked['applied'] ?? -1) === 1, 'applied migration count changed');
    migrationPreflightAssert(($checked['pending'] ?? -1) === 1, 'pending migration count changed');
    migrationPreflightAssert((($checked['pending_migrations'][0]['name'] ?? '') === $beta), 'pending migration identity changed');

    migrationPreflightWrite($migrationRoot . '/' . $alpha, $alphaSql . "-- tampered\n");
    $tamperRejected = false;
    try {
        (new UpdateMigrationPreflight($temp))->check($db);
    } catch (Throwable $e) {
        $tamperRejected = str_contains($e->getMessage(), 'checksum mismatch');
    }
    migrationPreflightAssert($tamperRejected, 'applied migration tamper was accepted');
    migrationPreflightWrite($migrationRoot . '/' . $alpha, $alphaSql);

    $stmt = $db->prepare('INSERT INTO schema_migrations (migration,checksum) VALUES (?,?)');
    $betaHash = hash('sha256', $betaSql);
    $stmt->bind_param('ss', $beta, $betaHash);
    $stmt->execute();
    $stmt->close();
    migrationPreflightManifest($temp, [$beta, $alpha]);
    $reorderRejected = false;
    try {
        (new UpdateMigrationPreflight($temp))->check($db);
    } catch (Throwable $e) {
        $reorderRejected = str_contains($e->getMessage(), 'order');
    }
    migrationPreflightAssert($reorderRejected, 'applied migration order change was accepted');
    migrationPreflightManifest($temp, [$alpha, $beta]);

    $db->query('DROP TABLE schema_migrations');
    $legacy = (new UpdateMigrationPreflight($temp))->check($db);
    migrationPreflightAssert(($legacy['ledger_present'] ?? true) === false, 'missing ledger was not detected');
    migrationPreflightAssert(($legacy['legacy_untracked'] ?? false) === true, 'legacy DB was not marked untracked');
    migrationPreflightAssert(($legacy['applied'] ?? -1) === 0 && ($legacy['pending'] ?? -1) === 2, 'legacy pending set changed');

    migrationPreflightWrite($migrationRoot . '/' . $beta, "CREATE TABLE broken_fixture (id INT\n");
    $unterminatedRejected = false;
    try {
        (new UpdateMigrationPreflight($temp))->check($db);
    } catch (Throwable $e) {
        $unterminatedRejected = str_contains($e->getMessage(), 'unterminated');
    }
    migrationPreflightAssert($unterminatedRejected, 'unterminated target SQL was accepted');
    migrationPreflightWrite($migrationRoot . '/' . $beta, $betaSql);

    migrationPreflightManifest($temp, ['../escape.sql']);
    $traversalRejected = false;
    try {
        (new MigrationManifest($temp))->load();
    } catch (Throwable $e) {
        $traversalRejected = str_contains($e->getMessage(), 'unsafe filename');
    }
    migrationPreflightAssert($traversalRejected, 'migration manifest traversal was accepted');

    $canonical = (new MigrationManifest($root))->names();
    migrationPreflightAssert($canonical !== [], 'canonical migration manifest is empty');
    $migrateSource = file_get_contents($root . '/bin/migrate.php');
    migrationPreflightAssert(is_string($migrateSource), 'cannot read bin/migrate.php for manifest ownership contract');
    migrationPreflightAssert(
        str_contains($migrateSource, 'new \\Core\\MigrationManifest($root)'),
        'bin/migrate.php does not consume the canonical migration manifest'
    );
    migrationPreflightAssert(
        str_contains($migrateSource, '\\Core\\DatabaseOwnership::fromPackageRoot($root)'),
        'bin/migrate.php does not consume packaged database ownership'
    );
    migrationPreflightAssert(
        !str_contains($migrateSource, '$manifest = ['),
        'bin/migrate.php still duplicates the canonical migration order'
    );

    echo "[OK] updater data-only migration preflight contract\n";
} finally {
    $db->close();
    migrationPreflightRemoveTree($temp);
}
