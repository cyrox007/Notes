<?php

declare(strict_types=1);

namespace Modules\Calendar\Services;

use DateTimeImmutable;

final class CalendarAgendaService
{
    public function __construct(
        private ?CalendarEventService $events = null,
        private ?CalendarTimeBlockService $blocks = null
    ) {
        $this->events ??= new CalendarEventService();
        $this->blocks ??= new CalendarTimeBlockService();
    }

    /** @return array{items:list<array<string,mixed>>,from:string,to:string} */
    public function listForUser(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $eventResult = $this->events->listForUser($userId, $from, $to);
        $items = array_map(static function (array $event): array {
            $event['kind'] = 'event';
            return $event;
        }, $eventResult['events']);

        foreach ($this->blocks->listForUser($userId, $from, $to) as $block) {
            $items[] = $block;
        }
        usort($items, static fn (array $left, array $right): int => [
            (string) ($left['starts_at_utc'] ?? ''),
            (string) ($left['kind'] ?? ''),
            (string) ($left['uid'] ?? ''),
        ] <=> [
            (string) ($right['starts_at_utc'] ?? ''),
            (string) ($right['kind'] ?? ''),
            (string) ($right['uid'] ?? ''),
        ]);

        return [
            'items' => array_values($items),
            'from' => $eventResult['from'],
            'to' => $eventResult['to'],
        ];
    }
}
