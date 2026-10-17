<?php

declare(strict_types=1);

use App\Services\LicenseVerifier;

/**
 * Устанавливает временную лицензию только для CI в текущую тестовую базу.
 *
 * Пара ключей подписи существует только в памяти runner. В рабочую копию CI
 * добавляется временный ОТКРЫТЫЙ ключ, поэтому проверка лицензии остаётся
 * полностью включённой во время браузерных и HTTP-тестов с изменением данных.
 * Закрытый ключ production не читается, не копируется и никуда не передаётся.
 */
function workspaceEnsureCiLicense(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!extension_loaded('sodium')) {
        throw new RuntimeException('Для временной CI-лицензии требуется расширение sodium');
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
        $connectionClass = PDO::class;
        if (is_file($root . '/core/PrefixPDO.php')) {
            require_once $root . '/core/PrefixPDO.php';
            $connectionClass = Core\PrefixPDO::class;
        }
        $pdo = new $connectionClass(
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
        throw new RuntimeException('Не удалось создать блокировку временной CI-лицензии');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Не удалось заблокировать подготовку временной CI-лицензии');
        }

        $keys = require $registryPath;
        if (!is_array($keys)) {
            throw new RuntimeException('Реестр доверенных ключей лицензии должен возвращать массив');
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
                'features' => [
                    'workspace.notes',
                    'workspace.tasks',
                    'workspace.calendar',
                    'workspace.files',
                    'workspace.messenger',
                    'workspace.profile',
                    'workspace.admin',
                ],
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
                throw new RuntimeException('Временная CI-лицензия не прошла самопроверку');
            }

            // Изменяется только одноразовая рабочая копия runner. Боевой открытый
            // корневой ключ остаётся на месте, закрытый CI-ключ на диск не записывается.
            $keys[$keyId] = $publicEncoded;
            $registry = "<?php\n\ndeclare(strict_types=1);\n\n"
                . "/** Наложение CI: боевые открытые ключи и один временный тестовый открытый ключ. */\n"
                . 'return ' . var_export($keys, true) . ";\n";
            $temporaryRegistry = $registryPath . '.ci-' . getmypid() . '.tmp';
            if (file_put_contents($temporaryRegistry, $registry, LOCK_EX) === false) {
                throw new RuntimeException('Не удалось записать временное наложение реестра доверенных ключей CI');
            }
            if (!rename($temporaryRegistry, $registryPath)) {
                @unlink($temporaryRegistry);
                throw new RuntimeException('Не удалось активировать временное наложение реестра доверенных ключей CI');
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
                ':description' => 'Временный идентификатор установки CI',
            ]);
            $upsert->execute([
                ':key' => 'workspace_license_token',
                ':value' => $token,
                ':description' => 'Временный подписанный токен лицензии CI',
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
    fwrite(STDOUT, "[OK] временная CI-лицензия активирована при наличии тестовой базы\n");
}
