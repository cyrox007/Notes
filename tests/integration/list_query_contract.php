<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/core/request.php';
require $root . '/app/services/ListQuery.php';

use App\Services\ListQuery;
use Core\Request;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$_GET = [
    'q' => str_repeat('я', 120),
    'page' => '-10',
    'limit' => '999',
    'sort' => 'unknown',
    'direction' => 'sideways',
];
$request = new Request();
$query = ListQuery::fromRequest($request, ['created_at' => 't.created_at', 'title' => 't.title'], 'created_at');
expect(mb_strlen($query['q']) === 100, 'Search query must be capped at 100 characters');
expect($query['page'] === 1, 'Page must be clamped to >= 1');
expect($query['limit'] === 20, 'Unsupported limit must fall back to default');
expect($query['sort'] === 'created_at', 'Unknown sort must fall back to whitelist default');
expect($query['direction'] === 'desc', 'Unknown direction must fall back to desc');
expect($query['offset'] === 0, 'Invalid page must not create a negative offset');

$_GET = [
    'q' => 'alpha',
    'page' => '3',
    'limit' => '50',
    'sort' => 'title',
    'direction' => 'asc',
];
$request = new Request();
$query = ListQuery::fromRequest($request, ['created_at' => 't.created_at', 'title' => 't.title'], 'created_at');
expect($query['q'] === 'alpha', 'Valid search query changed unexpectedly');
expect($query['page'] === 3, 'Valid page changed unexpectedly');
expect($query['limit'] === 50, 'Valid limit changed unexpectedly');
expect($query['offset'] === 100, 'Offset must be derived from page and limit');
expect($query['sort_column'] === 't.title', 'Whitelisted sort column was not resolved');
expect($query['direction_sql'] === 'ASC', 'Direction SQL must be normalized');

$pagination = ListQuery::pagination($query, 121);
expect($pagination['total_pages'] === 3, 'Total page calculation is incorrect');
expect($pagination['total'] === 121, 'Total count changed unexpectedly');

echo "List query contract: OK\n";
