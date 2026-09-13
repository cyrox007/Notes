<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptMethods;
use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use RuntimeException;
use Throwable;

final class CryptoMigrationService
{
    public function __construct(private ?DatabaseManager $db = null)
    {
        $this->db ??= DatabaseManager::getInstance();
    }

    /** @return array{scanned:int,current:int,migrated:int,would_migrate:int,failed:int,last_id:int,failures:list<string>} */
    public function migrateMessenger(bool $dryRun = false, int $limit = 1000, int $afterId = 0): array
    {
        $limit = max(1, min(10000, $limit));
        $afterId = max(0, $afterId);
        $rows = $this->db->fetchAll(
            'SELECT id,uid,message FROM messages '
            . "WHERE id > {$afterId} AND message IS NOT NULL AND message <> '' ORDER BY id ASC LIMIT {$limit}"
        );

        $stats = $this->stats($afterId);
        foreach ($rows as $row) {
            $stats['scanned']++;
            $id = (int) $row['id'];
            $stats['last_id'] = $id;
            $uid = (string) $row['uid'];
            $payload = (string) $row['message'];

            try {
                if (str_starts_with($payload, 'v2:')) {
                    MessengerCrypto::decrypt($payload, $uid);
                    $stats['current']++;
                    continue;
                }

                $plaintext = MessengerCrypto::decrypt($payload, $uid);
                $replacement = MessengerCrypto::encrypt($plaintext, $uid);
                if ($dryRun) {
                    $stats['would_migrate']++;
                    continue;
                }

                $updated = $this->db->execute(
                    'UPDATE messages SET message = :replacement '
                    . 'WHERE id = :id AND uid = :uid AND message = :original',
                    [
                        ':replacement' => $replacement,
                        ':id' => $id,
                        ':uid' => $uid,
                        ':original' => $payload,
                    ]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Message changed concurrently; row was not migrated');
                }
                $stats['migrated']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failures'][] = "messenger:{$id}: " . $e->getMessage();
            }
        }

        return $stats;
    }

    /** @return array{scanned:int,current:int,migrated:int,would_migrate:int,failed:int,last_id:int,failures:list<string>} */
    public function migrateNotes(
        bool $dryRun = false,
        int $limit = 1000,
        bool $allowUnknownPlaintext = false,
        int $afterId = 0
    ): array {
        $limit = max(1, min(10000, $limit));
        $afterId = max(0, $afterId);
        $rows = $this->db->fetchAll(
            'SELECT id,uid,content,is_encrypted FROM notes '
            . "WHERE id > {$afterId} AND content IS NOT NULL AND content <> '' ORDER BY id ASC LIMIT {$limit}"
        );

        $stats = $this->stats($afterId);
        foreach ($rows as $row) {
            $stats['scanned']++;
            $id = (int) $row['id'];
            $stats['last_id'] = $id;
            $uid = (string) $row['uid'];
            $payload = (string) $row['content'];
            $encryptedFlag = (int) $row['is_encrypted'];

            try {
                if ($this->isCurrentNotePayload($payload, $uid)) {
                    $stats['current']++;
                    continue;
                }

                if ($encryptedFlag === 0) {
                    $plaintext = $payload;
                } else {
                    $legacy = $this->decryptLegacyDoubleNote($payload);
                    if ($legacy !== null) {
                        $plaintext = $legacy;
                    } elseif ($allowUnknownPlaintext) {
                        $plaintext = $payload;
                    } else {
                        throw new RuntimeException(
                            'Unknown encrypted note payload; review manually or rerun with --allow-plaintext-notes only after confirming it is plaintext'
                        );
                    }
                }

                $replacement = CryptMethods::encrypt($plaintext, $uid);
                if ($dryRun) {
                    $stats['would_migrate']++;
                    continue;
                }

                $updated = $this->db->execute(
                    'UPDATE notes SET content = :replacement, is_encrypted = 1 '
                    . 'WHERE id = :id AND uid = :uid AND content = :original AND is_encrypted = :encrypted_flag',
                    [
                        ':replacement' => $replacement,
                        ':id' => $id,
                        ':uid' => $uid,
                        ':original' => $payload,
                        ':encrypted_flag' => $encryptedFlag,
                    ]
                );
                if ($updated !== 1) {
                    throw new RuntimeException('Note changed concurrently; row was not migrated');
                }
                $stats['migrated']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $stats['failures'][] = "note:{$id}: " . $e->getMessage();
            }
        }

        return $stats;
    }

    private function isCurrentNotePayload(string $payload, string $uid): bool
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return false;
        }

        try {
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($data) || ($data['v'] ?? null) !== 1 || !isset($data['n'], $data['c'])) {
            return false;
        }

        CryptMethods::decrypt($payload, $uid);
        return true;
    }

    private function decryptLegacyDoubleNote(string $payload): ?string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        foreach (['iv1', 'tag1', 'enc1', 'iv2', 'enc2'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return null;
            }
        }

        $iv1 = base64_decode($data['iv1'], true);
        $tag1 = base64_decode($data['tag1'], true);
        $encrypted1 = base64_decode($data['enc1'], true);
        $iv2 = base64_decode($data['iv2'], true);
        $encrypted2 = base64_decode($data['enc2'], true);
        if ($iv1 === false || $tag1 === false || $encrypted1 === false || $iv2 === false || $encrypted2 === false) {
            return null;
        }
        if (strlen($iv1) !== 12 || strlen($iv2) !== 16 || strlen($tag1) !== 16) {
            return null;
        }

        $uniqueKey = (string) (getenv('UNIQUE_KEY') ?: '');
        if ($uniqueKey === '') {
            throw new RuntimeException('UNIQUE_KEY is required to migrate legacy notes');
        }
        $secondary = (string) (getenv('SECONDARY_KEY') ?: '');
        if ($secondary === '') {
            $secondary = hash('sha256', $uniqueKey . '_secondary_salt', true);
        }

        $decryptedLayer = openssl_decrypt(
            $encrypted2,
            'aes-256-cbc',
            $secondary,
            OPENSSL_RAW_DATA,
            $iv2
        );
        if ($decryptedLayer === false || !hash_equals($encrypted1, $decryptedLayer)) {
            throw new RuntimeException('Legacy note CBC layer authentication/consistency check failed');
        }

        $plaintext = openssl_decrypt(
            $encrypted1,
            'aes-256-gcm',
            $uniqueKey,
            OPENSSL_RAW_DATA,
            $iv1,
            $tag1
        );
        if ($plaintext === false) {
            throw new RuntimeException('Legacy note GCM authentication failed');
        }

        return $plaintext;
    }

    /** @return array{scanned:int,current:int,migrated:int,would_migrate:int,failed:int,last_id:int,failures:list<string>} */
    private function stats(int $afterId): array
    {
        return [
            'scanned' => 0,
            'current' => 0,
            'migrated' => 0,
            'would_migrate' => 0,
            'failed' => 0,
            'last_id' => $afterId,
            'failures' => [],
        ];
    }
}
