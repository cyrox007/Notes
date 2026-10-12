<?php

declare(strict_types=1);

namespace Core;

/**
 * Публичный межмодульный контракт безопасных целей для связей Calendar.
 *
 * Реализация принадлежит модулю-источнику и возвращает только минимальные
 * метаданные, доступные текущему пользователю. Calendar не читает внутренние
 * таблицы соседних модулей напрямую.
 */
interface WorkspaceCalendarLinkTargetProvider
{
    /**
     * @return list<array{uid:string,title:string,subtitle:string,href:string}>
     */
    public function listCalendarLinkTargets(int $userId, string $query = '', int $limit = 20): array;

    /**
     * @return array{uid:string,title:string,subtitle:string,href:string}
     */
    public function resolveCalendarLinkTarget(int $userId, string $uid): array;
}
