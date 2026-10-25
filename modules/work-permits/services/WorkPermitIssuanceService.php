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
 * Выдача и первичный допуск оформляются отдельными неизменяемыми записями.
 * Учётное действие пользователя НЕ является электронной подписью работника.
 */
final class WorkPermitIssuanceService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** @param array<string,mixed> $data @return array{id:int,version:int,replayed:bool,state:string} */
    public function issue(int $documentId, int $actor, array $data): array
    {
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $start = $this->dateTime($data['planned_start'] ?? null, 'planned_start');
        $finish = $this->dateTime($data['planned_finish'] ?? null, 'planned_finish');
        if ($start >= $finish) {
            throw new InvalidArgumentException('Дата окончания должна быть позже начала');
        }
        $instructions = $this->string($data['special_instructions'] ?? '', 4000, false);
        $conditions = $this->string($data['work_conditions'] ?? '', 6000, false);
        $measures = $this->measures($data['measures'] ?? null);
        // Для старых команд без условий сохраняется исходный отпечаток.
        $fingerprintParts = [$version, $start, $finish, $instructions, $measures];
        if ($conditions !== '') {
            $fingerprintParts[] = $conditions;
        }
        $hash = $this->fingerprint($documentId, $actor, 'issue', $fingerprintParts);
        $old = $this->replay($command, $documentId, $actor, 'issue', $hash);
        if ($old !== null) {
            return $old;
        }

        $this->db->beginTransaction();
        try {
            $document = $this->locked($documentId);
            $old = $this->replay($command, $documentId, $actor, 'issue', $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireVersion($document, $version, 'draft');
            if (empty($document['organization_id']) || empty($document['facility_id'])
                || trim((string) $document['work_description']) === '') {
                throw new DomainException('Заполните организацию, объект и задание до выдачи', 409);
            }
            $crew = (new WorkPermitCrewService($this->db))->forDocument($documentId);
            $issuer = $this->crewRole($crew, 'issuer');
            $producer = $this->crewRole($crew, 'producer');
            if ($document['kind'] === 'permit' && !array_filter($crew, static fn (array $r): bool => $r['role'] === 'member')) {
                throw new DomainException('Для наряда укажите хотя бы одного члена бригады', 409);
            }
            $this->activeEmployee((int) $issuer['employee_id'], (int) $document['organization_id']);
            $this->activeEmployee((int) $producer['employee_id'], (int) $document['organization_id']);

            $zone = $this->db->fetchOne(
                'SELECT time_zone FROM work_permit_organizations WHERE id=:org FOR UPDATE',
                [':org' => $document['organization_id']]
            );
            if ($zone === null) {
                throw new DomainException('Организация наряда не найдена', 409);
            }
            $timeZone = WorkPermitLocalDay::validate($zone['time_zone']);
            // При смене пояса между открытием формы и выдачей нельзя молча сдвинуть время.
            if (array_key_exists('time_zone', $data) && $data['time_zone'] !== $timeZone) {
                throw new DomainException('Часовой пояс организации изменился. Откройте документ повторно', 409);
            }
            if ($document['kind'] === 'order' && !WorkPermitLocalDay::sameDay($start, $finish, $timeZone)) {
                throw new DomainException('Распоряжение должно завершаться в те же местные сутки', 409);
            }
            $this->db->execute(
                'INSERT INTO work_permit_issuances
                    (document_id,issuer_employee_id,issuer_name,producer_employee_id,producer_name,
                     planned_start,planned_finish,time_zone,special_instructions,work_conditions,issued_by_user_id)
                 VALUES (:doc,:issuer,:issuer_name,:producer,:producer_name,:start,:finish,:zone,:instructions,:conditions,:actor)',
                [
                    ':doc' => $documentId,
                    ':issuer' => $issuer['employee_id'], ':issuer_name' => $issuer['employee_name'],
                    ':producer' => $producer['employee_id'], ':producer_name' => $producer['employee_name'],
                    ':start' => $start, ':finish' => $finish, ':zone' => $timeZone,
                    ':instructions' => $instructions, ':conditions' => $conditions, ':actor' => $actor,
                ]
            );
            foreach ($measures as $index => $measure) {
                $this->db->execute(
                    'INSERT INTO work_permit_safety_measures
                        (document_id,sequence_no,equipment,isolation,isolated_parts,remaining_live)
                     VALUES (:doc,:sequence,:equipment,:isolation,:isolated,:remaining)',
                    [
                        ':doc' => $documentId, ':sequence' => $index + 1,
                        ':equipment' => $measure['equipment'],
                        ':isolation' => $measure['isolation'],
                        ':isolated' => $measure['isolated_parts'] ?? null,
                        ':remaining' => $measure['remaining_live'],
                    ]
                );
            }
            $next = $this->advance($documentId, $version, 'issued', 'issued', $actor);
            $this->recordCommand($command, $documentId, $actor, 'issue', $hash, $next);
            $this->db->endTransaction();
            return $this->result($documentId, $next, false, 'issued');
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, 'issue', $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /** @param array<string,mixed> $data @return array{id:int,version:int,replayed:bool,state:string} */
    public function firstAdmission(int $documentId, int $actor, array $data): array
    {
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $start = $this->dateTime($data['started_at'] ?? null, 'started_at');
        $admitter = $this->positive($data['admitted_employee_id'] ?? null, 'admitted_employee_id');
        $instructor = $this->positive($data['briefed_by_employee_id'] ?? null, 'briefed_by_employee_id');
        $recipient = $this->positive($data['briefing_recipient_id'] ?? null, 'briefing_recipient_id');
        $workplace = WorkPermitDailyService::normalizeWorkplace($data['workplace'] ?? null);
        if (($data['workplace_prepared'] ?? null) !== true || ($data['briefing_confirmed'] ?? null) !== true) {
            throw new DomainException('Подтвердите подготовку места и фактически проведённый инструктаж', 409);
        }
        // Не меняем отпечатки старых команд, в которых место не указывалось.
        $fingerprint = [$version, $start, $admitter, $instructor, $recipient, true, true];
        if ($workplace !== null) $fingerprint[] = $workplace;
        $hash = $this->fingerprint($documentId, $actor, 'first_admission', $fingerprint);
        $old = $this->replay($command, $documentId, $actor, 'first_admission', $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->locked($documentId);
            $old = $this->replay($command, $documentId, $actor, 'first_admission', $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requireVersion($document, $version, 'issued');
            if ($document['kind'] !== 'permit') {
                throw new DomainException('Для распоряжения отдельный допуск не оформляется', 409);
            }
            $issued = $this->db->fetchOne(
                'SELECT planned_start,planned_finish,producer_employee_id FROM work_permit_issuances
                 WHERE document_id=:doc LIMIT 1',
                [':doc' => $documentId]
            );
            if ($issued === null || $start < $issued['planned_start'] || $start > $issued['planned_finish']) {
                throw new DomainException('Начало допуска должно находиться в сроке выданного наряда', 409);
            }
            if ((int) $issued['producer_employee_id'] !== $recipient) {
                throw new DomainException('Инструктаж получает указанный в наряде производитель работ', 409);
            }
            $crew = (new WorkPermitCrewService($this->db))->forDocument($documentId);
            $explicitAdmitter = array_values(array_filter($crew, static fn (array $r): bool => $r['role'] === 'admitter'));
            if ($explicitAdmitter !== [] && (int) $explicitAdmitter[0]['employee_id'] !== $admitter) {
                throw new DomainException('Допускающий не совпадает с назначенным в наряде', 409);
            }
            $org = (int) $document['organization_id'];
            $admitterName = $this->activeEmployee($admitter, $org);
            $instructorName = $this->activeEmployee($instructor, $org);
            $recipientName = $this->activeEmployee($recipient, $org);
            $this->db->execute(
                'INSERT INTO work_permit_first_admissions
                 (document_id,admitted_employee_id,admitted_name,briefed_by_employee_id,briefed_by_name,
                  briefing_recipient_id,briefing_recipient_name,started_at,
                  workplace_prepared,briefing_confirmed,recorded_by_user_id)
                 VALUES (:doc,:admitter,:admitter_name,:instructor,:instructor_name,
                         :recipient,:recipient_name,:start,1,1,:actor)',
                [
                    ':doc' => $documentId, ':admitter' => $admitter, ':admitter_name' => $admitterName,
                    ':instructor' => $instructor, ':instructor_name' => $instructorName,
                    ':recipient' => $recipient, ':recipient_name' => $recipientName,
                    ':start' => $start, ':actor' => $actor,
                ]
            );
            (new WorkPermitDailyService($this->db))->firstPeriod(
                $documentId, $actor, $start, $admitter, $admitterName,
                $instructor, $instructorName, $recipient, $recipientName, $workplace
            );
            $next = $this->advance($documentId, $version, 'issued', 'first_admission', $actor);
            $this->recordCommand($command, $documentId, $actor, 'first_admission', $hash, $next);
            $this->db->endTransaction();
            return $this->result($documentId, $next, false, 'issued');
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, 'first_admission', $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /** @return array{issuance:?array<string,mixed>,measures:list<array<string,mixed>>,first_admission:?array<string,mixed>} */
    public function details(int $documentId): array
    {
        return [
            'issuance' => $this->db->fetchOne(
                'SELECT issuer_employee_id,issuer_name,producer_employee_id,producer_name,
                        planned_start,planned_finish,time_zone,special_instructions,work_conditions,issued_at
                 FROM work_permit_issuances WHERE document_id=:id LIMIT 1',
                [':id' => $documentId]
            ),
            'measures' => $this->db->fetchAll(
                'SELECT sequence_no,equipment,isolation,isolated_parts,remaining_live
                 FROM work_permit_safety_measures WHERE document_id=:id ORDER BY sequence_no',
                [':id' => $documentId]
            ),
            'first_admission' => $this->db->fetchOne(
                'SELECT admitted_employee_id,admitted_name,briefed_by_employee_id,briefed_by_name,
                        briefing_recipient_id,briefing_recipient_name,started_at,
                        workplace_prepared,briefing_confirmed,recorded_at
                 FROM work_permit_first_admissions WHERE document_id=:id LIMIT 1',
                [':id' => $documentId]
            ),
            'periods' => (new WorkPermitDailyService($this->db))->periods($documentId),
        ];
    }

    private function locked(int $documentId): array
    {
        $row = $this->db->fetchOne(
            'SELECT id,kind,state,version,organization_id,facility_id,work_description
             FROM work_permit_documents WHERE id=:id FOR UPDATE',
            [':id' => $documentId]
        );
        return $row ?? throw new DomainException('Документ не найден', 404);
    }

    /** @param array<string,mixed> $document */
    private function requireVersion(array $document, int $version, string $state): void
    {
        if ($document['state'] !== $state || (int) $document['version'] !== $version) {
            throw new DomainException('Документ изменён или находится в другом состоянии. Обновите карточку', 409);
        }
    }

    /** @param list<array<string,mixed>> $crew @return array<string,mixed> */
    private function crewRole(array $crew, string $role): array
    {
        foreach ($crew as $member) if ($member['role'] === $role) return $member;
        throw new DomainException('Не назначен обязательный участник: ' . $role, 409);
    }

    private function activeEmployee(int $id, int $org): string
    {
        $row = $this->db->fetchOne(
            'SELECT name FROM work_permit_employees WHERE id=:id AND organization_id=:org AND is_archived=0 LIMIT 1',
            [':id' => $id, ':org' => $org]
        );
        return $row === null ? throw new DomainException('Работник отсутствует, архивирован или из другой организации', 409)
            : (string) $row['name'];
    }

    private function advance(int $doc, int $version, string $state, string $event, int $actor): int
    {
        $changed = $this->db->execute(
            'UPDATE work_permit_documents SET state=:state,version=version+1 WHERE id=:id AND version=:version',
            [':state' => $state, ':id' => $doc, ':version' => $version]
        );
        if ($changed !== 1) throw new DomainException('Не удалось изменить состояние документа', 409);
        $this->db->execute(
            'INSERT INTO work_permit_history (document_id,actor_user_id,event_type,version)
             VALUES (:id,:actor,:event,:version)',
            [':id' => $doc, ':actor' => $actor, ':event' => $event, ':version' => $version + 1]
        );
        return $version + 1;
    }

    private function recordCommand(string $command, int $doc, int $actor, string $action, string $hash, int $version): void
    {
        $this->db->execute(
            'INSERT INTO work_permit_lifecycle_commands
             (command_id,document_id,actor_user_id,action,request_hash,result_version)
             VALUES (:command,:doc,:actor,:action,:hash,:version)',
            [':command' => $command, ':doc' => $doc, ':actor' => $actor, ':action' => $action,
                ':hash' => $hash, ':version' => $version]
        );
    }

    /** @return array{id:int,version:int,replayed:bool,state:string}|null */
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
            throw new DomainException('Команда уже применена с другими данными', 409);
        }
        return $this->result($doc, (int) $row['result_version'], true, 'issued');
    }

    /** @return array{id:int,version:int,replayed:bool,state:string} */
    private function result(int $id, int $version, bool $replayed, string $state): array
    {
        return compact('id', 'version', 'replayed', 'state');
    }

    private function fingerprint(int $doc, int $actor, string $action, array $values): string
    {
        return hash('sha256', json_encode([$doc, $actor, $action, $values],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{equipment:string,isolation:string,isolated_parts?:string,remaining_live:string}> */
    private function measures(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 20) {
            throw new InvalidArgumentException('Укажите от одного до двадцати мероприятий безопасности');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Некорректное мероприятие безопасности');
            $entry = [
                'equipment' => $this->string($row['equipment'] ?? null, 240, true),
                'isolation' => $this->string($row['isolation'] ?? null, 1000, true),
                'remaining_live' => $this->string($row['remaining_live'] ??
                    'Оборудование и токоведущие части, оставшиеся в работе под напряжением.', 500, true),
            ];
            // Старые команды не включали графу изоляции в отпечаток.
            // Если отдельное значение появилось, оно обязательно влияет на идемпотентность.
            $isolated = $this->string($row['isolated_parts'] ?? '', 1000, false);
            if ($isolated !== '') {
                $entry['isolated_parts'] = $isolated;
            }
            $result[] = $entry;
        }
        return $result;
    }

    private function string(mixed $value, int $max, bool $required): string
    {
        if (!is_string($value) || ($required && trim($value) === '')
            || mb_strlen(trim($value)) > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Неверное текстовое поле');
        }
        return trim($value);
    }

    private function positive(mixed $value, string $field): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9]\d{0,14}$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new InvalidArgumentException('Некорректное поле: ' . $field);
        }
        return (int) $value;
    }

    private function command(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Ожидается уникальный идентификатор команды');
        }
        return $value;
    }

    private function dateTime(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Неверная дата/время UTC: ' . $field);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Несуществующая дата: ' . $field);
        }
        return $value;
    }
}
