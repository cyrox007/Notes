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
 * Два последовательных события для наряда и отдельное закрытие распоряжения.
 * Учётные отметки пользователей не являются подписями работников.
 */
final class WorkPermitClosureService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    public function complete(int $doc, int $actor, array $input): array
    {
        $version = $this->positive($input['version'] ?? null, 'version');
        $command = $this->command($input['command_id'] ?? null);
        $at = $this->utc($input['completed_at'] ?? null);
        $producer = $this->positive($input['producer_employee_id'] ?? null, 'producer_employee_id');
        $note = $this->note($input['note'] ?? '');
        if (($input['workplace_safe'] ?? null) !== true || ($input['crew_withdrawn'] ?? null) !== true) {
            throw new DomainException('Подтвердите безопасное состояние рабочего места и вывод бригады', 409);
        }
        $hash = $this->hash($doc, $actor, 'complete', [$version, $at, $producer, $note, true, true]);
        $old = $this->replay($command, $doc, $actor, 'complete', $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->locked($doc);
            $old = $this->replay($command, $doc, $actor, 'complete', $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireIssued($document, $version, 'permit');
            $issue = $this->issuance($doc);
            $period = $this->db->fetchOne(
                'SELECT started_at,ended_at,end_kind FROM work_permit_work_periods
                 WHERE document_id=:doc ORDER BY id DESC LIMIT 1',
                [':doc' => $doc]
            );
            if ($period === null || $period['ended_at'] === null || $period['end_kind'] !== 'day_end') {
                throw new DomainException('Завершите последний рабочий период окончанием дня', 409);
            }
            if ($at < $period['ended_at'] || $at > $issue['planned_finish']) {
                throw new DomainException('Окончание работ должно быть после завершения смены, в сроке наряда', 409);
            }
            if ((int) $issue['producer_employee_id'] !== $producer) {
                throw new DomainException('Окончание работ подтверждает производитель из выданного наряда', 409);
            }
            $name = $this->employeeName($producer, (int) $document['organization_id']);
            $this->db->execute(
                'INSERT INTO work_permit_completions
                 (document_id,completed_at,producer_employee_id,producer_name,
                  workplace_safe,crew_withdrawn,completion_note,recorded_by_user_id)
                 VALUES (:doc,:at,:producer,:name,1,1,:note,:actor)',
                [':doc' => $doc, ':at' => $at, ':producer' => $producer,
                    ':name' => $name, ':note' => $note, ':actor' => $actor]
            );
            $this->advance($doc, $actor, $version, $command, 'complete', $hash, 'issued');
            $this->db->endTransaction();
            return $this->result($doc, $version + 1, 'issued', false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $doc, $actor, 'complete', $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    public function closePermit(int $doc, int $actor, array $input): array
    {
        return $this->close($doc, $actor, $input, 'permit', 'close_permit');
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    public function closeOrder(int $doc, int $actor, array $input): array
    {
        return $this->close($doc, $actor, $input, 'order', 'close_order');
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    private function close(int $doc, int $actor, array $input, string $kind, string $action): array
    {
        $version = $this->positive($input['version'] ?? null, 'version');
        $command = $this->command($input['command_id'] ?? null);
        $at = $this->utc($input['closed_at'] ?? null);
        $closer = $this->positive($input['closer_employee_id'] ?? null, 'closer_employee_id');
        $note = $this->note($input['note'] ?? '');
        if (($input['workplace_accepted'] ?? null) !== true
            || ($input['documents_handed_over'] ?? null) !== true) {
            throw new DomainException('Подтвердите проверку места и передачу документации', 409);
        }
        $hash = $this->hash($doc, $actor, $action,
            [$version, $at, $closer, $note, true, true]);
        $old = $this->replay($command, $doc, $actor, $action, $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->locked($doc);
            $old = $this->replay($command, $doc, $actor, $action, $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireIssued($document, $version, $kind);
            $issue = $this->issuance($doc);
            $this->validateClosure($doc, $kind, $issue, $at, $closer);
            $name = $this->employeeName($closer, (int) $document['organization_id']);
            $this->db->execute(
                'INSERT INTO work_permit_closures
                 (document_id,closed_at,closer_employee_id,closer_name,workplace_accepted,
                  documents_handed_over,closure_note,recorded_by_user_id)
                 VALUES (:doc,:at,:employee,:name,1,1,:note,:actor)',
                [':doc' => $doc, ':at' => $at, ':employee' => $closer,
                    ':name' => $name, ':note' => $note, ':actor' => $actor]
            );
            $this->advance($doc, $actor, $version, $command, $action, $hash, 'closed');
            $this->db->endTransaction();
            return $this->result($doc, $version + 1, 'closed', false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $doc, $actor, $action, $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /** @param array<string,mixed> $issue */
    private function validateClosure(int $doc, string $kind, array $issue, string $at, int $closer): void
    {
        if ($kind === 'order') {
            if ($closer !== (int) $issue['producer_employee_id']
                || $at < $issue['planned_start'] || $at > $issue['planned_finish']) {
                throw new DomainException('Распоряжение закрывает производитель в пределах рабочей смены', 409);
            }
            return;
        }

        $complete = $this->db->fetchOne(
            'SELECT completed_at FROM work_permit_completions WHERE document_id=:doc',
            [':doc' => $doc]
        );
        if ($complete === null || $at < $complete['completed_at']) {
            throw new DomainException('Сначала зарегистрируйте окончание работ; закрытие должно быть позже', 409);
        }
        $responsible = $this->db->fetchOne(
            'SELECT employee_id FROM work_permit_crew
             WHERE document_id=:doc AND role=\'responsible_manager\' LIMIT 1',
            [':doc' => $doc]
        );
        if ($closer !== (int) $issue['issuer_employee_id']
            && ($responsible === null || $closer !== (int) $responsible['employee_id'])) {
            throw new DomainException('Закрытие наряда допускается выдавшим или ответственным руководителем', 409);
        }
    }

    /** @return array{completion:?array<string,mixed>,closure:?array<string,mixed>} */
    public function details(int $doc): array
    {
        return [
            'completion' => $this->db->fetchOne(
                'SELECT completed_at,producer_employee_id,producer_name,
                        workplace_safe,crew_withdrawn,completion_note,recorded_at
                 FROM work_permit_completions WHERE document_id=:doc LIMIT 1',
                [':doc' => $doc]
            ),
            'closure' => $this->db->fetchOne(
                'SELECT closed_at,closer_employee_id,closer_name,workplace_accepted,
                        documents_handed_over,closure_note,recorded_at
                 FROM work_permit_closures WHERE document_id=:doc LIMIT 1',
                [':doc' => $doc]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function locked(int $doc): array
    {
        if ($doc < 1) throw new InvalidArgumentException('Некорректный документ');
        $row = $this->db->fetchOne(
            'SELECT id,kind,state,version,organization_id FROM work_permit_documents
             WHERE id=:doc FOR UPDATE', [':doc' => $doc]
        );
        return $row ?? throw new DomainException('Документ не найден', 404);
    }

    /** @param array<string,mixed> $document */
    private function requireIssued(array $document, int $version, string $kind): void
    {
        if ($document['kind'] !== $kind || $document['state'] !== 'issued'
            || (int) $document['version'] !== $version) {
            throw new DomainException('Состояние или версия документа изменились', 409);
        }
    }

    /** @return array<string,mixed> */
    private function issuance(int $doc): array
    {
        return $this->db->fetchOne(
            'SELECT planned_start,planned_finish,issuer_employee_id,producer_employee_id
             FROM work_permit_issuances WHERE document_id=:doc LIMIT 1',
            [':doc' => $doc]
        ) ?? throw new DomainException('Документ не выдан', 409);
    }

    private function employeeName(int $id, int $org): string
    {
        $row = $this->db->fetchOne(
            'SELECT name FROM work_permit_employees
             WHERE id=:id AND organization_id=:org AND is_archived=0 LIMIT 1',
            [':id' => $id, ':org' => $org]
        );
        return $row === null
            ? throw new DomainException('Работник недоступен или находится в другой организации', 409)
            : (string) $row['name'];
    }

    private function advance(
        int $doc, int $actor, int $version, string $command,
        string $action, string $hash, string $state
    ): void {
        $updated = $this->db->execute(
            'UPDATE work_permit_documents SET state=:state,version=version+1
             WHERE id=:doc AND state=\'issued\' AND version=:version',
            [':state' => $state, ':doc' => $doc, ':version' => $version]
        );
        if ($updated !== 1) throw new DomainException('Версия документа изменена', 409);
        $this->db->execute(
            'INSERT INTO work_permit_history
             (document_id,actor_user_id,event_type,version)
             VALUES (:doc,:actor,:event,:version)',
            [':doc' => $doc, ':actor' => $actor,
                ':event' => $action, ':version' => $version + 1]
        );
        $this->db->execute(
            'INSERT INTO work_permit_lifecycle_commands
             (command_id,document_id,actor_user_id,action,request_hash,result_version)
             VALUES (:command,:doc,:actor,:action,:hash,:version)',
            [':command' => $command, ':doc' => $doc, ':actor' => $actor,
                ':action' => $action, ':hash' => $hash, ':version' => $version + 1]
        );
    }

    /** @return array{id:int,version:int,state:string,replayed:bool}|null */
    private function replay(string $command, int $doc, int $actor, string $action, string $hash): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT document_id,actor_user_id,action,request_hash,result_version
             FROM work_permit_lifecycle_commands WHERE command_id=:command LIMIT 1',
            [':command' => $command]
        );
        if ($row === null) return null;
        if ((int) $row['document_id'] !== $doc || (int) $row['actor_user_id'] !== $actor
            || $row['action'] !== $action || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new DomainException('Команда повторно применена с другим содержимым', 409);
        }
        return $this->result($doc, (int) $row['result_version'],
            $action === 'complete' ? 'issued' : 'closed', true);
    }

    /** @return array{id:int,version:int,state:string,replayed:bool} */
    private function result(int $id, int $version, string $state, bool $replayed): array
    {
        return compact('id', 'version', 'state', 'replayed');
    }

    private function hash(int $doc, int $actor, string $action, array $parts): string
    {
        return hash('sha256', json_encode(
            [$doc, $actor, $action, $parts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
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
            throw new InvalidArgumentException('Укажите идентификатор команды из 32 шестнадцатеричных символов');
        }
        return $value;
    }

    private function utc(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Время должно быть в UTC');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Неизвестная дата и время');
        }
        return $value;
    }

    private function note(mixed $value): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Некорректное пояснение закрытия');
        }
        return trim($value);
    }
}
