<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/CrawlerDefense.php';

use Core\CrawlerDefense;

function crawlerDefenseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] защита от сканеров: {$message}\n");
        exit(1);
    }
}

foreach ([
    '/.well-known/workspace-crawl-trap' => 'crawler_trap',
    '/workspace/.well-known/workspace-crawl-trap' => 'crawler_trap',
    '/.env' => 'secret_file',
    '/.env.production' => 'secret_file',
    '/.git/config' => 'secret_file',
    '/wp-login.php' => 'cms_probe',
    '/wp-admin/' => 'cms_probe',
    '/phpmyadmin/' => 'db_admin_probe',
    '/adminer.php' => 'db_admin_probe',
    '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php' => 'runtime_probe',
    '/server-status' => 'runtime_probe',
    '/composer.json' => 'metadata_probe',
    '/backup.zip' => 'metadata_probe',
] as $path => $expected) {
    crawlerDefenseAssert(
        CrawlerDefense::classifyPath($path) === $expected,
        "{$path} не классифицирован как {$expected}"
    );
}

foreach ([
    '/',
    '/auth/login/',
    '/auth/registration/',
    '/files/',
    '/messenger/realtime/poll/',
    '/assets/css/workspace-ui-1.0.css',
] as $path) {
    crawlerDefenseAssert(
        CrawlerDefense::classifyPath($path) === null,
        "штатный маршрут ошибочно классифицирован как scanner probe: {$path}"
    );
}

$robots = (string) file_get_contents($root . '/robots.txt');
crawlerDefenseAssert(
    str_contains($robots, "User-agent: *")
        && str_contains($robots, "Disallow: /")
        && str_contains($robots, CrawlerDefense::TRAP_PATH),
    'robots.txt не закрывает Workspace от индексации или не содержит trap'
);

$headers = (string) file_get_contents($root . '/core/SecurityHeaders.php');
crawlerDefenseAssert(
    str_contains($headers, 'X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex'),
    'динамические ответы не получают X-Robots-Tag'
);

$apache = (string) file_get_contents($root . '/.htaccess');
crawlerDefenseAssert(
    str_contains($apache, 'X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex"'),
    'Apache static responses не получают X-Robots-Tag'
);

foreach ([
    'app/views/core/base.php',
    'app/views/login_page/login_layout.php',
] as $relative) {
    $view = (string) file_get_contents($root . '/' . $relative);
    crawlerDefenseAssert(
        str_contains($view, 'noindex,nofollow,noarchive,nosnippet,noimageindex')
            && str_contains($view, 'workspace-crawl-trap'),
        "{$relative} не содержит noindex и crawler trap"
    );
}

$index = (string) file_get_contents($root . '/index.php');
crawlerDefenseAssert(
    str_contains($index, '\\Core\\CrawlerDefense::handleEarlyRequest();'),
    'ранний scanner guard не подключён в index.php'
);

fwrite(STDOUT, "[OK] robots noindex, scanner probes, crawler trap и throttling-контур закреплены\n");
