<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'profile' => $root . '/tools/performance/profile.json',
    'profile12' => $root . '/tools/performance/profile-1.2.json',
    'httpSmoke12' => $root . '/tests/integration/performance_runner_smoke_12.mjs',
    'runner' => $root . '/tools/performance/load-runner.mjs',
    'users' => $root . '/tools/performance/prepare-users.php',
    'dataset' => $root . '/tools/performance/prepare-dataset.php',
    'environment' => $root . '/tools/performance/collect-environment.php',
    'metrics' => $root . '/tools/performance/sample-metrics.php',
    'reference' => $root . '/tools/performance/run-reference.sh',
    'readme' => $root . '/tools/performance/README.md',
];

foreach ($files as $name => $file) {
    if (!is_file($file)) {
        throw new RuntimeException('Отсутствует файл нагрузочного контура ' . $name . ': ' . $file);
    }
}

$profile = json_decode((string) file_get_contents($files['profile']), true, 64, JSON_THROW_ON_ERROR);
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

$profile12 = json_decode((string) file_get_contents($files['profile12']), true, 64, JSON_THROW_ON_ERROR);
$operations12 = $profile12['operations'] ?? [];
if (!is_array($operations12) || array_sum(array_map(
    static fn (array $operation): int => (int) ($operation['weight'] ?? 0),
    $operations12
)) !== 100) {
    throw new RuntimeException('Профиль 1.2 должен содержать операции с суммой весов 100');
}
$names12 = array_column($operations12, 'name');
foreach ([
    'notes_list', 'tasks_list', 'tasks_boards', 'files_list',
    'files_spaces', 'messenger_page', 'messenger_long_poll',
    'notifications_center', 'defects_list', 'defects_report',
    'calendar_create', 'calendar_update', 'calendar_delete',
] as $required) {
    if (!in_array($required, $names12, true)) {
        throw new RuntimeException('В нагрузочном профиле 1.2 отсутствует: ' . $required);
    }
}
if (($profile12['nominal_users'] ?? 0) < 1000 || ($profile12['peak_users'] ?? 0) < 1200
    || ($profile12['soak_seconds'] ?? 0) < 7200) {
    throw new RuntimeException('Нарушены требования полной приёмки 1.2');
}

assertMarkers($files['runner'], [
    'new CookieJar()',
    'PERF_ISOLATED_STAND',
    'sessionsActivePeak',
    'simultaneousSessions',
    'csrf_token: loginCsrf',
    "'/api/defects/?status=open&limit=40'",
    "'/messenger/realtime/poll?wait_seconds=1'",
    "'X-CSRF-Token'",
    'p95_ms <= 700',
    'p95_ms <= 1200',
    'p99_ms <= 2500',
    "this.runMode === 'full'",
], 'Runner');

assertMarkers($files['users'], [
    'WORKSPACE_PERFORMANCE_ONLY',
    "preg_match('/^perf_",
    '@chmod($output, 0600)',
    "'performance_fixture' => true",
], 'Генератор пользователей');

assertMarkers($files['dataset'], [
    'WORKSPACE_PERFORMANCE_ONLY',
    'performance_fixture',
    'MessengerSavedService',
    'notification_events',
    'user_files',
    'calendar_events',
    'stableUuid',
], 'Генератор набора данных');

assertMarkers($files['environment'], [
    'commit_sha',
    'max_connections',
    'innodb_buffer_pool_size',
    'database_size_bytes',
    'private_storage',
], 'Снимок окружения');

assertMarkers($files['metrics'], [
    'Threads_connected',
    'Threads_running',
    'Slow_queries',
    'Innodb_row_lock_current_waits',
    'notification_jobs_ready',
    'calendar_reminders_pending',
    'messenger_activity_active',
    '/proc/diskstats',
    '/proc/net/dev',
], 'Сборщик метрик');

assertMarkers($files['reference'], [
    'PERF_MODE=full',
    'git diff --quiet',
    'collect-environment.php',
    'sample-metrics.php',
    'load-runner.mjs',
    'sha256sum',
    'manifest.txt',
], 'Эталонный запуск');

$readme = (string) file_get_contents($files['readme']);
foreach ([
    'не является доказательством производительности 1000 пользователей',
    'репрезентативный набор данных',
    'Снимок эталонной конфигурации',
    'Серверные метрики',
    'manifest.txt',
] as $marker) {
    if (!str_contains($readme, $marker)) {
        throw new RuntimeException('Документация потеряла обязательный маркер: ' . $marker);
    }
}

echo "Контракт performance-runner: OK\n";

/** @param list<string> $markers */
function assertMarkers(string $file, array $markers, string $label): void
{
    $content = (string) file_get_contents($file);
    foreach ($markers as $marker) {
        if (!str_contains($content, $marker)) {
            throw new RuntimeException($label . ' потерял обязательный контракт: ' . $marker);
        }
    }
}
