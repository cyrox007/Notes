<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Продление срока и изменение членов бригады у выданного наряда.
 * Все операции регистрируются с предыдущей версией и не переписывают историю.
 */
final class WorkPermitChangeService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /**
     * Заменить только членов бригады; назначенные ответственные роли сохраняются.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,version:int,replayed:bool,state:string}
     */
    public function changeCrew(int $documentId, int $actor, array $data): array
    {
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $changedAt = $this->timestamp($data['changed_at'] ?? null);
        $reason = $this->reason($data['reason'] ?? null);
        $members = $this->ids($data['member_ids'] ?? null);
        $hash = $this->hash($documentId, $actor, 'crew_change',
            [$version, $changedAt, $reason, $members]);
        $old = $this->replay($command, $documentId, $actor, 'crew_change', $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->lock($documentId);
            $old = $this->replay($command, $documentId, $actor, 'crew_change', $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireIssuedPermit($document, $version);
            $issue = $this->issuance($documentId);
            $this->assertBetweenPeriods($documentId, $changedAt, $issue);
            $crewService = new WorkPermitCrewService($this->db);
            $current = $crewService->forDocument($documentId);
            if (count(array_filter($current, static fn (array $row): bool => $row['role'] === 'issuer')) !== 1
                || count(array_filter($current, static fn (array $row): bool => $row['role'] === 'producer')) !== 1) {
                throw new DomainException('Состав наряда не содержит обязательных ответственных работников', 409);
            }
            $newMembers = $crewService->prepare((int) $document['organization_id'], [
                'roles' => [], 'member_ids' => $members,
            ]);
            $this->db->execute(
                'DELETE FROM work_permit_crew WHERE document_id=:id AND role=\'member\'',
                [':id' => $documentId]
            );
            foreach ($newMembers as $row) {
                $this->db->execute(
                    'INSERT INTO work_permit_crew
                     (document_id,role,employee_id,employee_name,electrical_group)
                     VALUES (:doc,\'member\',:employee,:name,:group)',
                    [':doc' => $documentId, ':employee' => $row['employee_id'],
                        ':name' => $row['employee_name'], ':group' => $row['electrical_group']]
                );
            }
            $this->db->execute(
                'INSERT INTO work_permit_crew_revisions
                 (document_id,previous_version,changed_at,reason,actor_user_id)
                 VALUES (:doc,:version,:changed,:reason,:actor)',
                [':doc' => $documentId, ':version' => $version, ':changed' => $changedAt,
                    ':reason' => $reason, ':actor' => $actor]
            );
            $revision = (int) $this->db->lastInsertId();
            foreach ($crewService->forDocument($documentId) as $row) {
                $this->db->execute(
                    'INSERT INTO work_permit_crew_revision_members
                     (revision_id,role,employee_id,employee_name,electrical_group)
                     VALUES (:revision,:role,:employee,:name,:group)',
                    [':revision' => $revision, ':role' => $row['role'],
                        ':employee' => $row['employee_id'], ':name' => $row['employee_name'],
                        ':group' => $row['electrical_group']]
                );
            }
            $this->advance($documentId, $actor, $version, $command, $hash, 'crew_change');
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, 'crew_change', $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /**
     * Увеличить только дату окончания; исходный срок и причину не стирать.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,version:int,replayed:bool,state:string}
     */
    public function extend(int $documentId, int $actor, array $data): array
    {
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $changedAt = $this->timestamp($data['extended_at'] ?? null);
        $finish = $this->timestamp($data['new_finish'] ?? null);
        $reason = $this->reason($data['reason'] ?? null);
        $issuerId = $this->positive($data['issuer_employee_id'] ?? null, 'issuer_employee_id');
        $hash = $this->hash($documentId, $actor, 'extension',
            [$version, $changedAt, $finish, $reason, $issuerId]);
        $old = $this->replay($command, $documentId, $actor, 'extension', $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->lock($documentId);
            $old = $this->replay($command, $documentId, $actor, 'extension', $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireIssuedPermit($document, $version);
            $issue = $this->issuance($documentId);
            if ($finish <= $issue['planned_finish']) {
                throw new DomainException('Продление должно увеличивать текущий срок окончания', 409);
            }
            if ($changedAt < $issue['issued_at'] || $changedAt > $issue['planned_finish']) {
                throw new DomainException('Продление необходимо зарегистрировать в пределах текущего срока', 409);
            }
            $this->assertBetweenPeriods($documentId, $changedAt, $issue);
            if ((int) $issue['issuer_employee_id'] !== $issuerId) {
                throw new DomainException('Продление должен оформлять работник, выдавший наряд', 409);
            }
            $issuer = $this->db->fetchOne(
                'SELECT name FROM work_permit_employees
                 WHERE id=:employee AND organization_id=:org AND is_archived=0',
                [':employee' => $issuerId, ':org' => $document['organization_id']]
            );
            if ($issuer === null) {
                throw new DomainException('Выдавший наряд работник недоступен или архивирован', 409);
            }
            $this->db->execute(
                'INSERT INTO work_permit_extensions
                 (document_id,previous_version,previous_finish,new_finish,extended_at,
                  reason,issuer_employee_id,issuer_name,actor_user_id)
                 VALUES (:doc,:version,:previous,:finish,:changed,:reason,:issuer,:name,:actor)',
                [':doc' => $documentId, ':version' => $version,
                    ':previous' => $issue['planned_finish'], ':finish' => $finish,
                    ':changed' => $changedAt, ':reason' => $reason,
                    ':issuer' => $issuerId, ':name' => $issuer['name'], ':actor' => $actor]
            );
            $this->db->execute(
                'UPDATE work_permit_issuances SET planned_finish=:finish WHERE document_id=:doc',
                [':finish' => $finish, ':doc' => $documentId]
            );
            $this->advance($documentId, $actor, $version, $command, $hash, 'extension');
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, 'extension', $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /**
     * @return array{extensions:list<array<string,mixed>>,crew_revisions:list<array<string,mixed>>}
     */
    public function details(int $documentId): array
    {
        $revisions = $this->db->fetchAll(
            'SELECT id,previous_version,changed_at,reason FROM work_permit_crew_revisions
             WHERE document_id=:id ORDER BY id ASC', [':id' => $documentId]
        );
        foreach ($revisions as &$revision) {
            $revision['members'] = $this->db->fetchAll(
                'SELECT role,employee_id,employee_name,electrical_group
                 FROM work_permit_crew_revision_members WHERE revision_id=:id
                 ORDER BY role,employee_id', [':id' => $revision['id']]
            );
        }
        unset($revision);
        return [
            'extensions' => $this->db->fetchAll(
                'SELECT previous_version,previous_finish,new_finish,extended_at,
                        reason,issuer_employee_id,issuer_name
                 FROM work_permit_extensions WHERE document_id=:id ORDER BY id ASC',
                [':id' => $documentId]
            ),
            'crew_revisions' => $revisions,
        ];
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        if ($id < 1) throw new InvalidArgumentException('Некорректный номер документа');
        $row = $this->db->fetchOne(
            'SELECT id,kind,state,version,organization_id
             FROM work_permit_documents WHERE id=:id FOR UPDATE',
            [':id' => $id]
        );
        return $row ?? throw new DomainException('Документ не найден', 404);
    }

    /** @param array<string,mixed> $document */
    private function requireIssuedPermit(array $document, int $expected): void
    {
        if ($this->db->fetchOne(
            'SELECT document_id FROM work_permit_completions WHERE document_id=:doc',
            [':doc' => $document['id']]
        ) !== null) {
            throw new DomainException('После завершения работ изменение бригады и продление запрещены', 409);
        }
        if ($document['kind'] !== 'permit' || $document['state'] !== 'issued'
            || (int) $document['version'] !== $expected) {
            throw new DomainException('Наряд изменён, закрыт или находится в другом состоянии', 409);
        }
    }

    /** @return array<string,mixed> */
    private function issuance(int $documentId): array
    {
        return $this->db->fetchOne(
            'SELECT planned_start,planned_finish,issued_at,issuer_employee_id
             FROM work_permit_issuances WHERE document_id=:doc',
            [':doc' => $documentId]
        ) ?? throw new DomainException('Наряд ещё не выдан', 409);
    }

    /** @param array<string,mixed> $issue */
    private function assertBetweenPeriods(int $documentId, string $at, array $issue): void
    {
        if ($at < $issue['planned_start'] || $at > $issue['planned_finish']) {
            throw new DomainException('Изменение находится за пределами срока действия наряда', 409);
        }
        $last = $this->db->fetchOne(
            'SELECT started_at,ended_at FROM work_permit_work_periods
             WHERE document_id=:doc ORDER BY id DESC LIMIT 1',
            [':doc' => $documentId]
        );
        if ($last !== null && ($last['ended_at'] === null || $at < $last['ended_at'])) {
            throw new DomainException('Дождитесь завершения текущего рабочего периода', 409);
        }
    }

    private function advance(int $doc, int $actor, int $version, string $command, string $hash, string $action): void
    {
        $updated = $this->db->execute(
            'UPDATE work_permit_documents SET version=version+1
             WHERE id=:doc AND state=\'issued\' AND version=:version',
            [':doc' => $doc, ':version' => $version]
        );
        if ($updated !== 1) throw new DomainException('Версия наряда изменилась', 409);
        $this->db->execute(
            'INSERT INTO work_permit_history (document_id,actor_user_id,event_type,version)
             VALUES (:doc,:actor,:action,:version)',
            [':doc' => $doc, ':actor' => $actor,
                ':action' => $action, ':version' => $version + 1]
        );
        $this->db->execute(
            'INSERT INTO work_permit_lifecycle_commands
             (command_id,document_id,actor_user_id,action,request_hash,result_version)
             VALUES (:command,:doc,:actor,:action,:hash,:version)',
            [':command' => $command, ':doc' => $doc, ':actor' => $actor,
                ':action' => $action, ':hash' => $hash, ':version' => $version + 1]
        );
    }

    /** @return array{id:int,version:int,replayed:bool,state:string}|null */
    private function replay(string $command, int $doc, int $actor, string $action, string $hash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT document_id,actor_user_id,action,request_hash,result_version
             FROM work_permit_lifecycle_commands WHERE command_id=:command',
            [':command' => $command]
        );
        if ($row === null) return null;
        if ((int) $row['document_id'] !== $doc || (int) $row['actor_user_id'] !== $actor
            || $row['action'] !== $action || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new DomainException('Идентификатор команды уже занят другим действием', 409);
        }
        return $this->result($doc, (int) $row['result_version'], true);
    }

    /** @return array{id:int,version:int,replayed:bool,state:string} */
    private function result(int $id, int $version, bool $replayed): array
    {
        return compact('id', 'version', 'replayed') + ['state' => 'issued'];
    }

    private function hash(int $doc, int $actor, string $action, array $content): string
    {
        return hash('sha256', json_encode([$doc, $actor, $action, $content],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)
            || count($value) < 1 || count($value) > 30) {
            throw new InvalidArgumentException('Укажите от одного до тридцати участников');
        }
        $ids = array_map(fn (mixed $id): int => $this->positive($id, 'member_ids'), $value);
        sort($ids, SORT_NUMERIC);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('В составе бригады не может быть повторяющихся участников');
        }
        return $ids;
    }

    private function positive(mixed $value, string $field): int
    {
        if ((!is_int($value) && (!is_string($value)
            || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1)) || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректное поле: ' . $field);
        }
        return (int) $value;
    }

    private function command(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректный идентификатор команды');
        }
        return $value;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Укажите дату и время UTC');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Несуществующая дата и время');
        }
        return $value;
    }

    private function reason(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Укажите обоснование изменения (до 1000 символов)');
        }
        return trim($value);
    }
}
