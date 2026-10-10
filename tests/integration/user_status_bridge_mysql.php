<?php
declare(strict_types=1);

// Explicit test credentials only: never load an application's .env.
if (PHP_SAPI !== 'cli' || !getenv('DBUSER')) {
    throw new RuntimeException('CLI and explicit test database credentials required');
}
$pdo = new PDO('mysql:host=' . (getenv('DBHOST') ?: '127.0.0.1') . ';port=' . (getenv('DBPORT') ?: 3306),
    (string) getenv('DBUSER'), (string) getenv('DBPASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'notes_status_test_' . bin2hex(random_bytes(6));
$root = dirname(__DIR__, 2);
$pdo->exec("CREATE DATABASE `$database`");
try {
    $pdo->exec("USE `$database`");
    $pdo->exec("CREATE TABLE users (id INT PRIMARY KEY, role INT NOT NULL, is_active INT NOT NULL,
        account_status ENUM('active','inactive','blocked') NOT NULL, label VARCHAR(30) DEFAULT '')");
    $pdo->exec("INSERT INTO users (id,role,is_active,account_status) VALUES (1,888,1,'active')");
    $legacy = (string) file_get_contents($root . '/database/migrations/20260915_rbac_foundation.sql');
    if (!preg_match('/DROP TRIGGER IF EXISTS `users_before_update_account_status_bridge`;.*$/s', $legacy, $match)) {
        throw new RuntimeException('Legacy trigger fixture missing');
    }
    $apply = static function (string $sql) use ($pdo): void {
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') $pdo->exec($statement);
        }
    };
    $expect = static function (string $expected) use ($pdo): void {
        $actual = $pdo->query('SELECT account_status FROM users WHERE id=1')->fetchColumn();
        if ($actual !== $expected) throw new RuntimeException("Expected $expected, got $actual");
    };
    $apply($match[0]);
    $pdo->exec("UPDATE users SET account_status='blocked' WHERE id=1");
    $expect('active'); // Reproduce the real upgraded-installation bug first.
    $migration = (string) file_get_contents($root . '/database/migrations/20261010_user_status_bridge.sql');
    $apply($migration);
    $apply($migration); // Reapplication must preserve state and be safe.
    $pdo->exec("UPDATE users SET account_status='blocked',is_active=1 WHERE id=1");
    $expect('blocked');
    $pdo->exec("UPDATE users SET label='profile edit' WHERE id=1");
    $expect('blocked');
    $pdo->exec("UPDATE users SET account_status='active',is_active=1 WHERE id=1");
    $expect('active');
    $pdo->exec('UPDATE users SET role=999 WHERE id=1');
    $expect('blocked');
    $pdo->exec("UPDATE users SET label='password edit' WHERE id=1");
    $expect('blocked');
    $pdo->exec('UPDATE users SET role=888 WHERE id=1');
    $expect('active');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    $expect('inactive');
    $pdo->exec("UPDATE users SET label='inactive edit' WHERE id=1");
    $expect('inactive');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    $expect('active');
    $pdo->exec('UPDATE users SET role=899 WHERE id=1');
    $expect('inactive');
    echo "[OK] Legacy status reset reproduced; migration preserves blocking, profile updates and legacy transitions\n";
} finally {
    $pdo->exec("DROP DATABASE `$database`");
}
