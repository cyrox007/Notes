<?php

declare(strict_types=1);

namespace App\Services;

use Core\Request;

final class ListQuery
{
    /** @var list<int> */
    private const LIMITS = [10, 20, 50];

    /**
     * @param array<string,string> $sortColumns
     * @return array{q:string,page:int,limit:int,offset:int,sort:string,sort_column:string,direction:string,direction_sql:string}
     */
    public static function fromRequest(
        Request $request,
        array $sortColumns,
        string $defaultSort,
        int $defaultLimit = 20
    ): array {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) > 100) {
            $q = mb_substr($q, 0, 100);
        }

        $page = max(1, (int) $request->get('page', 1));
        $requestedLimit = (int) $request->get('limit', $defaultLimit);
        $limit = in_array($requestedLimit, self::LIMITS, true) ? $requestedLimit : $defaultLimit;
        $sort = (string) $request->get('sort', $defaultSort);
        if (!array_key_exists($sort, $sortColumns)) {
            $sort = $defaultSort;
        }
        $direction = strtolower((string) $request->get('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [
            'q' => $q,
            'page' => $page,
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
            'sort' => $sort,
            'sort_column' => $sortColumns[$sort],
            'direction' => $direction,
            'direction_sql' => strtoupper($direction),
        ];
    }

    /**
     * @param array{q:string,page:int,limit:int,offset:int,sort:string,sort_column:string,direction:string,direction_sql:string} $query
     * @return array{q:string,page:int,limit:int,offset:int,sort:string,sort_column:string,direction:string,direction_sql:string}
     */
    public static function clampToTotal(array $query, int $total): array
    {
        $state = self::pageState((int) $query['page'], (int) $query['limit'], $total);
        $query['page'] = $state['page'];
        $query['offset'] = $state['offset'];
        return $query;
    }

    /** @return array{q:string,page:int,limit:int,total:int,total_pages:int,sort:string,direction:string} */
    public static function pagination(array $query, int $total): array
    {
        $state = self::pageState((int) $query['page'], (int) $query['limit'], $total);
        return [
            'q' => (string) $query['q'],
            'page' => $state['page'],
            'limit' => (int) $query['limit'],
            'total' => max(0, $total),
            'total_pages' => $state['total_pages'],
            'sort' => (string) $query['sort'],
            'direction' => (string) $query['direction'],
        ];
    }

    /** @return array{page:int,offset:int,total_pages:int} */
    public static function pageState(int $page, int $limit, int $total): array
    {
        $limit = max(1, $limit);
        $totalPages = max(1, (int) ceil(max(0, $total) / $limit));
        $page = min(max(1, $page), $totalPages);

        return [
            'page' => $page,
            'offset' => ($page - 1) * $limit,
            'total_pages' => $totalPages,
        ];
    }
}
