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

    /**
     * Печатный макет с отдельными сторонами и незаполненными графами подписи.
     * Учётные отметки не превращаются в подписи работников.
     *
     * @param array<string,mixed> $document
     */
    public function renderForm(array $document): string
    {
        if (($document['kind'] ?? null) !== 'permit'
            || !in_array($document['state'] ?? null, ['issued', 'closed'], true)) {
            throw new DomainException('Печатная форма доступна только для выданного наряда', 409);
        }
        $lifecycle = $document['lifecycle'] ?? [];
        $issue = $lifecycle['issuance'] ?? null;
        if (!is_array($issue)) {
            throw new DomainException('Нет сведений о выдаче', 409);
        }
        $zone = WorkPermitLocalDay::validate($issue['time_zone'] ?? 'UTC');
        $pdf = new WorkPermitPdfDocument($this->font ?? new WorkPermitPdfFont());
        $pdf->section('НАРЯД-ДОПУСК № ' . $this->text($document['id'] ?? null) . ' — ЛИЦЕВАЯ СТОРОНА');
        $pdf->paragraph('', 'ПЕЧАТНЫЙ МАКЕТ — не заменяет утверждённый бланк и подписи работников.');
        $pdf->table(['Реквизит', 'Содержание'], [
            ['Организация', $this->text($document['organization'] ?? null)],
            ['Подразделение', $this->text($document['subdivision'] ?? null)],
            ['Электроустановка', $this->text($document['facility'] ?? null)],
            ['Поручается', $this->text($document['work_description'] ?? null)],
            ['Начать работу', $this->localTime($issue['planned_start'] ?? null, $zone)],
            ['Закончить работу', $this->localTime($issue['planned_finish'] ?? null, $zone)],
        ]);
        $roles = $this->roles($document['crew'] ?? []);
        $roleLabels = [
            'issuer' => 'Выдающий',
            'responsible_manager' => 'Ответственный руководитель',
            'admitter' => 'Допускающий',
            'producer' => 'Производитель работ',
            'observer' => 'Наблюдающий',
            'member' => 'Члены бригады',
        ];
        $roleRows = [];
        foreach ($roleLabels as $role => $label) {
            $roleRows[] = [$label, $roles[$role] ?? 'Не назначен'];
        }
        $pdf->table(['Назначение', 'ФИО, группа'], $roleRows);
        $rows = [];
        foreach ($lifecycle['measures'] ?? [] as $measure) {
            $isolated = $this->text($measure['isolated_parts'] ?? null);
            $rows[] = [
                $this->text($measure['equipment'] ?? null),
                $this->text($measure['isolation'] ?? null),
                $isolated !== '' ? $isolated : 'Не указано в исходной записи',
            ];
        }
        $pdf->section('Мероприятия по подготовке рабочих мест');
        $pdf->table(['Электроустановка', 'Что должно быть отключено и заземлено',
            'Что должно быть изолировано (ограждено)'], $rows);
        $pdf->paragraph('Условия производства работ', $this->text($issue['work_conditions'] ?? null));
        $pdf->paragraph('Отдельные указания', $this->text($issue['special_instructions'] ?? null));
        $pdf->table(['Оформление выдачи', 'ФИО и время', 'Собственноручная подпись'], [
            ['Наряд выдал', $this->text($issue['issuer_name'] ?? null)
                . ' / ' . $this->localTime($issue['issued_at'] ?? null, $zone), '________________'],
        ]);
        $extensions = [];
        foreach ($lifecycle['extensions'] ?? [] as $extension) {
            $extensions[] = [
                $this->localTime($extension['new_finish'] ?? null, $zone),
                $this->text($extension['issuer_name'] ?? null),
                '________________',
            ];
        }
        $pdf->table(['Наряд продлён по', 'Продлил', 'Подпись на бумаге'], $extensions);
        // Оборотная сторона не должна начинаться ниже лицевых реквизитов.
        $pdf->pageBreak();
        $pdf->section('ОБОРОТНАЯ СТОРОНА — учётные сведения');
        $permissions = [];
        foreach ($lifecycle['preparation_permissions'] ?? [] as $entry) {
            $method = $this->text($entry['communication_method'] ?? null);
            $place = $this->text($entry['workplace'] ?? null);
            $permissions[] = [
                $this->localTime($entry['granted_at'] ?? null, $zone),
                ($place !== '' ? $place : 'Место не указано')
                    . ($method !== '' ? "\nСпособ связи: " . $method : ''),
                $this->text($entry['grantor_name'] ?? null),
                '__________',
                $this->text($entry['receiver_name'] ?? null),
                '__________',
            ];
        }
        $pdf->section('Разрешения на подготовку рабочего места и допуск');
        $pdf->table(
            ['Разрешено', 'Место / связь', 'Передал', 'Подпись передавшего',
                'Получил', 'Подпись получившего'],
            $permissions, [1.15, 1.55, 1.15, 0.9, 1.15, 0.9]
        );
        $briefings = [];
        foreach ($lifecycle['target_briefings'] ?? [] as $entry) {
            $stage = match ($entry['stage'] ?? '') {
                'issuer' => 'При выдаче',
                'manager' => 'Ответственным руководителем',
                'producer' => 'Производителем',
                'admitter' => 'Допускающим',
                default => 'Этап не указан',
            };
            $group = $this->text($entry['recipient_group'] ?? null);
            $briefings[] = [
                $this->localTime($entry['briefed_at'] ?? null, $zone),
                $stage . "\n" . $this->text($entry['topic'] ?? null),
                $this->text($entry['instructor_name'] ?? null),
                '__________',
                $this->text($entry['recipient_name'] ?? null)
                    . ($group !== '' ? ' (гр. ' . $group . ')' : ''),
                '__________',
            ];
        }
        $pdf->section('Целевые инструктажи');
        $pdf->table(
            ['Время', 'Этап / тема', 'Провёл', 'Подпись проводившего',
                'Получил', 'Подпись получившего'],
            $briefings, [1.15, 1.65, 1.15, 0.9, 1.15, 0.9]
        );
        $periods = [];
        foreach ($lifecycle['periods'] ?? [] as $period) {
            $kind = match ($period['start_kind'] ?? '') {
                'first' => 'Первичный допуск',
                'daily_admission' => 'Ежедневный допуск',
                'resume' => 'После перерыва',
                default => 'Допуск',
            };
            $end = $period['ended_at'] ?? null;
            $periods[] = [
                $kind . "\n" . $this->localTime($period['started_at'] ?? null, $zone)
                    . "\nОкончание: " . ($end !== null
                        ? $this->localTime($end, $zone) : 'Не зарегистрировано'),
                $this->text($period['admitter_name'] ?? null),
                '__________',
                $this->text($period['recipient_name'] ?? null),
                '__________',
            ];
        }
        $pdf->section('Ежедневные допуски и окончание работ');
        $pdf->table(
            ['Допуск / окончание', 'Допускающий', 'Подпись допускающего',
                'Производитель работ', 'Подпись производителя'],
            $periods, [1.6, 1.2, 1.0, 1.25, 1.0]
        );
        $revisions = [];
        foreach ($lifecycle['crew_revisions'] ?? [] as $revision) {
            $revisions[] = [
                $this->localTime($revision['changed_at'] ?? null, $zone),
                $this->text($revision['reason'] ?? null),
                '________________',
            ];
        }
        $pdf->section('Изменения состава бригады');
        $pdf->table(['Время изменения', 'Основание', 'Подпись на бумаге'], $revisions);
        $completion = $lifecycle['completion'] ?? null;
        $closure = $lifecycle['closure'] ?? null;
        $pdf->section('Оформление полного окончания и закрытия');
        $pdf->table(['Событие', 'Время объекта', 'Ответственный', 'Подпись'], [
            ['Работа закончена',
                $this->localTime($completion['completed_at'] ?? null, $zone),
                $this->text($completion['producer_name'] ?? null), '________________'],
            ['Наряд закрыт',
                $this->localTime($closure['closed_at'] ?? null, $zone),
                $this->text($closure['closer_name'] ?? null), '________________'],
        ]);
        $pdf->paragraph('', 'Все линии подписей оставлены пустыми. Электронные отметки Notes не являются подписями.');
        return $pdf->build();
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
        $zone = WorkPermitLocalDay::validate($issue['time_zone'] ?? 'UTC');
        $pdf = new WorkPermitPdfDocument($this->font ?? new WorkPermitPdfFont());

        $pdf->section('1. Регистрация наряда и место производства работ');
        $pdf->table(['Реквизит', 'Значение'], [
            ['Номер наряда', (string) ($document['id'] ?? '')],
            ['Состояние', $document['state'] === 'closed' ? 'Закрыт' : 'Выдан, в работе'],
            ['Организация', $this->text($document['organization'] ?? null)],
            ['Подразделение', $this->text($document['subdivision'] ?? null)],
            ['Объект (электроустановка)', $this->text($document['facility'] ?? null)],
            ['Дата создания', $this->localTime($document['created_at'] ?? null, $zone)],
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
            ['Часовой пояс объекта (IANA)', $zone],
            ['Работу начать (время объекта)', $this->localTime($issue['planned_start'] ?? null, $zone)],
            ['Работу закончить (время объекта)', $this->localTime($issue['planned_finish'] ?? null, $zone)],
            ['Наряд выдал', $this->text($issue['issuer_name'] ?? null)],
            ['Производитель работ', $this->text($issue['producer_name'] ?? null)],
            ['Выдан (время объекта)', $this->localTime($issue['issued_at'] ?? null, $zone)],
        ], [1.3, 2.7]);
        $pdf->paragraph('Условия производства работ', $this->text($issue['work_conditions'] ?? null));
        $pdf->paragraph('Отдельные указания', $this->text($issue['special_instructions'] ?? null));

        $pdf->section('6. Разрешения на подготовку рабочих мест и допуск');
        $permissionRows = [];
        foreach ($lifecycle['preparation_permissions'] ?? [] as $permission) {
            $permissionRows[] = [
                $this->localTime($permission['granted_at'] ?? null, $zone),
                $this->text($permission['grantor_name'] ?? null),
                $this->text($permission['receiver_name'] ?? null),
                $this->text($permission['workplace'] ?? null),
                $this->text($permission['communication_method'] ?? null),
            ];
        }
        $pdf->table(
            ['Получено (время объекта)', 'Передал', 'Получил', 'Рабочее место', 'Способ'],
            $permissionRows, [1.15, 1.1, 1.1, 1.5, 0.8]
        );
        $pdf->paragraph('', 'Разрешения отражены как учётные сведения без подписи работников.');

        $pdf->section('7. Целевые инструктажи (индивидуальные записи)');
        $briefingRows = [];
        foreach ($lifecycle['target_briefings'] ?? [] as $briefing) {
            $briefingRows[] = [
                $this->text($briefing['stage'] ?? null),
                $this->localTime($briefing['briefed_at'] ?? null, $zone),
                $this->text($briefing['instructor_name'] ?? null),
                $this->text($briefing['recipient_name'] ?? null)
                    . ' (гр. ' . $this->text($briefing['recipient_group'] ?? null) . ')',
                $this->text($briefing['topic'] ?? null),
            ];
        }
        $pdf->table(
            ['Этап', 'Время объекта', 'Провёл', 'Получил', 'Содержание'],
            $briefingRows, [0.7, 1.1, 1.05, 1.2, 1.4]
        );
        $pdf->paragraph('', 'Отметки о проведении инструктажа не заменяют таблицу подписей утверждённого бланка.');

        $pdf->section('8. Первичный допуск и целевой инструктаж допускающим');
        $admission = $lifecycle['first_admission'] ?? null;
        $pdf->table(['Сведение', 'Значение'], is_array($admission) ? [
            ['Первый допуск (время объекта)', $this->localTime($admission['started_at'] ?? null, $zone)],
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
                $this->localTime($period['started_at'] ?? null, $zone),
                $this->text($period['admitter_name'] ?? null),
                $this->text($period['instructor_name'] ?? null)
                    . ' / ' . $this->text($period['recipient_name'] ?? null),
                $this->localTime($period['ended_at'] ?? null, $zone) . "\n"
                    . match ($period['end_kind'] ?? '') {
                        'break' => 'Перерыв',
                        'day_end' => 'Конец дня',
                        default => 'Открыт',
                    } . "\n" . $this->text($period['end_note'] ?? null),
            ];
        }
        $pdf->table([
            'Вид', 'Начало (объект)', 'Допускающий', 'Инструктаж: провёл / получил',
            'Окончание (объект) и причина',
        ], $periodRows, [0.8, 1.0, 1.1, 1.4, 1.25]);

        $pdf->section('8. Продления наряда');
        $extensionRows = [];
        foreach ($lifecycle['extensions'] ?? [] as $extension) {
            $extensionRows[] = [
                $this->localTime($extension['previous_finish'] ?? null, $zone),
                $this->localTime($extension['new_finish'] ?? null, $zone),
                $this->localTime($extension['extended_at'] ?? null, $zone),
                $this->text($extension['issuer_name'] ?? null),
                $this->text($extension['reason'] ?? null),
            ];
        }
        $pdf->table([
            'Было до (объект)', 'Продлено до (объект)', 'Оформлено (объект)', 'Выдал', 'Основание',
        ], $extensionRows, [1, 1, 1, 1, 1.5]);

        $pdf->section('9. Изменения состава бригады');
        $revisions = $lifecycle['crew_revisions'] ?? [];
        if ($revisions === []) {
            $pdf->paragraph('', 'Изменений состава бригады не зарегистрировано.');
        }
        foreach ($revisions as $index => $revision) {
            $pdf->paragraph(
                'Изменение №' . ($index + 1) . ' · ' . $this->localTime($revision['changed_at'] ?? null, $zone),
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
            ['Окончание (время объекта)', $this->localTime($completion['completed_at'] ?? null, $zone)],
            ['Производитель работ', $this->text($completion['producer_name'] ?? null)],
            ['Безопасное состояние', !empty($completion['workplace_safe']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Бригада выведена', !empty($completion['crew_withdrawn']) ? 'Подтверждено' : 'Не подтверждено'],
            ['Примечание', $this->text($completion['completion_note'] ?? null)],
        ] : [], [1.25, 2.75]);

        $pdf->section('11. Окончательное закрытие наряда');
        $closure = $lifecycle['closure'] ?? null;
        $pdf->table(['Сведение', 'Значение'], is_array($closure) ? [
            ['Закрыт (время объекта)', $this->localTime($closure['closed_at'] ?? null, $zone)],
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

    private function localTime(mixed $value, string $zone): string
    {
        $utc = $this->text($value);
        return $utc === '' ? 'Не заполнено' : WorkPermitLocalDay::forPrint($utc, $zone);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
