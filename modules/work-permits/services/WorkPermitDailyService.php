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
 * Один открытый рабочий период на выданный наряд.
 * Конец дня и перерыв — разные события, каждое требует нового допуска.
 * Действия пользователей системы не подменяют подписи работников.
 */
final class WorkPermitDailyService
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    /** Вызывается из той же транзакции, что и запись первичного допуска. */
    public function firstPeriod(
        int $documentId,
        int $actor,
        string $startedAt,
        int $admitter,
        string $admitterName,
        int $instructor,
        string $instructorName,
        int $recipient,
        string $recipientName,
        ?string $workplace = null
    ): void {
        $this->insertPeriod($documentId, $actor, 'first', $startedAt, [
            'admitter' => $admitter, 'admitter_name' => $admitterName,
            'instructor' => $instructor, 'instructor_name' => $instructorName,
            'recipient' => $recipient, 'recipient_name' => $recipientName,
            'workplace' => $workplace,
        ]);
    }

    /**
     * Повторный дневной допуск или допуск после перерыва.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,version:int,replayed:bool,state:string}
     */
    public function start(int $documentId, int $actor, string $action, array $data): array
    {
        if (!in_array($action, ['daily_admission', 'resume'], true)) {
            throw new InvalidArgumentException('Неизвестный способ продолжения работ');
        }
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $started = $this->timestamp($data['started_at'] ?? null);
        $admitter = $this->positive($data['admitted_employee_id'] ?? null, 'admitted_employee_id');
        $instructor = $this->positive($data['briefed_by_employee_id'] ?? null, 'briefed_by_employee_id');
        $recipient = $this->positive($data['briefing_recipient_id'] ?? null, 'briefing_recipient_id');
        $workplace = self::normalizeWorkplace($data['workplace'] ?? null);
        if (($data['workplace_prepared'] ?? null) !== true
            || ($data['briefing_confirmed'] ?? null) !== true) {
            throw new DomainException('Подтвердите подготовку места и проведённый инструктаж', 409);
        }
        // Старые команды без места допуска сохраняют прежний отпечаток.
        $fingerprint = [$version, $started, $admitter, $instructor, $recipient, true, true];
        if ($workplace !== null) $fingerprint[] = $workplace;
        $hash = $this->fingerprint($documentId, $actor, $action, $fingerprint);
        $old = $this->replay($command, $documentId, $actor, $action, $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->lockDocument($documentId, $version);
            $old = $this->replay($command, $documentId, $actor, $action, $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requirePermit($document);
            $last = $this->lastPeriod($documentId);
            if ($last === null || $last['ended_at'] === null) {
                throw new DomainException('Сначала завершите действующий рабочий период', 409);
            }
            $issue = $this->issuance($documentId);
            $this->requireSequence($last, $action, $started, $issue['time_zone']);
            if ($started < $issue['planned_start'] || $started > $issue['planned_finish']) {
                throw new DomainException('Допуск находится за пределами срока выданного наряда', 409);
            }
            if ((int) $issue['producer_employee_id'] !== $recipient) {
                throw new DomainException('Инструктаж должен получить назначенный производитель работ', 409);
            }
            $crew = (new WorkPermitCrewService($this->db))->forDocument($documentId);
            foreach ($crew as $row) {
                if ($row['role'] === 'admitter' && (int) $row['employee_id'] !== $admitter) {
                    throw new DomainException('Допускающий не совпадает с назначенным в наряде', 409);
                }
            }
            $organization = (int) $document['organization_id'];
            $personnel = [
                'admitter' => $admitter, 'admitter_name' => $this->employee($admitter, $organization),
                'instructor' => $instructor, 'instructor_name' => $this->employee($instructor, $organization),
                'recipient' => $recipient, 'recipient_name' => $this->employee($recipient, $organization),
                'workplace' => $workplace,
            ];
            $this->insertPeriod($documentId, $actor, $action, $started, $personnel);
            $this->advance($documentId, $actor, $version, $action, $command, $hash);
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, $action, $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /**
     * Приостановить работу или закончить рабочий день.
     *
     * @param array<string,mixed> $data
     * @return array{id:int,version:int,replayed:bool,state:string}
     */
    public function finish(int $documentId, int $actor, string $action, array $data): array
    {
        if (!in_array($action, ['break', 'day_end'], true)) {
            throw new InvalidArgumentException('Неизвестный способ остановки работ');
        }
        $version = $this->positive($data['version'] ?? null, 'version');
        $command = $this->command($data['command_id'] ?? null);
        $ended = $this->timestamp($data['ended_at'] ?? null);
        $note = $this->note($data['note'] ?? '');
        if (($data['work_stopped'] ?? null) !== true) {
            throw new DomainException('Подтвердите фактическое прекращение работ', 409);
        }
        $hash = $this->fingerprint($documentId, $actor, $action, [$version, $ended, $note, true]);
        $old = $this->replay($command, $documentId, $actor, $action, $hash);
        if ($old !== null) return $old;

        $this->db->beginTransaction();
        try {
            $document = $this->lockDocument($documentId, $version);
            $old = $this->replay($command, $documentId, $actor, $action, $hash);
            if ($old !== null) {
                $this->db->endTransaction();
                return $old;
            }
            $this->requirePermit($document);
            $last = $this->lastPeriod($documentId);
            if ($last === null || $last['ended_at'] !== null) {
                throw new DomainException('Нет активного рабочего периода для остановки', 409);
            }
            $issue = $this->issuance($documentId);
            if ($ended <= $last['started_at'] || !WorkPermitLocalDay::sameDay(
                $ended, $last['started_at'], $issue['time_zone']
            )) {
                throw new DomainException('Остановка должна быть позже допуска, в пределах местных суток объекта', 409);
            }
            if ($ended > $issue['planned_finish']) {
                throw new DomainException('Остановка выходит за срок действия наряда', 409);
            }
            $affected = $this->db->execute(
                'UPDATE work_permit_work_periods SET
                 ended_at=:ended,end_kind=:kind,end_note=:note,ended_by_user_id=:actor
                 WHERE id=:id AND ended_at IS NULL',
                [':ended' => $ended, ':kind' => $action,
                    ':note' => $note, ':actor' => $actor, ':id' => $last['id']]
            );
            if ($affected !== 1) throw new DomainException('Рабочий период уже завершён', 409);
            $this->advance($documentId, $actor, $version, $action, $command, $hash);
            $this->db->endTransaction();
            return $this->result($documentId, $version + 1, false);
        } catch (Throwable $error) {
            $this->db->endTransaction(false);
            $old = $this->replay($command, $documentId, $actor, $action, $hash);
            if ($old !== null) return $old;
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function periods(int $documentId): array
    {
        return $this->db->fetchAll(
            'SELECT id,start_kind,started_at,admitter_employee_id,admitter_name,
                instructor_employee_id,instructor_name,recipient_employee_id,recipient_name,
                workplace,workplace_prepared,briefing_confirmed,ended_at,end_kind,end_note
             FROM work_permit_work_periods WHERE document_id=:document ORDER BY id ASC',
            [':document' => $documentId]
        );
    }

    /** @param array<string,mixed> $last */
    private function requireSequence(array $last, string $action, string $started, string $zone): void
    {
        if ($started <= $last['ended_at']) {
            throw new DomainException('Новый допуск должен быть позже завершения предыдущего периода', 409);
        }
        $sameDay = WorkPermitLocalDay::sameDay($started, $last['ended_at'], $zone);
        if ($action === 'resume' && ($last['end_kind'] !== 'break' || !$sameDay)) {
            throw new DomainException('Возобновление возможно только после перерыва в те же местные сутки', 409);
        }
        if ($action === 'daily_admission'
            && ($last['end_kind'] !== 'day_end' || $sameDay)) {
            throw new DomainException('Новый дневной допуск возможен только после окончания предыдущего дня', 409);
        }
    }

    /** @return array<string,mixed> */
    private function lockDocument(int $documentId, int $version): array
    {
        if ($documentId < 1 || $version < 1) throw new InvalidArgumentException('Некорректный документ');
        $row = $this->db->fetchOne(
            'SELECT id,kind,state,version,organization_id FROM work_permit_documents WHERE id=:id FOR UPDATE',
            [':id' => $documentId]
        );
        if ($row === null) throw new DomainException('Документ не найден', 404);
        if ($this->db->fetchOne(
            'SELECT document_id FROM work_permit_completions WHERE document_id=:id',
            [':id' => $documentId]
        ) !== null) {
            throw new DomainException('Работы завершены: новые допуски и остановки запрещены', 409);
        }
        // Проверка идемпотентности повторяется после блокировки, до проверки версии.
        if ((int) $row['version'] !== $version || $row['state'] !== 'issued') {
            throw new DomainException('Версия или состояние документа изменены. Обновите карточку', 409);
        }
        return $row;
    }

    /** @param array<string,mixed> $document */
    private function requirePermit(array $document): void
    {
        if ($document['kind'] !== 'permit' || empty($document['organization_id'])) {
            throw new DomainException('Ежедневные допуски предусмотрены только для выданных нарядов', 409);
        }
    }

    /** @return array<string,mixed> */
    private function issuance(int $documentId): array
    {
        $row = $this->db->fetchOne(
            'SELECT planned_start,planned_finish,producer_employee_id,time_zone
             FROM work_permit_issuances WHERE document_id=:id LIMIT 1',
            [':id' => $documentId]
        );
        if ($row === null) throw new DomainException('Нет зарегистрированной выдачи наряда', 409);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function lastPeriod(int $documentId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id,start_kind,started_at,ended_at,end_kind
             FROM work_permit_work_periods WHERE document_id=:doc ORDER BY id DESC LIMIT 1',
            [':doc' => $documentId]
        );
    }

    /** @param array<string,int|string|null> $employee */
    private function insertPeriod(int $doc, int $actor, string $kind, string $start, array $employee): void
    {
        $this->db->execute(
            'INSERT INTO work_permit_work_periods
             (document_id,start_kind,started_at,admitter_employee_id,admitter_name,
              instructor_employee_id,instructor_name,recipient_employee_id,recipient_name,
              workplace,workplace_prepared,briefing_confirmed,started_by_user_id)
             VALUES (:doc,:kind,:start,:admitter,:admitter_name,:instructor,:instructor_name,
                     :recipient,:recipient_name,:workplace,1,1,:actor)',
            [':doc' => $doc, ':kind' => $kind, ':start' => $start,
                ':admitter' => $employee['admitter'], ':admitter_name' => $employee['admitter_name'],
                ':instructor' => $employee['instructor'], ':instructor_name' => $employee['instructor_name'],
                ':recipient' => $employee['recipient'], ':recipient_name' => $employee['recipient_name'],
                ':workplace' => $employee['workplace'] ?? null, ':actor' => $actor]
        );
    }

    private function employee(int $id, int $organizationId): string
    {
        $row = $this->db->fetchOne(
            'SELECT name FROM work_permit_employees
             WHERE id=:id AND organization_id=:org AND is_archived=0 LIMIT 1',
            [':id' => $id, ':org' => $organizationId]
        );
        return $row === null
            ? throw new DomainException('Участник отсутствует или архивирован', 409)
            : (string) $row['name'];
    }

    private function advance(int $doc, int $actor, int $version, string $action, string $command, string $hash): void
    {
        $changed = $this->db->execute(
            'UPDATE work_permit_documents SET version=version+1
             WHERE id=:id AND state=\'issued\' AND version=:version',
            [':id' => $doc, ':version' => $version]
        );
        if ($changed !== 1) throw new DomainException('Версия документа изменилась', 409);
        $this->db->execute(
            'INSERT INTO work_permit_history (document_id,actor_user_id,event_type,version)
             VALUES (:doc,:actor,:action,:version)',
            [':doc' => $doc, ':actor' => $actor, ':action' => $action, ':version' => $version + 1]
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
             FROM work_permit_lifecycle_commands WHERE command_id=:command LIMIT 1',
            [':command' => $command]
        );
        if ($row === null) return null;
        if ((int) $row['document_id'] !== $doc || (int) $row['actor_user_id'] !== $actor
            || $row['action'] !== $action || !hash_equals((string) $row['request_hash'], $hash)) {
            throw new DomainException('Команда уже использована с другими параметрами', 409);
        }
        return $this->result($doc, (int) $row['result_version'], true);
    }

    /** @return array{id:int,version:int,replayed:bool,state:string} */
    private function result(int $doc, int $version, bool $replayed): array
    {
        return ['id' => $doc, 'version' => $version, 'replayed' => $replayed, 'state' => 'issued'];
    }

    private function fingerprint(int $doc, int $actor, string $action, array $payload): string
    {
        return hash('sha256', json_encode([$doc, $actor, $action, $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
            throw new InvalidArgumentException('Укажите уникальный идентификатор команды');
        }
        return $value;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Время должно быть передано в UTC');
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Несуществующая дата/время');
        }
        return $value;
    }

    /** Пустое значение допускается только для старых клиентов и записей. */
    public static function normalizeWorkplace(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Некорректное рабочее место допуска');
        }
        $workplace = trim($value);
        if ($workplace === '' || mb_strlen($workplace) > 500
            || preg_match('/[\x00-\x1F\x7F]/u', $workplace) === 1) {
            throw new InvalidArgumentException('Рабочее место должно содержать от 1 до 500 символов');
        }
        return $workplace;
    }

    private function note(mixed $value): string
    {
        if (!is_string($value) || mb_strlen(trim($value)) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Некорректное пояснение перерыва');
        }
        return trim($value);
    }
}
