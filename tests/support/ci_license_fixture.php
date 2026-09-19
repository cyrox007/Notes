<?php

declare(strict_types=1);

use App\Services\LicenseVerifier;

/**
 * Install an ephemeral CI-only license into the current test database.
 *
 * The signing keypair exists only in runner memory. The CI working copy gets an
 * additional ephemeral PUBLIC key so runtime licensing remains fully enforced
 * while mutation-heavy browser/HTTP tests execute. No production private key is
 * read, copied or uploaded.
 */
function workspaceEnsureCiLicense(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!extension_loaded('sodium')) {
        throw new RuntimeException('CI license fixture requires the sodium extension');
    }

    $root = dirname(__DIR__, 2);
    $env = workspaceCiLicenseEnvironment($root);
    if ($env['DBNAME'] === '') {
        return;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DBHOST'],
        $env['DBPORT'],
        $env['DBNAME']
    );

    try {
        $pdo = new PDO(
            $dsn,
            $env['DBUSER'],
            $env['DBPASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable) {
        return;
    }

    $settingsExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables"
        . " WHERE table_schema = DATABASE() AND table_name = 'system_settings'"
    )->fetchColumn();
    if ($settingsExists !== 1) {
        return;
    }

    require_once $root . '/app/services/LicenseVerifier.php';

    $keyId = 'ci-e2e-ephemeral';
    $registryPath = $root . '/config/license_trusted_keys.php';
    $lockPath = sys_get_temp_dir() . '/workspace-ci-license-'
        . hash('sha256', $root . '|' . $env['DBNAME']) . '.lock';
    $lock = fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Unable to create CI license fixture lock');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock CI license fixture');
        }

        $keys = require $registryPath;
        if (!is_array($keys)) {
            throw new RuntimeException('License trust registry must return an array');
        }

        $existing = $pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1'
        );
        $existing->execute([':key' => 'workspace_license_token']);
        $existingToken = trim((string) ($existing->fetchColumn() ?: ''));

        if (
            isset($keys[$keyId])
            && is_string($keys[$keyId])
            && str_starts_with($existingToken, LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.')
        ) {
            $done = true;
            return;
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        try {
            $publicKey = sodium_crypto_sign_publickey($keypair);
            $publicEncoded = LicenseVerifier::base64UrlEncode($publicKey);
            $installationId = workspaceCiLicenseUuid();

            $payload = [
                'v' => LicenseVerifier::PAYLOAD_VERSION,
                'license_id' => 'lic-ci-e2e-ephemeral',
                'installation_id' => $installationId,
                'issued_at' => time() - 5,
                'expires_at' => null,
                'edition' => 'ci',
                'features' => ['notes', 'tasks', 'files', 'messenger', 'profile', 'admin'],
            ];
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $payloadEncoded = LicenseVerifier::base64UrlEncode($json);
            $signedBytes = LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.' . $payloadEncoded;
            $signature = sodium_crypto_sign_detached($signedBytes, $secretKey);
            $token = $signedBytes . '.' . LicenseVerifier::base64UrlEncode($signature);

            $verifier = new LicenseVerifier([$keyId => $publicEncoded]);
            $status = $verifier->verify($token, $installationId);
            if (!($status['valid'] ?? false)) {
                throw new RuntimeException('Ephemeral CI license failed self-verification');
            }

            // This modifies only the disposable runner checkout. The production
            // public trust root remains present and no CI private key is written.
            $keys[$keyId] = $publicEncoded;
            $registry = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "/** CI runner overlay: production public roots plus one ephemeral test public key. */\n"
                . 'return ' . var_export($keys, true) . ";\n";
            $temporaryRegistry = $registryPath . '.ci-' . getmypid() . '.tmp';
            if (file_put_contents($temporaryRegistry, $registry, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write CI trust-registry overlay');
            }
            if (!rename($temporaryRegistry, $registryPath)) {
                @unlink($temporaryRegistry);
                throw new RuntimeException('Unable to activate CI trust-registry overlay');
            }

            $upsert = $pdo->prepare(
                "INSERT INTO system_settings"
                . " (setting_key,setting_value,setting_type,category,description,is_editable)"
                . " VALUES (:key,:value,'string','licensing',:description,0)"
                . " ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),"
                . " updated_at = CURRENT_TIMESTAMP"
            );
            $upsert->execute([
                ':key' => 'installation_id',
                ':value' => $installationId,
                ':description' => 'Ephemeral CI installation identifier',
            ]);
            $upsert->execute([
                ':key' => 'workspace_license_token',
                ':value' => $token,
                ':description' => 'Ephemeral CI signed license token',
            ]);

            $done = true;
        } finally {
            sodium_memzero($secretKey);
            sodium_memzero($keypair);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array{DBHOST:string,DBPORT:string,DBUSER:string,DBPASS:string,DBNAME:string} */
function workspaceCiLicenseEnvironment(string $root): array
{
    $values = [
        'DBHOST' => '127.0.0.1',
        'DBPORT' => '3306',
        'DBUSER' => 'root',
        'DBPASS' => '',
        'DBNAME' => '',
    ];

    $envFile = $root . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $values[$name] = trim(trim($value), "\"'");
        }
    }

    foreach (array_keys($values) as $name) {
        $runtime = getenv($name);
        if (is_string($runtime) && $runtime !== '') {
            $values[$name] = $runtime;
        }
    }

    return $values;
}

function workspaceCiLicenseUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

if (
    PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    workspaceEnsureCiLicense();
    fwrite(STDOUT, "[OK] ephemeral CI license activated when a test database was available\n");
}
