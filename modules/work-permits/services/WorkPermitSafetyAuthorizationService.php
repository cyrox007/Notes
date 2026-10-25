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
 * Разрешения на подготовку места и индивидуальные целевые инструктажи.
 * Действия системы являются учётными записями, но не подписями работников.
 */
final class WorkPermitSafetyAuthorizationService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    public function preparationPermission(int $documentId, int $actor, array $input): array
    {
        $version = $this->positive($input['version'] ?? null, 'version');
        $command = $this->command($input['command_id'] ?? null);
        $at = $this->timestamp($input['granted_at'] ?? null);
        $grantor = $this->positive($input['grantor_employee_id'] ?? null, 'grantor_employee_id');
        $receiver = $this->positive($input['receiver_employee_id'] ?? null, 'receiver_employee_id');
        $workplace = $this->string($input['workplace'] ?? null, 500, true);
        $method = $input['communication_method'] ?? null;
        if (!in_array($method, ['personally', 'telephone', 'radio', 'other'], true)) {
            throw new InvalidArgumentException('Выберите способ передачи разрешения');
        }
        $note = $this->string($input['note'] ?? '', 1000, false);
        if (($input['permission_confirmed'] ?? null) !== true) {
            throw new DomainException('Подтвердите фактическое получение разрешения', 409);
        }
        $hash = $this->hash($documentId, $actor, 'preparation_permission',
            [$version, $at, $grantor, $receiver, $workplace, $method, $note, true]);
        $existing = $this->replay($command, $documentId, $actor, 'preparation_permission', $hash);
        if ($existing !== null) return $existing;

        $this->db->beginTransaction();
        try {
            $doc = $this->locked($documentId, $version);
            $existing = $this->replay($command, $documentId, $actor, 'preparation_permission', $hash);
            if ($existing !== null) {
                $this->db->endTransaction();
                return $existing;
            }
            $issue = $this->issuance($documentId);
            if ($at > $issue['planned_finish']) {
                throw new DomainException('Разрешение нельзя регистрировать после срока наряда', 409);
            }
            if ($this->db->fetchOne(
                'SELECT document_id FROM work_permit_first_admissions WHERE document_id=:doc',
                [':doc' => $documentId]
            ) !== null) {
                throw new DomainException('Разрешение на подготовку регистрируется до первого допуска', 409);
            }
            $org = (int) $doc['organization_id'];
            $grantedBy = $this->employee($grantor, $org);
            $receivedBy = $this->employee($receiver, $org);
            if ($grantor === $receiver) {
                throw new DomainException('Передающий и получающий разрешение должны быть разными работниками', 409);
            }
            $crew = (new WorkPermitCrewService($this->db))->forDocument($documentId);
            $admitters = $this->members($crew, 'admitter');
            if ($admitters !== [] && !in_array($receiver, $admitters, true)) {
                throw new DomainException('Получатель не совпадает с назначенным допускающим', 409);
            }
            $this->db->execute(
                'INSERT INTO work_permit_preparation_permissions
                 (document_id,granted_at,grantor_employee_id,grantor_name,
                  receiver_employee_id,receiver_name,workplace,communication_method,note,recorded_by_user_id)
                 VALUES (:doc,:at,:grantor,:grantor_name,:receiver,:receiver_name,
                         :workplace,:method,:note,:actor)',
                [':doc' => $documentId, ':at' => $at, ':grantor' => $grantor,
                 ':grantor_name' => $grantedBy['name'], ':receiver' => $receiver,
                 ':receiver_name' => $receivedBy['name'], ':workplace' => $workplace,
                 ':method' => $method, ':note' => $note, ':actor' => $actor]
            );
            $this->advance($documentId, $actor, $version, $command, 'preparation_permission', $hash);
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $existing = $this->replay($command, $documentId, $actor, 'preparation_permission', $hash);
            if ($existing !== null) return $existing;
            throw $error;
        }
    }

    /** @param array<string,mixed> $input @return array{id:int,version:int,state:string,replayed:bool} */
    public function targetBriefing(int $documentId, int $actor, array $input): array
    {
        $version = $this->positive($input['version'] ?? null, 'version');
        $command = $this->command($input['command_id'] ?? null);
        $stage = $input['stage'] ?? null;
        if (!in_array($stage, ['issuer', 'admitter', 'manager', 'producer'], true)) {
            throw new InvalidArgumentException('Выберите этап целевого инструктажа');
        }
        $at = $this->timestamp($input['briefed_at'] ?? null);
        $instructor = $this->positive($input['instructor_employee_id'] ?? null, 'instructor_employee_id');
        $recipient = $this->positive($input['recipient_employee_id'] ?? null, 'recipient_employee_id');
        $topic = $this->string($input['topic'] ?? null, 1000, true);
        if (($input['briefing_confirmed'] ?? null) !== true) {
            throw new DomainException('Подтвердите фактическое проведение инструктажа', 409);
        }
        $hash = $this->hash($documentId, $actor, 'target_briefing',
            [$version, $stage, $at, $instructor, $recipient, $topic, true]);
        $existing = $this->replay($command, $documentId, $actor, 'target_briefing', $hash);
        if ($existing !== null) return $existing;

        $this->db->beginTransaction();
        try {
            $doc = $this->locked($documentId, $version);
            $existing = $this->replay($command, $documentId, $actor, 'target_briefing', $hash);
            if ($existing !== null) {
                $this->db->endTransaction();
                return $existing;
            }
            $issue = $this->issuance($documentId);
            if ($at > $issue['planned_finish']) {
                throw new DomainException('Инструктаж нельзя регистрировать после срока наряда', 409);
            }
            if ($this->db->fetchOne(
                'SELECT document_id FROM work_permit_completions WHERE document_id=:doc',
                [':doc' => $documentId]
            ) !== null) {
                throw new DomainException('После окончания работ инструктаж не регистрируется', 409);
            }
            if ($stage === 'issuer' && $this->db->fetchOne(
                'SELECT document_id FROM work_permit_first_admissions WHERE document_id=:doc',
                [':doc' => $documentId]
            ) !== null) {
                throw new DomainException('Инструктаж выдавшим наряд оформляется до первого допуска', 409);
            }
            $org = (int) $doc['organization_id'];
            $from = $this->employee($instructor, $org);
            $to = $this->employee($recipient, $org);
            if ($instructor === $recipient) {
                throw new DomainException('Работник не может проводить инструктаж самому себе', 409);
            }
            $crew = (new WorkPermitCrewService($this->db))->forDocument($documentId);
            $instructorRole = $stage === 'issuer' ? 'issuer'
                : ($stage === 'admitter' ? 'admitter' : ($stage === 'manager' ? 'responsible_manager' : 'producer'));
            $assigned = $this->members($crew, $instructorRole);
            if (($assigned !== [] && !in_array($instructor, $assigned, true))
                || ($assigned === [] && $stage !== 'admitter')) {
                throw new DomainException('Проводящий инструктаж не назначен для выбранного этапа', 409);
            }
            $recipientRoles = match ($stage) {
                'issuer' => ['responsible_manager', 'producer'],
                'admitter' => ['responsible_manager', 'producer', 'member'],
                'manager' => ['producer', 'member'],
                'producer' => ['member'],
            };
            $allowed = [];
            foreach ($recipientRoles as $role) {
                array_push($allowed, ...$this->members($crew, $role));
            }
            if (!in_array($recipient, $allowed, true)) {
                throw new DomainException('Получатель инструктажа не указан в бригаде для этапа', 409);
            }
            $this->db->execute(
                'INSERT INTO work_permit_target_briefings
                 (document_id,stage,briefed_at,instructor_employee_id,instructor_name,
                  recipient_employee_id,recipient_name,recipient_group,topic,recorded_by_user_id)
                 VALUES (:doc,:stage,:at,:instructor,:instructor_name,
                         :recipient,:recipient_name,:group,:topic,:actor)',
                [':doc' => $documentId, ':stage' => $stage, ':at' => $at,
                 ':instructor' => $instructor, ':instructor_name' => $from['name'],
                 ':recipient' => $recipient, ':recipient_name' => $to['name'],
                 ':group' => $to['electrical_group'], ':topic' => $topic, ':actor' => $actor]
            );
            $this->advance($documentId, $actor, $version, $command, 'target_briefing', $hash);
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $existing = $this->replay($command, $documentId, $actor, 'target_briefing', $hash);
            if ($existing !== null) return $existing;
            throw $error;
        }
    }

    /** @return array{preparation_permissions:list<array<string,mixed>>,target_briefings:list<array<string,mixed>>} */
    public function details(int $documentId): array
    {
        return [
            'preparation_permissions' => $this->db->fetchAll(
                'SELECT granted_at,grantor_employee_id,grantor_name,
                        receiver_employee_id,receiver_name,workplace,communication_method,note,recorded_at
                 FROM work_permit_preparation_permissions WHERE document_id=:doc ORDER BY id',
                [':doc' => $documentId]
            ),
            'target_briefings' => $this->db->fetchAll(
                'SELECT stage,briefed_at,instructor_employee_id,instructor_name,
                        recipient_employee_id,recipient_name,recipient_group,topic,recorded_at
                 FROM work_permit_target_briefings WHERE document_id=:doc ORDER BY id',
                [':doc' => $documentId]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function locked(int $documentId, int $version): array
    {
        $doc = $this->db->fetchOne(
            'SELECT id,kind,state,version,organization_id
             FROM work_permit_documents WHERE id=:doc FOR UPDATE',
            [':doc' => $documentId]
        );
        if ($doc === null) throw new DomainException('Наряд не найден', 404);
        if ($doc['kind'] !== 'permit' || $doc['state'] !== 'issued'
            || (int) $doc['version'] !== $version) {
            throw new DomainException('Версия или состояние наряда изменились', 409);
        }
        return $doc;
    }

    /** @return array<string,mixed> */
    private function issuance(int $documentId): array
    {
        return $this->db->fetchOne(
            'SELECT planned_finish FROM work_permit_issuances WHERE document_id=:doc LIMIT 1',
            [':doc' => $documentId]
        ) ?? throw new DomainException('Наряд ещё не выдан', 409);
    }

    /** @return array{name:string,electrical_group:string} */
    private function employee(int $id, int $org): array
    {
        $row = $this->db->fetchOne(
            'SELECT name,electrical_group FROM work_permit_employees
             WHERE id=:id AND organization_id=:org AND is_archived=0',
            [':id' => $id, ':org' => $org]
        );
        if ($row === null) {
            throw new DomainException('Работник не найден, архивирован или принадлежит другой организации', 409);
        }
        return ['name' => (string) $row['name'], 'electrical_group' => (string) $row['electrical_group']];
    }

    /** @param list<array<string,mixed>> $crew @return list<int> */
    private function members(array $crew, string $role): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            array_filter($crew, static fn (array $row): bool => $row['role'] === $role)
        ));
    }

    private function advance(int $doc, int $actor, int $version, string $command, string $action, string $hash): void
    {
        $updated = $this->db->execute(
            'UPDATE work_permit_documents SET version=version+1
             WHERE id=:doc AND state=\'issued\' AND version=:version',
            [':doc' => $doc, ':version' => $version]
        );
        if ($updated !== 1) throw new DomainException('Версия наряда изменилась', 409);
        $this->db->execute(
            'INSERT INTO work_permit_history (document_id,actor_user_id,event_type,version)
             VALUES (:doc,:actor,:event,:version)',
            [':doc' => $doc, ':actor' => $actor, ':event' => $action, ':version' => $version + 1]
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
            throw new DomainException('Команда повторно использована с иным содержимым', 409);
        }
        return $this->result($doc, (int) $row['result_version'], true);
    }

    /** @return array{id:int,version:int,state:string,replayed:bool} */
    private function result(int $id, int $version, bool $replayed): array
    {
        return compact('id', 'version', 'replayed') + ['state' => 'issued'];
    }

    private function hash(int $doc, int $actor, string $action, array $parts): string
    {
        return hash('sha256', json_encode([$doc, $actor, $action, $parts],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function positive(mixed $value, string $name): int
    {
        if ((!is_int($value) && (!is_string($value)
            || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1)) || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректное поле: ' . $name);
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
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Укажите дату и время в UTC');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Некорректная дата и время UTC');
        }
        return $value;
    }

    private function string(mixed $value, int $maximum, bool $required): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > $maximum
            || ($required && trim($value) === '')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Некорректный текст разрешения или инструктажа');
        }
        return trim($value);
    }
}
