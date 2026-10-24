<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Изменение только собственного черновика. Версия, команда и вся бригада
 * записываются одной транзакцией: частичное сохранение исключено.
 */
final class WorkPermitDraftEditService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,version:int,replayed:bool}
     */
    public function save(int $documentId, int $actor, array $data): array
    {
        if ($documentId < 1 || $actor < 1) {
            throw new InvalidArgumentException('Неизвестный документ или пользователь');
        }
        $version = $this->number($data['version'] ?? null, 'version');
        $command = $data['command_id'] ?? null;
        if (!is_string($command) || preg_match('/^[a-f0-9]{32}$/D', $command) !== 1) {
            throw new InvalidArgumentException('Необходим идентификатор команды из 32 шестнадцатеричных символов');
        }
        $description = $data['work_description'] ?? null;
        if (!is_string($description) || trim($description) === ''
            || mb_strlen(trim($description)) > 10000) {
            throw new InvalidArgumentException('Задание должно содержать от 1 до 10000 символов');
        }
        $description = trim($description);

        // Отпечаток не зависит от последующих переименований или архивирования.
        $fingerprint = hash('sha256', json_encode([
            $documentId, $actor, $version, $data['organization_id'] ?? null,
            $data['department_id'] ?? null, $data['facility_id'] ?? null,
            $description, $data['roles'] ?? null, $data['member_ids'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $existing = $this->replay($command, $documentId, $actor, $fingerprint);
        if ($existing !== null) {
            return $existing;
        }

        $this->db->beginTransaction();
        try {
            // Сериализуем изменения карточки до проверки версии и бригады.
            $row = $this->db->fetchOne(
                'SELECT id,version,state,created_by_user_id FROM work_permit_documents
                 WHERE id=:document AND created_by_user_id=:actor FOR UPDATE',
                [':document' => $documentId, ':actor' => $actor]
            );
            if ($row === null) {
                throw new DomainException('Черновик не найден или принадлежит другому пользователю', 404);
            }
            $existing = $this->replay($command, $documentId, $actor, $fingerprint);
            if ($existing !== null) {
                $this->db->endTransaction();
                return $existing;
            }
            if ($row['state'] !== 'draft') {
                throw new DomainException('Выданный или закрытый документ нельзя изменять как черновик', 409);
            }
            if ((int) $row['version'] !== $version) {
                throw new DomainException('Черновик уже изменён в другой вкладке. Загрузите актуальную карточку', 409);
            }

            $references = (new WorkPermitReferenceService($this->db))->resolveDraft($data);
            $crewService = new WorkPermitCrewService($this->db);
            $crew = $crewService->prepare($references['organization_id'], $data);
            $this->db->execute(
                'UPDATE work_permit_documents SET
                    organization=:org,subdivision=:unit,facility=:facility,
                    organization_id=:org_id,department_id=:dept_id,facility_id=:facility_id,
                    work_description=:description,version=version+1
                 WHERE id=:document AND state=\'draft\' AND version=:version',
                [
                    ':org' => $references['organization'],
                    ':unit' => $references['subdivision'],
                    ':facility' => $references['facility'],
                    ':org_id' => $references['organization_id'],
                    ':dept_id' => $references['department_id'],
                    ':facility_id' => $references['facility_id'],
                    ':description' => $description,
                    ':document' => $documentId, ':version' => $version,
                ]
            );
            $crewService->replace($documentId, $crew);
            $this->db->execute(
                'INSERT INTO work_permit_edit_commands
                    (command_id,document_id,actor_user_id,request_hash,result_version)
                 VALUES (:command,:document,:actor,:hash,:result_version)',
                [
                    ':command' => $command, ':document' => $documentId,
                    ':actor' => $actor, ':hash' => $fingerprint, ':result_version' => $version + 1,
                ]
            );
            $this->db->execute(
                'INSERT INTO work_permit_history
                    (document_id,actor_user_id,event_type,version)
                 VALUES (:document,:actor,\'draft_updated\',:version)',
                [':document' => $documentId, ':actor' => $actor, ':version' => $version + 1]
            );
            $this->db->endTransaction();
            return ['id' => $documentId, 'version' => $version + 1, 'replayed' => false];
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $existing = $this->replay($command, $documentId, $actor, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $error;
        }
    }

    /** @return array{id:int,version:int,replayed:bool}|null */
    private function replay(string $command, int $documentId, int $actor, string $hash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT document_id,actor_user_id,request_hash,result_version
             FROM work_permit_edit_commands WHERE command_id=:command LIMIT 1',
            [':command' => $command]
        );
        if ($row === null) {
            return null;
        }
        if ((int) $row['document_id'] !== $documentId
            || (int) $row['actor_user_id'] !== $actor
            || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new DomainException('Команда изменения уже использована с другим содержимым', 409);
        }
        return ['id' => $documentId, 'version' => (int) $row['result_version'], 'replayed' => true];
    }

    private function number(mixed $value, string $name): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректное значение: ' . $name);
        }
        return (int) $value;
    }
}
