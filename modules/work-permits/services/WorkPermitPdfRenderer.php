<?php

declare(strict_types=1);

namespace Modules\WorkPermits\Services;

use DomainException;

/**
 * Печатная учётная копия наряда по разделам утверждённых требований.
 * Только серверные снимки, без пользовательской разметки или внешних URL.
 */
final class WorkPermitPdfRenderer
{
    public function __construct(private readonly ?WorkPermitPdfFont $font = null)
    {
    }

    /** @param array<string,mixed> $document */
    public function render(array $document): string
    {
        if (($document['kind'] ?? null) !== 'permit') {
            throw new DomainException('PDF предусмотрен только для наряда-допуска', 409);
        }
        if (!in_array($document['state'] ?? null, ['issued', 'closed'], true)) {
            throw new DomainException('Сначала выдайте наряд, черновик не печатается', 409);
        }
        $lifecycle = $document['lifecycle'] ?? [];
        $issue = $lifecycle['issuance'] ?? null;
        if (!is_array($issue)) {
            throw new DomainException('Сведения о выдаче отсутствуют', 409);
        }
        $pdf = new WorkPermitPdfDocument($this->font ?? new WorkPermitPdfFont());

        $pdf->section('1. Регистрация наряда и место производства работ');
        $pdf->table(['Реквизит', 'Значение'], [
            ['Номер наряда', (string) ($document['id'] ?? '')],
            ['Состояние', $document['state'] === 'closed' ? 'Закрыт' : 'Выдан, в работе'],
            ['Организация', $this->text($document['organization'] ?? null)],
            ['Подразделение', $this->text($document['subdivision'] ?? null)],
            ['Объект (электроустановка)', $this->text($document['facility'] ?? null)],
            ['Дата создания (UTC)', $this->text($document['created_at'] ?? null)],
        ], [1.15, 2.75]);

        $pdf->section('2. Ответственные работники и состав бригады');
        $people = $this->roles($document['crew'] ?? []);
        $rows = [];
        foreach ([
            'issuer' => 'Выдавший наряд',
            'responsible_manager' => 'Ответственный руководитель',
            'admitter' => 'Допускающий',
            'producer' => 'Производитель работ',
            'observer' => 'Наблюдающий',
            'member' => 'Члены бригады',
        ] as $key => $label) {
            $rows[] = [$label, $people[$key] ?? match ($key) {
                'admitter' => 'Оперативный персонал',
                'observer' => 'Не назначается',
                default => 'Не назначен',
            }];
        }
        $pdf->table(['Роль', 'ФИО и группа по электробезопасности'], $rows, [1.45, 2.55]);

        $pdf->section('3. Задание на производство работ');
        $pdf->paragraph('', $this->text($document['work_description'] ?? null));

        $pdf->section('4. Подготовка рабочего места и меры безопасности');
        $measureRows = [];
        foreach ($lifecycle['measures'] ?? [] as $measure) {
            $measureRows[] = [
                $this->text($measure['equipment'] ?? null),
                $this->text($measure['isolation'] ?? null),
                $this->text($measure['remaining_live'] ?? null),
            ];
        }
        $pdf->table([
            'Электроустановка / участок',
            'Отключения, заземления, ограждения',
            'Оставшееся под напряжением',
        ], $measureRows, [1.05, 1.55, 1.35]);

        $pdf->section('5. Сроки, выдача и отдельные указания');
        $pdf->table(['Сведение', 'Значение'], [
            ['Начало (UTC)', $this->text($issue['planned_start'] ?? null)],
            ['Окончание (UTC)', $this->text($issue['planned_finish'] ?? null)],
            ['Наряд выдал', $this->text($issue['issuer_name'] ?? null)],
            ['Производитель работ', $this->text($issue['producer_name'] ?? null)],
            ['Зарегистрирован (UTC)', $this->text($issue['issued_at'] ?? null)],
        ], [1.3, 2.7]);
        $pdf->paragraph('Условия производства работ', $this->text($issue['work_conditions'] ?? null));
        $pdf->paragraph('Отдельные указания', $this->text($issue['special_instructions'] ?? null));

        $pdf->section('6. Первичный допуск и целевой инструктаж');
        $admission = $lifecycle['first_admission'] ?? null;
        $pdf->table(['Сведение', 'Значение'], is_array($admission) ? [
            ['Первый допуск (UTC)', $this->text($admission['started_at'] ?? null)],
            ['Допускающий', $this->text($admission['admitted_name'] ?? null)],
            ['Инструктаж провёл', $this->text($admission['briefed_by_name'] ?? null)],
            ['Инструктаж получил', $this->text($admission['briefing_recipient_name'] ?? null)],
            ['Место подготовлено', empty($admission['workplace_prepared']) ? 'Нет отметки' : 'Да, отмечено'],
            ['Инструктаж подтверждён', empty($admission['briefing_confirmed']) ? 'Нет отметки' : 'Да, отмечено'],
        ] : [], [1.5, 2.5]);

        $pdf->section('7. Ежедневные допуски, перерывы и окончание смен');
        $periodRows = [];
        foreach ($lifecycle['periods'] ?? [] as $period) {
            $periodRows[] = [
                match ($period['start_kind'] ?? '') {
                    'first' => 'Первый',
                    'daily_admission' => 'Дневной',
                    'resume' => 'После перерыва',
                    default => 'Допуск',
                },
                $this->text($period['started_at'] ?? null),
                $this->text($period['admitter_name'] ?? null),
                $this->text($period['instructor_name'] ?? null)
                    . ' / ' . $this->text($period['recipient_name'] ?? null),
                $this->text($period['ended_at'] ?? null) . "\n"
                    . match ($period['end_kind'] ?? '') {
                        'break' => 'Перерыв',
                        'day_end' => 'Конец дня',
                        default => 'Открыт',
                    } . "\n" . $this->text($period['end_note'] ?? null),
            ];
        }
        $pdf->table([
            'Вид', 'Начало UTC', 'Допускающий', 'Инструктаж: провёл / получил',
            'Окончание UTC и причина',
        ], $periodRows, [0.8, 1.0, 1.1, 1.4, 1.25]);

        $pdf->section('8. Продления наряда');
        $extensionRows = [];
        foreach ($lifecycle['extensions'] ?? [] as $extension) {
            $extensionRows[] = [
                $this->text($extension['previous_finish'] ?? null),
                $this->text($extension['new_finish'] ?? null),
                $this->text($extension['extended_at'] ?? null),
                $this->text($extension['issuer_name'] ?? null),
                $this->text($extension['reason'] ?? null),
            ];
        }
        $pdf->table([
            'Было до UTC', 'Продлено до UTC', 'Оформлено UTC', 'Выдал', 'Основание',
        ], $extensionRows, [1, 1, 1, 1, 1.5]);

        $pdf->section('9. Изменения состава бригады');
        $revisions = $lifecycle['crew_revisions'] ?? [];
        if ($revisions === []) {
            $pdf->paragraph('', 'Изменений состава бригады не зарегистрировано.');
        }
        foreach ($revisions as $index => $revision) {
            $pdf->paragraph(
                'Изменение №' . ($index + 1) . ' · ' . $this->text($revision['changed_at'] ?? null) . ' UTC',
                $this->text($revision['reason'] ?? null)
            );
            $revisionRoles = $this->roles($revision['members'] ?? []);
            $list = [];
            foreach ($revisionRoles as $role => $names) {
                $list[] = [$this->roleName($role), $names];
            }
            $pdf->table(['Роль', 'Состав после изменения'], $list, [1.35, 2.65]);
        }

        $pdf->section('10. Полное окончание работ');
        $completion = $lifecycle['completion'] ?? null;
        $pdf->table(['Сведение', 'Значение'], is_array($completion) ? [
            ['Окончание (UTC)', $this->text($completion['completed_at'] ?? null)],
            ['Производитель работ', $this->text($completion['producer_name'] ?? null)],
            ['Безопасное состояние', !empty($completion['workplace_safe']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Бригада выведена', !empty($completion['crew_withdrawn']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Примечание', $this->text($completion['completion_note'] ?? null)],
        ] : [], [1.25, 2.75]);

        $pdf->section('11. Окончательное закрытие наряда');
        $closure = $lifecycle['closure'] ?? null;
        $pdf->table(['Сведение', 'Значение'], is_array($closure) ? [
            ['Закрыт (UTC)', $this->text($closure['closed_at'] ?? null)],
            ['Закрывающий работник', $this->text($closure['closer_name'] ?? null)],
            ['Рабочее место принято', !empty($closure['workplace_accepted']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Документы переданы', !empty($closure['documents_handed_over']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Примечание', $this->text($closure['closure_note'] ?? null)],
        ] : [], [1.25, 2.75]);

        $pdf->section('Отметки и подписи');
        $pdf->paragraph('', 'Электронные отметки в Notes фиксируют события учёта. '
            . 'Они не являются юридически значимой подписью работника. '
            . 'Распечатанный документ должен пройти оформление по действующим '
            . 'правилам организации; пустые подписи в данном PDF не считаются проставленными.');
        return $pdf->build();
    }

    /** @param list<array<string,mixed>> $crew @return array<string,string> */
    private function roles(array $crew): array
    {
        $roles = [];
        foreach ($crew as $item) {
            $role = (string) ($item['role'] ?? '');
            $name = $this->text($item['employee_name'] ?? null);
            $group = $this->text($item['electrical_group'] ?? null);
            $roles[$role][] = $group === '' ? $name : $name . ' (гр. ' . $group . ')';
        }
        return array_map(static fn (array $names): string => implode(', ', $names), $roles);
    }

    private function roleName(string $role): string
    {
        return match ($role) {
            'issuer' => 'Выдавший',
            'responsible_manager' => 'Ответственный руководитель',
            'admitter' => 'Допускающий',
            'producer' => 'Производитель',
            'observer' => 'Наблюдающий',
            'member' => 'Члены бригады',
            default => 'Назначение',
        };
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
