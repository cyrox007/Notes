<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CryptMethods;
use App\Helpers\MessengerCrypto;
use Core\DatabaseManager;
use RuntimeException;
use Throwable;

final class DataKeyRotationService
{
    private const STATE_SCHEMA = 1;
    private const MAX_STATE_BYTES = 131072;

    public function __construct(
        private ?DatabaseManager $db = null,
        private ?MaintenanceModeService $maintenance = null,
        private ?string $explicitStateRoot = null,
        private ?string $appRoot = null,
    ) {
        $this->db ??= DatabaseManager::getInstance();
        $this->maintenance ??= new MaintenanceModeService();
        $resolved = realpath($this->appRoot ?? (defined('SITEPATH') ? SITEPATH : dirname(__DIR__, 2)));
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Application root cannot be resolved for data-key rotation');
        }
        $this->appRoot = $this->normalizePath($resolved);
    }

    /**
     * @param array{old_unique?:string,new_unique?:string,old_msg?:string,new_msg?:string} $secrets
     * @return array<string,mixed>
     */
    public function run(
        string $transactionId,
        string $scope,
        array $secrets,
        int $batchSize = 250,
        int $maxBatches = 0,
        bool $rollback = false,
    ): array {
        $transactionId = trim($transactionId);
        $scope = strtolower(trim($scope));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) !== 1) {
            throw new RuntimeException('Invalid data-key rotation transaction id');
        }
        if (!in_array($scope, ['all', 'notes', 'messenger'], true)) {
            throw new RuntimeException('Rotation scope must be all, notes or messenger');
        }
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new RuntimeException('Rotation batch size must be between 1 and 5000');
        }
        if ($maxBatches < 0 || $maxBatches > 100000) {
            throw new RuntimeException('Rotation max batches must be between 0 and 100000');
        }

        $this->assertMaintenanceOwned($transactionId);
        $normalized = $this->normalizeSecrets($scope, $secrets);
        $this->assertActiveOldSecrets($scope, $normalized);

        $source = [
            'unique' => $rollback ? ($normalized['new_unique'] ?? null) : ($normalized['old_unique'] ?? null),
            'msg' => $rollback ? ($normalized['new_msg'] ?? null) : ($normalized['old_msg'] ?? null),
        ];
        $target = [
            'unique' => $rollback ? ($normalized['old_unique'] ?? null) : ($normalized['new_unique'] ?? null),
            'msg' => $rollback ? ($normalized['old_msg'] ?? null) : ($normalized['new_msg'] ?? null),
        ];
        $direction = $rollback ? 'rollback' : 'forward';
        $identity = $this->rotationIdentity($scope, $normalized, $direction);
        $statePath = $this->statePath($transactionId, $direction, true);

        return $this->withExclusiveLock($statePath, function () use (
            $transactionId,
            $scope,
            $source,
            $target,
            $normalized,
            $direction,
            $identity,
            $statePath,
            $batchSize,
            $maxBatches,
            $rollback,
        ): array {
            $state = $this->loadOrCreateState(
                $statePath,
                $transactionId,
                $scope,
                $direction,
                $identity
            );

            // Preflight is intentionally repeated on resume. Mixed old/new rows
            // are valid during an interrupted operation and must authenticate
            // with exactly one of the supplied key generations before writes.
            $preflight = $this->preflight($scope, $source, $target, $normalized, $rollback);
            $state['preflight'] = $preflight;
            $state['updated_at'] = time();
            $this->writeState($statePath, $state);

            $batches = 0;
            $limitReached = false;

            if ($scope === 'all' || $scope === 'notes') {
                if (empty($state['complete']['notes'])) {
                    $limitReached = $this->rotateNotes(
                        $state,
                        $statePath,
                        (string) $source['unique'],
                        (string) $target['unique'],
                        $batchSize,
                        $maxBatches,
                        $batches
                    );
                }
                if (!$limitReached && !empty($state['complete']['notes']) && empty($state['complete']['note_history'])) {
                    $limitReached = $this->rotateNoteHistory(
                        $state,
                        $statePath,
                        (string) $source['unique'],
                        (string) $target['unique'],
                        (string) $normalized['old_unique'],
                        $rollback,
                        $batchSize,
                        $maxBatches,
                        $batches
                    );
                }
            }

            if (!$limitReached && ($scope === 'all' || $scope === 'notes') && empty($state['complete']['totp'])) {
                $limitReached = $this->rotateTotpSecrets(
                    $state,
                    $statePath,
                    (string) $source['unique'],
                    (string) $target['unique'],
                    $batchSize,
                    $maxBatches,
                    $batches
                );
            }

            if (!$limitReached && ($scope === 'all' || $scope === 'messenger') && empty($state['complete']['messenger'])) {
                $limitReached = $this->rotateMessenger(
                    $state,
                    $statePath,
                    (string) $source['msg'],
                    (string) $target['msg'],
                    $batchSize,
                    $maxBatches,
                    $batches
                );
            }

            $complete = $this->requestedScopesComplete($scope, $state);
            if ($complete) {
                $verification = $this->verifyTarget($scope, $target, $normalized, $rollback);
                $state['verification'] = $verification;
                $state['verified'] = true;
                $state['completed_at'] ??= time();
                $state['updated_at'] = time();
                $this->writeState($statePath, $state);
            }

            return [
                'status' => 'ok',
                'transaction_id' => $transactionId,
                'scope' => $scope,
                'direction' => $direction,
                'complete' => $complete,
                'verified' => !empty($state['verified']),
                'resume_required' => !$complete,
                'batches_this_run' => $batches,
                'state_path' => $statePath,
                'checkpoints' => $state['checkpoints'],
                'counts' => $state['counts'],
                'fingerprints' => $identity,
            ];
        });
    }

    private function assertMaintenanceOwned(string $transactionId): void
    {
        $state = $this->maintenance->state();
        if (!$state['active'] || !$state['valid']) {
            throw new RuntimeException('Data-key rotation requires valid active maintenance mode');
        }
        if (!hash_equals((string) $state['transaction_id'], $transactionId)) {
            throw new RuntimeException('Maintenance mode belongs to another transaction');
        }
    }

    /** @param array<string,string> $secrets @return array<string,string> */
    private function normalizeSecrets(string $scope, array $secrets): array
    {
        $required = [];
        if ($scope === 'all' || $scope === 'notes') {
            $required = array_merge($required, ['old_unique', 'new_unique']);
        }
        if ($scope === 'all' || $scope === 'messenger') {
            $required = array_merge($required, ['old_msg', 'new_msg']);
        }

        $normalized = [];
        foreach ($required as $name) {
            $value = trim((string) ($secrets[$name] ?? ''));
            if (strlen($value) < 32) {
                throw new RuntimeException("Rotation secret {$name} must contain at least 32 characters");
            }
            $normalized[$name] = $value;
        }

        if (isset($normalized['old_unique'], $normalized['new_unique'])
            && hash_equals($this->fingerprint($normalized['old_unique']), $this->fingerprint($normalized['new_unique']))) {
            throw new RuntimeException('Old and new UNIQUE_KEY values must differ');
        }
        if (isset($normalized['old_msg'], $normalized['new_msg'])
            && hash_equals($this->fingerprint($normalized['old_msg']), $this->fingerprint($normalized['new_msg']))) {
            throw new RuntimeException('Old and new MSG_SECRET_KEY values must differ');
        }

        return $normalized;
    }

    /** @param array<string,string> $secrets */
    private function assertActiveOldSecrets(string $scope, array $secrets): void
    {
        if ($scope === 'all' || $scope === 'notes') {
            $active = trim((string) (getenv('UNIQUE_KEY') ?: ''));
            if ($active === '' || !hash_equals($this->fingerprint($active), $this->fingerprint($secrets['old_unique']))) {
                throw new RuntimeException('Old UNIQUE_KEY file does not match the active installation secret');
            }
        }
        if ($scope === 'all' || $scope === 'messenger') {
            $active = trim((string) (getenv('MSG_SECRET_KEY') ?: ''));
            if ($active === '' || !hash_equals($this->fingerprint($active), $this->fingerprint($secrets['old_msg']))) {
                throw new RuntimeException('Old MSG_SECRET_KEY file does not match the active installation secret');
            }
        }
    }

    /** @param array<string,string> $secrets @return array<string,string|null> */
    private function rotationIdentity(string $scope, array $secrets, string $direction): array
    {
        return [
            'direction' => $direction,
            'old_unique_sha256' => isset($secrets['old_unique']) ? $this->fingerprint($secrets['old_unique']) : null,
            'new_unique_sha256' => isset($secrets['new_unique']) ? $this->fingerprint($secrets['new_unique']) : null,
            'old_msg_sha256' => isset($secrets['old_msg']) ? $this->fingerprint($secrets['old_msg']) : null,
            'new_msg_sha256' => isset($secrets['new_msg']) ? $this->fingerprint($secrets['new_msg']) : null,
            'scope' => $scope,
        ];
    }

    private function fingerprint(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @param array{unique:?string,msg:?string} $source
     * @param array{unique:?string,msg:?string} $target
     * @param array<string,string> $normalized
     * @return array<string,int>
     */
    private function preflight(string $scope, array $source, array $target, array $normalized, bool $rollback): array
    {
        $stats = ['notes' => 0, 'note_history_fields' => 0, 'totp_secrets' => 0, 'messenger' => 0, 'plaintext_history_fields' => 0];

        if ($scope === 'all' || $scope === 'notes') {
            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,content,is_encrypted FROM notes '
                    . "WHERE id > {$after} AND content IS NOT NULL AND content <> '' ORDER BY id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    if ((int) $row['is_encrypted'] !== 1) {
                        continue;
                    }
                    $payload = (string) $row['content'];
                    if (!CryptMethods::isCurrentPayload($payload)) {
                        throw new RuntimeException(
                            'Note ' . $after . ' is not current encrypted format; run bin/migrate_crypto.php before key rotation'
                        );
                    }
                    $this->authenticateNoteEither(
                        $payload,
                        (string) $row['uid'],
                        (string) $source['unique'],
                        (string) $target['unique']
                    );
                    $stats['notes']++;
                }
            } while ($rows !== []);

            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT h.id,n.uid,h.old_content,h.new_content '
                    . 'FROM note_history h INNER JOIN notes n ON n.id=h.note_id '
                    . "WHERE h.id > {$after} ORDER BY h.id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    foreach (['old_content', 'new_content'] as $field) {
                        $payload = $row[$field];
                        if (!is_string($payload) || $payload === '') {
                            continue;
                        }
                        if (CryptMethods::isCurrentPayload($payload)) {
                            $this->authenticateNoteEither(
                                $payload,
                                (string) $row['uid'],
                                (string) $source['unique'],
                                (string) $target['unique']
                            );
                            $stats['note_history_fields']++;
                            continue;
                        }
                        if ($this->isLegacyDoubleNotePayload($payload)) {
                            // Legacy history remains old-key dependent until forward
                            // conversion. During rollback it is already at target.
                            $this->decryptLegacyDoubleNote($payload, (string) $normalized['old_unique']);
                            $stats['note_history_fields']++;
                            continue;
                        }
                        $stats['plaintext_history_fields']++;
                    }
                }
            } while ($rows !== []);

            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,totp_secret FROM users '
                    . "WHERE id > {$after} AND totp_enabled=1 AND totp_secret IS NOT NULL AND totp_secret <> '' "
                    . 'ORDER BY id ASC LIMIT 1000'
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    $payload = (string) $row['totp_secret'];
                    if (!CryptMethods::isCurrentPayload($payload)) {
                        throw new RuntimeException(
                            'TOTP-секрет пользователя ' . $after . ' имеет неподдерживаемый формат шифрования'
                        );
                    }
                    $this->authenticateUniqueEither(
                        $payload,
                        $this->totpAad((string) $row['uid']),
                        (string) $source['unique'],
                        (string) $target['unique']
                    );
                    $stats['totp_secrets']++;
                }
            } while ($rows !== []);
        }

        if ($scope === 'all' || $scope === 'messenger') {
            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,message FROM messages '
                    . "WHERE id > {$after} AND message IS NOT NULL AND message <> '' ORDER BY id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    $payload = (string) $row['message'];
                    if (!MessengerCrypto::isCurrentPayload($payload)) {
                        throw new RuntimeException(
                            'Messenger message ' . $after . ' is not current v2 format; run bin/migrate_crypto.php before key rotation'
                        );
                    }
                    $this->authenticateMessengerEither(
                        $payload,
                        (string) $row['uid'],
                        (string) $source['msg'],
                        (string) $target['msg']
                    );
                    $stats['messenger']++;
                }
            } while ($rows !== []);
        }

        return $stats;
    }

    /** @param array<string,mixed> $state */
    private function rotateNotes(
        array &$state,
        string $statePath,
        string $sourceSecret,
        string $targetSecret,
        int $batchSize,
        int $maxBatches,
        int &$batches,
    ): bool {
        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                return true;
            }
            $after = (int) $state['checkpoints']['notes'];
            $rows = $this->db->fetchAll(
                'SELECT id,uid,content,is_encrypted FROM notes '
                . "WHERE id > {$after} AND content IS NOT NULL AND content <> '' AND is_encrypted=1 "
                . "ORDER BY id ASC LIMIT {$batchSize}"
            );
            if ($rows === []) {
                $state['complete']['notes'] = true;
                $state['updated_at'] = time();
                $this->writeState($statePath, $state);
                return false;
            }

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $uid = (string) $row['uid'];
                    $payload = (string) $row['content'];
                    $plaintext = $this->tryNoteDecrypt($payload, $uid, $targetSecret);
                    if ($plaintext !== null) {
                        $state['counts']['notes_already_target']++;
                    } else {
                        $plaintext = CryptMethods::decryptWithSecret($payload, $uid, $sourceSecret);
                        $replacement = CryptMethods::encryptWithSecret($plaintext, $uid, $targetSecret);
                        if (!hash_equals($plaintext, CryptMethods::decryptWithSecret($replacement, $uid, $targetSecret))) {
                            throw new RuntimeException("Note {$id} target-key verification failed");
                        }
                        $updated = $this->db->execute(
                            'UPDATE notes SET content=:replacement WHERE id=:id AND content=:original',
                            [':replacement' => $replacement, ':id' => $id, ':original' => $payload]
                        );
                        if ($updated !== 1) {
                            throw new RuntimeException("Note {$id} changed concurrently during key rotation");
                        }
                        $state['counts']['notes_converted']++;
                    }
                    $state['checkpoints']['notes'] = $id;
                }
                $this->db->endTransaction(true);
            } catch (Throwable $e) {
                $this->db->endTransaction(false);
                throw $e;
            }

            $batches++;
            $state['updated_at'] = time();
            $this->writeState($statePath, $state);
        }
    }

    /** @param array<string,mixed> $state */
    private function rotateNoteHistory(
        array &$state,
        string $statePath,
        string $sourceSecret,
        string $targetSecret,
        string $originalOldSecret,
        bool $rollback,
        int $batchSize,
        int $maxBatches,
        int &$batches,
    ): bool {
        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                return true;
            }
            $after = (int) $state['checkpoints']['note_history'];
            $rows = $this->db->fetchAll(
                'SELECT h.id,n.uid,h.old_content,h.new_content '
                . 'FROM note_history h INNER JOIN notes n ON n.id=h.note_id '
                . "WHERE h.id > {$after} ORDER BY h.id ASC LIMIT {$batchSize}"
            );
            if ($rows === []) {
                $state['complete']['note_history'] = true;
                $state['updated_at'] = time();
                $this->writeState($statePath, $state);
                return false;
            }

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $uid = (string) $row['uid'];
                    $old = is_string($row['old_content']) ? $row['old_content'] : null;
                    $new = is_string($row['new_content']) ? $row['new_content'] : null;
                    [$oldReplacement, $oldChanged, $oldKind] = $this->rotateHistoryValue(
                        $old, $uid, $sourceSecret, $targetSecret, $originalOldSecret, $rollback
                    );
                    [$newReplacement, $newChanged, $newKind] = $this->rotateHistoryValue(
                        $new, $uid, $sourceSecret, $targetSecret, $originalOldSecret, $rollback
                    );

                    if ($oldChanged || $newChanged) {
                        $this->db->execute(
                            'UPDATE note_history SET old_content=:old_content,new_content=:new_content WHERE id=:id',
                            [':old_content' => $oldReplacement, ':new_content' => $newReplacement, ':id' => $id]
                        );
                    }
                    foreach ([$oldKind, $newKind] as $kind) {
                        if ($kind === 'converted') {
                            $state['counts']['history_fields_converted']++;
                        } elseif ($kind === 'target') {
                            $state['counts']['history_fields_already_target']++;
                        } elseif ($kind === 'plaintext') {
                            $state['counts']['history_fields_plaintext']++;
                        }
                    }
                    $state['checkpoints']['note_history'] = $id;
                }
                $this->db->endTransaction(true);
            } catch (Throwable $e) {
                $this->db->endTransaction(false);
                throw $e;
            }

            $batches++;
            $state['updated_at'] = time();
            $this->writeState($statePath, $state);
        }
    }

    /** @param array<string,mixed> $state */
    private function rotateTotpSecrets(
        array &$state,
        string $statePath,
        string $sourceSecret,
        string $targetSecret,
        int $batchSize,
        int $maxBatches,
        int &$batches,
    ): bool {
        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                return true;
            }

            $after = (int) ($state['checkpoints']['totp_users'] ?? 0);
            $rows = $this->db->fetchAll(
                'SELECT id,uid,totp_secret FROM users '
                . "WHERE id > {$after} AND totp_enabled=1 AND totp_secret IS NOT NULL AND totp_secret <> '' "
                . "ORDER BY id ASC LIMIT {$batchSize}"
            );
            if ($rows === []) {
                $state['complete']['totp'] = true;
                $state['updated_at'] = time();
                $this->writeState($statePath, $state);
                return false;
            }

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $uid = (string) $row['uid'];
                    $payload = (string) $row['totp_secret'];
                    $aad = $this->totpAad($uid);

                    $plaintext = $this->tryUniqueDecrypt($payload, $aad, $targetSecret);
                    if ($plaintext !== null) {
                        $state['counts']['totp_already_target']++;
                    } else {
                        $plaintext = CryptMethods::decryptWithSecret($payload, $aad, $sourceSecret);
                        if (preg_match('/^[A-Z2-7]{16,128}$/D', $plaintext) !== 1) {
                            throw new RuntimeException("TOTP-секрет пользователя {$id} после расшифровки некорректен");
                        }

                        $replacement = CryptMethods::encryptWithSecret($plaintext, $aad, $targetSecret);
                        if (!hash_equals(
                            $plaintext,
                            CryptMethods::decryptWithSecret($replacement, $aad, $targetSecret)
                        )) {
                            throw new RuntimeException("Проверка TOTP-секрета пользователя {$id} новым ключом не пройдена");
                        }

                        $updated = $this->db->execute(
                            'UPDATE users SET totp_secret=:replacement WHERE id=:id AND totp_secret=:original',
                            [':replacement' => $replacement, ':id' => $id, ':original' => $payload]
                        );
                        if ($updated !== 1) {
                            throw new RuntimeException(
                                "TOTP-секрет пользователя {$id} изменился параллельно во время ротации ключа"
                            );
                        }
                        $state['counts']['totp_converted']++;
                    }

                    $state['checkpoints']['totp_users'] = $id;
                }
                $this->db->endTransaction(true);
            } catch (Throwable $e) {
                $this->db->endTransaction(false);
                throw $e;
            }

            $batches++;
            $state['updated_at'] = time();
            $this->writeState($statePath, $state);
        }
    }

    /** @param array<string,mixed> $state */
    private function rotateMessenger(
        array &$state,
        string $statePath,
        string $sourceSecret,
        string $targetSecret,
        int $batchSize,
        int $maxBatches,
        int &$batches,
    ): bool {
        while (true) {
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                return true;
            }
            $after = (int) $state['checkpoints']['messenger'];
            $rows = $this->db->fetchAll(
                'SELECT id,uid,message FROM messages '
                . "WHERE id > {$after} AND message IS NOT NULL AND message <> '' ORDER BY id ASC LIMIT {$batchSize}"
            );
            if ($rows === []) {
                $state['complete']['messenger'] = true;
                $state['updated_at'] = time();
                $this->writeState($statePath, $state);
                return false;
            }

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $uid = (string) $row['uid'];
                    $payload = (string) $row['message'];
                    $plaintext = $this->tryMessengerDecrypt($payload, $uid, $targetSecret);
                    if ($plaintext !== null) {
                        $state['counts']['messenger_already_target']++;
                    } else {
                        $plaintext = MessengerCrypto::decryptCurrentWithSecret($payload, $uid, $sourceSecret);
                        $replacement = MessengerCrypto::encryptWithSecret($plaintext, $uid, $targetSecret);
                        if (!hash_equals($plaintext, MessengerCrypto::decryptCurrentWithSecret($replacement, $uid, $targetSecret))) {
                            throw new RuntimeException("Messenger message {$id} target-key verification failed");
                        }
                        $updated = $this->db->execute(
                            'UPDATE messages SET message=:replacement WHERE id=:id AND uid=:uid AND message=:original',
                            [':replacement' => $replacement, ':id' => $id, ':uid' => $uid, ':original' => $payload]
                        );
                        if ($updated !== 1) {
                            throw new RuntimeException("Messenger message {$id} changed concurrently during key rotation");
                        }
                        $state['counts']['messenger_converted']++;
                    }
                    $state['checkpoints']['messenger'] = $id;
                }
                $this->db->endTransaction(true);
            } catch (Throwable $e) {
                $this->db->endTransaction(false);
                throw $e;
            }

            $batches++;
            $state['updated_at'] = time();
            $this->writeState($statePath, $state);
        }
    }

    /**
     * @return array{0:?string,1:bool,2:string}
     */
    private function rotateHistoryValue(
        ?string $payload,
        string $uid,
        string $sourceSecret,
        string $targetSecret,
        string $originalOldSecret,
        bool $rollback,
    ): array {
        if ($payload === null || $payload === '') {
            return [$payload, false, 'empty'];
        }

        if (CryptMethods::isCurrentPayload($payload)) {
            $targetPlaintext = $this->tryNoteDecrypt($payload, $uid, $targetSecret);
            if ($targetPlaintext !== null) {
                return [$payload, false, 'target'];
            }
            $plaintext = CryptMethods::decryptWithSecret($payload, $uid, $sourceSecret);
            $replacement = CryptMethods::encryptWithSecret($plaintext, $uid, $targetSecret);
            if (!hash_equals($plaintext, CryptMethods::decryptWithSecret($replacement, $uid, $targetSecret))) {
                throw new RuntimeException('Note history target-key verification failed');
            }
            return [$replacement, true, 'converted'];
        }

        if ($this->isLegacyDoubleNotePayload($payload)) {
            $plaintext = $this->decryptLegacyDoubleNote($payload, $originalOldSecret);
            if ($rollback) {
                // Legacy history is already old-key dependent, which is the
                // rollback target. Preserve its exact bytes.
                return [$payload, false, 'target'];
            }
            $replacement = CryptMethods::encryptWithSecret($plaintext, $uid, $targetSecret);
            return [$replacement, true, 'converted'];
        }

        // Historical pre-encryption snapshots can be plaintext. They are not
        // bound to UNIQUE_KEY and therefore do not participate in key rotation.
        return [$payload, false, 'plaintext'];
    }

    private function authenticateNoteEither(string $payload, string $uid, string $sourceSecret, string $targetSecret): void
    {
        $this->authenticateUniqueEither($payload, $uid, $sourceSecret, $targetSecret);
    }

    private function authenticateUniqueEither(
        string $payload,
        string $aad,
        string $sourceSecret,
        string $targetSecret
    ): void {
        if ($this->tryUniqueDecrypt($payload, $aad, $targetSecret) !== null) {
            return;
        }
        CryptMethods::decryptWithSecret($payload, $aad, $sourceSecret);
    }

    private function authenticateMessengerEither(string $payload, string $uid, string $sourceSecret, string $targetSecret): void
    {
        if ($this->tryMessengerDecrypt($payload, $uid, $targetSecret) !== null) {
            return;
        }
        MessengerCrypto::decryptCurrentWithSecret($payload, $uid, $sourceSecret);
    }

    private function tryNoteDecrypt(string $payload, string $uid, string $secret): ?string
    {
        return $this->tryUniqueDecrypt($payload, $uid, $secret);
    }

    private function tryUniqueDecrypt(string $payload, string $aad, string $secret): ?string
    {
        try {
            return CryptMethods::decryptWithSecret($payload, $aad, $secret);
        } catch (Throwable) {
            return null;
        }
    }

    private function totpAad(string $uid): string
    {
        return 'two-factor-totp-secret:' . trim($uid);
    }

    private function tryMessengerDecrypt(string $payload, string $uid, string $secret): ?string
    {
        try {
            return MessengerCrypto::decryptCurrentWithSecret($payload, $uid, $secret);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array{unique:?string,msg:?string} $target
     * @param array<string,string> $normalized
     * @return array<string,int>
     */
    private function verifyTarget(string $scope, array $target, array $normalized, bool $rollback): array
    {
        $verified = ['notes' => 0, 'note_history_fields' => 0, 'totp_secrets' => 0, 'messenger' => 0, 'plaintext_history_fields' => 0];

        if ($scope === 'all' || $scope === 'notes') {
            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,content,is_encrypted FROM notes '
                    . "WHERE id > {$after} AND content IS NOT NULL AND content <> '' ORDER BY id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    if ((int) $row['is_encrypted'] !== 1) {
                        continue;
                    }
                    if (!CryptMethods::isCurrentPayload((string) $row['content'])) {
                        throw new RuntimeException("Final verification found non-current encrypted note {$after}");
                    }
                    CryptMethods::decryptWithSecret(
                        (string) $row['content'],
                        (string) $row['uid'],
                        (string) $target['unique']
                    );
                    $verified['notes']++;
                }
            } while ($rows !== []);

            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT h.id,n.uid,h.old_content,h.new_content '
                    . 'FROM note_history h INNER JOIN notes n ON n.id=h.note_id '
                    . "WHERE h.id > {$after} ORDER BY h.id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    foreach (['old_content', 'new_content'] as $field) {
                        $payload = $row[$field];
                        if (!is_string($payload) || $payload === '') {
                            continue;
                        }
                        if (CryptMethods::isCurrentPayload($payload)) {
                            CryptMethods::decryptWithSecret($payload, (string) $row['uid'], (string) $target['unique']);
                            $verified['note_history_fields']++;
                            continue;
                        }
                        if ($this->isLegacyDoubleNotePayload($payload)) {
                            if (!$rollback) {
                                throw new RuntimeException("Final verification found legacy note-history ciphertext at row {$after}");
                            }
                            $this->decryptLegacyDoubleNote($payload, (string) $normalized['old_unique']);
                            $verified['note_history_fields']++;
                            continue;
                        }
                        $verified['plaintext_history_fields']++;
                    }
                }
            } while ($rows !== []);

            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,totp_secret FROM users '
                    . "WHERE id > {$after} AND totp_enabled=1 AND totp_secret IS NOT NULL AND totp_secret <> '' "
                    . 'ORDER BY id ASC LIMIT 1000'
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    CryptMethods::decryptWithSecret(
                        (string) $row['totp_secret'],
                        $this->totpAad((string) $row['uid']),
                        (string) $target['unique']
                    );
                    $verified['totp_secrets']++;
                }
            } while ($rows !== []);
        }

        if ($scope === 'all' || $scope === 'messenger') {
            $after = 0;
            do {
                $rows = $this->db->fetchAll(
                    'SELECT id,uid,message FROM messages '
                    . "WHERE id > {$after} AND message IS NOT NULL AND message <> '' ORDER BY id ASC LIMIT 1000"
                );
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    if (!MessengerCrypto::isCurrentPayload((string) $row['message'])) {
                        throw new RuntimeException("Final verification found non-v2 Messenger ciphertext at row {$after}");
                    }
                    MessengerCrypto::decryptCurrentWithSecret(
                        (string) $row['message'],
                        (string) $row['uid'],
                        (string) $target['msg']
                    );
                    $verified['messenger']++;
                }
            } while ($rows !== []);
        }

        return $verified;
    }

    private function requestedScopesComplete(string $scope, array $state): bool
    {
        $notes = !empty($state['complete']['notes'])
            && !empty($state['complete']['note_history'])
            && !empty($state['complete']['totp']);
        $messenger = !empty($state['complete']['messenger']);

        return match ($scope) {
            'notes' => $notes,
            'messenger' => $messenger,
            default => $notes && $messenger,
        };
    }

    private function isLegacyDoubleNotePayload(string $payload): bool
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
        if (!is_array($data)) {
            return false;
        }
        foreach (['iv1', 'tag1', 'enc1', 'iv2', 'enc2'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                return false;
            }
        }
        return true;
    }

    private function decryptLegacyDoubleNote(string $payload, string $uniqueKey): string
    {
        $data = json_decode((string) base64_decode($payload, true), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Malformed legacy note history payload');
        }

        $iv1 = base64_decode((string) $data['iv1'], true);
        $tag1 = base64_decode((string) $data['tag1'], true);
        $encrypted1 = base64_decode((string) $data['enc1'], true);
        $iv2 = base64_decode((string) $data['iv2'], true);
        $encrypted2 = base64_decode((string) $data['enc2'], true);
        if ($iv1 === false || $tag1 === false || $encrypted1 === false || $iv2 === false || $encrypted2 === false) {
            throw new RuntimeException('Malformed legacy note history fields');
        }

        $secondary = trim((string) (getenv('SECONDARY_KEY') ?: ''));
        if ($secondary === '') {
            $secondary = hash('sha256', $uniqueKey . '_secondary_salt', true);
        }
        $layer = openssl_decrypt($encrypted2, 'aes-256-cbc', $secondary, OPENSSL_RAW_DATA, $iv2);
        if ($layer === false || !hash_equals($encrypted1, $layer)) {
            throw new RuntimeException('Legacy note history CBC layer verification failed');
        }

        $plaintext = openssl_decrypt($encrypted1, 'aes-256-gcm', $uniqueKey, OPENSSL_RAW_DATA, $iv1, $tag1);
        if ($plaintext === false) {
            throw new RuntimeException('Legacy note history GCM authentication failed');
        }
        return $plaintext;
    }

    /** @param array<string,string|null> $identity @return array<string,mixed> */
    private function loadOrCreateState(
        string $path,
        string $transactionId,
        string $scope,
        string $direction,
        array $identity,
    ): array {
        if (!file_exists($path)) {
            $now = time();
            return [
                'schema' => self::STATE_SCHEMA,
                'transaction_id' => $transactionId,
                'scope' => $scope,
                'direction' => $direction,
                'fingerprints' => $identity,
                'checkpoints' => ['notes' => 0, 'note_history' => 0, 'totp_users' => 0, 'messenger' => 0],
                'complete' => ['notes' => false, 'note_history' => false, 'totp' => false, 'messenger' => false],
                'counts' => [
                    'notes_converted' => 0,
                    'notes_already_target' => 0,
                    'history_fields_converted' => 0,
                    'history_fields_already_target' => 0,
                    'history_fields_plaintext' => 0,
                    'totp_converted' => 0,
                    'totp_already_target' => 0,
                    'messenger_converted' => 0,
                    'messenger_already_target' => 0,
                ],
                'preflight' => [],
                'verification' => [],
                'verified' => false,
                'started_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
            ];
        }

        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Data-key rotation state path is unsafe');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > self::MAX_STATE_BYTES) {
            throw new RuntimeException('Data-key rotation state has invalid size');
        }
        $bytes = file_get_contents($path);
        $state = is_string($bytes) ? json_decode($bytes, true, 32, JSON_THROW_ON_ERROR) : null;
        if (!is_array($state)
            || ($state['schema'] ?? null) !== self::STATE_SCHEMA
            || ($state['transaction_id'] ?? null) !== $transactionId
            || ($state['scope'] ?? null) !== $scope
            || ($state['direction'] ?? null) !== $direction
            || ($state['fingerprints'] ?? null) !== $identity
            || !is_array($state['checkpoints'] ?? null)
            || !is_array($state['complete'] ?? null)
            || !is_array($state['counts'] ?? null)) {
            throw new RuntimeException('Data-key rotation state does not match this operation');
        }

        $state['checkpoints'] += ['totp_users' => 0];
        $state['complete'] += ['totp' => false];
        $state['counts'] += ['totp_converted' => 0, 'totp_already_target' => 0];

        return $state;
    }

    /** @param array<string,mixed> $state */
    private function writeState(string $path, array $state): void
    {
        $bytes = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (strlen($bytes) > self::MAX_STATE_BYTES) {
            throw new RuntimeException('Data-key rotation state exceeds safety limit');
        }

        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.key-rotation-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($tmp, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary data-key rotation state');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete data-key rotation state');
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
            throw new RuntimeException('Cannot atomically replace data-key rotation state');
        }
    }

    /** @return mixed */
    private function withExclusiveLock(string $statePath, callable $callback): mixed
    {
        $lockPath = $statePath . '.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('Data-key rotation lock path is unsafe');
        }
        $oldUmask = umask(0077);
        $lock = @fopen($lockPath, 'c');
        umask($oldUmask);
        if ($lock === false) {
            throw new RuntimeException('Cannot open data-key rotation lock');
        }
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another data-key rotation process is active for this transaction');
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function statePath(string $transactionId, string $direction, bool $createRoot): string
    {
        $root = trim((string) ($this->explicitStateRoot ?? ''));
        if ($root === '') {
            $root = trim((string) (getenv('DATA_KEY_ROTATION_STATE_PATH') ?: ''));
        }
        if ($root === '') {
            $updateRoot = trim((string) (getenv('UPDATE_STATE_PATH') ?: ''));
            if ($updateRoot !== '') {
                $root = rtrim($updateRoot, '/\\') . DIRECTORY_SEPARATOR . 'data-key-rotation';
            }
        }
        if ($root === '') {
            $private = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
            if ($private !== '') {
                $root = rtrim($private, '/\\') . DIRECTORY_SEPARATOR . 'key-rotation';
            }
        }
        if ($root === '') {
            throw new RuntimeException('DATA_KEY_ROTATION_STATE_PATH, UPDATE_STATE_PATH or PRIVATE_STORAGE_PATH is required');
        }
        if (!$this->isAbsolutePath($root) || is_link($root)) {
            throw new RuntimeException('Data-key rotation state root must be an absolute non-symlink path');
        }
        if (!is_dir($root) && $createRoot) {
            $oldUmask = umask(0077);
            $made = @mkdir($root, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($root)) {
                throw new RuntimeException('Cannot create data-key rotation state root');
            }
        }
        @chmod($root, 0700);

        $resolved = realpath($root);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Data-key rotation state root cannot be resolved or is not writable');
        }
        $resolved = $this->normalizePath($resolved);
        if ($this->pathInside($resolved, (string) $this->appRoot)) {
            throw new RuntimeException('Data-key rotation state root must be outside the live application tree');
        }

        return $resolved . DIRECTORY_SEPARATOR . 'rotation-' . $transactionId . '-' . $direction . '.json';
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private function normalizePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function pathInside(string $path, string $parent): bool
    {
        $path = $this->normalizePath($path);
        $parent = $this->normalizePath($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
