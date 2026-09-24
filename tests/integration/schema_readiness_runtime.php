<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/DatabaseOwnership.php';
require_once $root . '/core/SchemaReadiness.php';

use Core\SchemaReadiness;

$expectedReady = (string) (getenv('EXPECT_READY') ?: '') === '1';
$state = SchemaReadiness::inspect($root);

if ((bool) $state['ready'] !== $expectedReady) {
    fwrite(
        STDERR,
        'Проверка готовности схемы не совпала с ожиданием: '
        . json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . PHP_EOL
    );
    exit(1);
}

if ($expectedReady && ($state['missing_tables'] !== [] || $state['missing_user_columns'] !== [])) {
    fwrite(STDERR, "Готовая схема содержит отсутствующие элементы\n");
    exit(1);
}

if (!$expectedReady && $state['missing_tables'] === [] && $state['missing_user_columns'] === []) {
    fwrite(STDERR, "Устаревшая схема ошибочно признана полной\n");
    exit(1);
}

echo $expectedReady
    ? "Контракт готовности схемы: актуальная БД принята\n"
    : "Контракт готовности схемы: устаревшая БД заблокирована\n";
