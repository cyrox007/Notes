<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$profileFile = $root . '/tools/performance/profile.json';
$runnerFile = $root . '/tools/performance/load-runner.mjs';
$fixtureFile = $root . '/tools/performance/prepare-users.php';
$readmeFile = $root . '/tools/performance/README.md';

foreach ([$profileFile, $runnerFile, $fixtureFile, $readmeFile] as $file) {
    if (!is_file($file)) {
        throw new RuntimeException('Отсутствует файл нагрузочного контура: ' . $file);
    }
}

$profile = json_decode((string) file_get_contents($profileFile), true, 64, JSON_THROW_ON_ERROR);
if (($profile['schema'] ?? null) !== 1) {
    throw new RuntimeException('Профиль нагрузки должен иметь schema=1');
}
if ((int) ($profile['nominal_users'] ?? 0) < 1000) {
    throw new RuntimeException('Профиль обязан моделировать не менее 1000 пользователей');
}
if ((int) ($profile['peak_users'] ?? 0) <= (int) $profile['nominal_users']) {
    throw new RuntimeException('Пиковая нагрузка должна быть выше штатной');
}
if ((int) ($profile['ramp_seconds'] ?? 0) < 300 || (int) $profile['ramp_seconds'] > 600) {
    throw new RuntimeException('Разогрев должен занимать 5–10 минут');
}
if ((int) ($profile['steady_seconds'] ?? 0) < 1800) {
    throw new RuntimeException('Устойчивый этап должен длиться не менее 30 минут');
}
if ((int) ($profile['soak_seconds'] ?? 0) < 7200) {
    throw new RuntimeException('Длительная проверка должна длиться не менее 2 часов');
}

$operations = $profile['operations'] ?? [];
if (!is_array($operations) || $operations === []) {
    throw new RuntimeException('Профиль не содержит операций');
}
$weight = 0;
$names = [];
$kinds = [];
foreach ($operations as $operation) {
    if (!is_array($operation)) {
        throw new RuntimeException('Некорректная операция профиля');
    }
    $name = (string) ($operation['name'] ?? '');
    $kind = (string) ($operation['kind'] ?? '');
    if ($name === '' || isset($names[$name])) {
        throw new RuntimeException('Имена операций должны быть непустыми и уникальными');
    }
    $names[$name] = true;
    $kinds[$kind] = true;
    $weight += (int) ($operation['weight'] ?? 0);
}
if ($weight !== 100) {
    throw new RuntimeException('Сумма весов операций должна быть 100');
}
foreach (['read', 'write', 'realtime'] as $kind) {
    if (!isset($kinds[$kind])) {
        throw new RuntimeException('В профиле отсутствует тип операции: ' . $kind);
    }
}
foreach (['notes_search', 'files_list', 'messenger_long_poll', 'calendar_create', 'calendar_update', 'calendar_delete'] as $required) {
    if (!isset($names[$required])) {
        throw new RuntimeException('В профиле отсутствует обязательная операция: ' . $required);
    }
}

$runner = (string) file_get_contents($runnerFile);
foreach ([
    'new CookieJar()',
    "'/messenger/realtime/poll?wait_seconds=1'",
    "'X-CSRF-Token'",
    'p95_ms <= 700',
    'p95_ms <= 1200',
    'p99_ms <= 2500',
    "this.runMode === 'full'",
] as $marker) {
    if (!str_contains($runner, $marker)) {
        throw new RuntimeException('Runner потерял обязательный контракт: ' . $marker);
    }
}

$fixture = (string) file_get_contents($fixtureFile);
foreach ([
    'WORKSPACE_PERFORMANCE_ONLY',
    "preg_match('/^perf_",
    '@chmod($output, 0600)',
    "'performance_fixture' => true",
] as $marker) {
    if (!str_contains($fixture, $marker)) {
        throw new RuntimeException('Fixture-generator потерял защитный контракт: ' . $marker);
    }
}

$readme = (string) file_get_contents($readmeFile);
if (!str_contains($readme, 'не является доказательством производительности 1000 пользователей')) {
    throw new RuntimeException('Документация должна явно запрещать выдавать smoke за release evidence');
}

echo "Контракт performance-runner: OK\n";
