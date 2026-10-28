<?php

declare(strict_types=1);

namespace Modules\Defects\Services;

use Core\DatabaseManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Поиск и агрегированная отчётность на основе одной проверяемой политики фильтров.
 * SQL выполняется средствами ядра; имя столбца не принимается от клиента.
 */
final class DefectReportService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /** @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,next_cursor:int|null}
     */
    public function search(array $filters, int $limit = 40, int $afterId = 0): array
    {
        if ($limit < 1 || $limit > 100 || $afterId < 0) {
            throw new InvalidArgumentException('Неверные параметры страницы');
        }

        [$where, $params] = $this->conditions($filters);
        $sort = $filters['sort'] ?? '';
        $sortColumns = [
            'id_desc' => 'd.id DESC',
            'due_asc' => 'd.due_at ASC, d.id ASC',
            'due_desc' => 'd.due_at DESC, d.id ASC',
            'area_asc' => 'a.name ASC, d.id ASC',
            'object_asc' => 'o.name ASC, d.id ASC',
        ];
        if (!is_string($sort) || ($sort !== '' && !isset($sortColumns[$sort]))) {
            throw new InvalidArgumentException('Недопустимый порядок сортировки');
        }

        // Для прежних клиентов сохраняем постраничный курсор по идентификатору.
        // При сортировке по другим полям курсор означает смещение в наборе.
        if ($sort === '') {
            $where[] = 'd.id > :after_id';
            $params['after_id'] = $afterId;
        }
        $orderBy = $sort === '' ? 'd.id ASC' : $sortColumns[$sort];
        $offset = $sort === '' ? '' : ' OFFSET ' . $afterId;
        $rows = $this->database->fetchAll(
            'SELECT d.id,d.object_id,d.description,d.priority,d.discovered_at,d.due_at,
                    d.registered_by_name,d.created_at,d.status,d.version,d.closed_at,
                    o.name AS object_name,c.name AS category_name,a.name AS area_name,
                    (SELECT GROUP_CONCAT(s.name)
                       FROM defect_responsible_services ds
                       JOIN defect_services s ON s.id=ds.service_id
                       WHERE ds.defect_id=d.id) AS service_names
             ' . $this->relations()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit . $offset,
            $params
        );
        $next = $rows === [] ? null : ($sort === ''
            ? (int) end($rows)['id'] : $afterId + count($rows));
        return ['items' => $rows, 'next_cursor' => $next];
    }

    /** @param array<string,mixed> $filters
     * @return array{total:int,overdue:int,by_priority:list<array<string,mixed>>,by_area:list<array<string,mixed>>,next_area_cursor:int|null}
     */
    public function summary(array $filters, int $afterAreaId = 0, int $areaLimit = 50): array
    {
        if ($afterAreaId < 0 || $areaLimit < 1 || $areaLimit > 100) {
            throw new InvalidArgumentException('Неверные параметры отчёта');
        }

        [$where, $params] = $this->conditions($filters);
        $relations = $this->relations();
        $conditionSql = implode(' AND ', $where);
        // Одна агрегирующая выборка вместо трёх отдельных проходов по
        // одним и тем же связям. Итоги и разбивка по приоритетам согласованы.
        $priorities = $this->database->fetchAll(
            'SELECT d.priority,d.status,COUNT(*) AS total,
                    SUM(CASE WHEN d.status = :open_status
                        AND d.due_at < :now_utc THEN 1 ELSE 0 END) AS overdue
             ' . $relations
            . ' WHERE ' . $conditionSql
            . ' GROUP BY d.priority,d.status ORDER BY d.priority,d.status',
            $params + ['open_status' => 'open', 'now_utc' => gmdate('Y-m-d H:i:s')]
        );

        $total = 0;
        $overdue = 0;
        foreach ($priorities as &$row) {
            $total += (int) $row['total'];
            $overdue += (int) $row['overdue'];
            unset($row['overdue']);
        }
        unset($row);
        $areaParams = $params + ['after_area_id' => $afterAreaId];
        $areas = $this->database->fetchAll(
            'SELECT a.id AS area_id,a.name AS area_name,COUNT(*) AS total ' . $relations
            . ' WHERE ' . $conditionSql . ' AND a.id > :after_area_id'
            . ' GROUP BY a.id,a.name ORDER BY a.id ASC LIMIT ' . ($areaLimit + 1),
            $areaParams
        );
        $more = count($areas) > $areaLimit;
        if ($more) {
            array_pop($areas);
        }
        return [
            'total' => $total,
            'overdue' => $overdue,
            'by_priority' => $priorities,
            'by_area' => $areas,
            'next_area_cursor' => $more ? (int) end($areas)['area_id'] : null,
        ];
    }

    private function relations(): string
    {
        return 'FROM defects d '
            . 'JOIN defect_objects o ON o.id=d.object_id '
            . 'JOIN defect_object_categories c ON c.id=o.category_id '
            . 'JOIN defect_areas a ON a.id=c.area_id';
    }

    /** @param array<string,mixed> $filters
     * @return array{0:list<string>,1:array<string,int|string>}
     */
    private function conditions(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $status = $filters['status'] ?? 'open';
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('Неверное состояние');
        }
        if ($status !== 'all') {
            $where[] = 'd.status=:status';
            $params['status'] = $status;
        }

        $priority = $filters['priority'] ?? '';
        if ($priority !== '') {
            if (!is_string($priority)) {
                throw new InvalidArgumentException('Неверный приоритет');
            }
            DefectPriorityPolicy::days($priority);
            $where[] = 'd.priority=:priority';
            $params['priority'] = $priority;
        }

        foreach (['area_id' => 'a.id', 'object_id' => 'd.object_id'] as $key => $column) {
            $id = $this->identifier($filters[$key] ?? null, $key);
            if ($id !== null) {
                $where[] = $column . '=:' . $key;
                $params[$key] = $id;
            }
        }

        $serviceId = $this->identifier($filters['service_id'] ?? null, 'service_id');
        if ($serviceId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM defect_responsible_services ds '
                . 'WHERE ds.defect_id=d.id AND ds.service_id=:service_id)';
            $params['service_id'] = $serviceId;
        }

        // Для существующих клиентов from/to остаются датой обнаружения.
        // Новые диапазоны регистрации и срока независимы друг от друга.
        foreach ([
            ['from', 'to', 'd.discovered_at', 'discovered'],
            ['registered_from', 'registered_to', 'd.created_at', 'registered'],
            ['due_from', 'due_to', 'd.due_at', 'due'],
        ] as [$first, $last, $column, $prefix]) {
            $from = $this->date($filters[$first] ?? null, $first);
            $to = $this->date($filters[$last] ?? null, $last);
            if ($from !== null && $to !== null && $from > $to) {
                throw new InvalidArgumentException('Начало периода позже окончания: ' . $prefix);
            }
            if ($from !== null) {
                $where[] = $column . ' >= :' . $prefix . '_from';
                $params[$prefix . '_from'] = $from->format('Y-m-d');
            }
            if ($to !== null) {
                $where[] = $column . ' < :' . $prefix . '_to';
                $params[$prefix . '_to'] = $to->modify('+1 day')->format('Y-m-d');
            }
        }

        return [$where, $params];
    }

    private function identifier(mixed $value, string $name): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]{1,12}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('Неверный идентификатор ' . $name);
        }
        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('Неверный идентификатор ' . $name);
        }
        return $id;
    }

    private function date(mixed $value, string $name): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Неверная дата ' . $name);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Неверная дата ' . $name);
        }
        return $parsed;
    }
}
