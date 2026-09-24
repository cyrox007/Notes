<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root . '/.github/release-governance.json';
$templatePath = $root . '/.github/pull_request_template.md';
$releaseGatePath = $root . '/.github/workflows/release-gate.yml';

function failContract(string $message): never
{
    fwrite(STDERR, "Контракт release governance нарушен: {$message}\n");
    exit(1);
}

function requireFileText(string $path): string
{
    if (!is_file($path)) {
        failContract('отсутствует файл: ' . $path);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        failContract('не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function pullRequestTriggerBody(string $workflow): string
{
    if (preg_match('/^  pull_request:\s*\n(?<body>(?:^    .*\n)*)/m', $workflow, $match) !== 1) {
        failContract('workflow не содержит pull_request trigger');
    }

    return (string) ($match['body'] ?? '');
}

$policy = json_decode(requireFileText($policyPath), true);
if (!is_array($policy) || json_last_error() !== JSON_ERROR_NONE) {
    failContract('некорректный JSON policy');
}

if (($policy['protected_branch'] ?? null) !== 'master') {
    failContract('protected_branch должен быть master');
}
if (($policy['stabilization_branch'] ?? null) !== '1.0') {
    failContract('stabilization_branch должен быть 1.0');
}

foreach ([
    'require_pull_request',
    'require_branch_up_to_date',
    'dismiss_stale_reviews',
    'block_force_pushes',
    'block_branch_deletion',
] as $flag) {
    if (($policy[$flag] ?? false) !== true) {
        failContract("{$flag} должен быть true");
    }
}

if ((int) ($policy['required_approvals_when_independent_reviewer_exists'] ?? 0) !== 1) {
    failContract('при наличии независимого reviewer требуется одно approval');
}

$requiredChecks = $policy['required_checks'] ?? null;
$stabilizationChecks = $policy['stabilization_required_checks'] ?? null;
$candidateChecks = $policy['release_candidate_required_checks'] ?? null;

if (!is_array($requiredChecks) || $requiredChecks === []) {
    failContract('required_checks должен быть непустым массивом');
}
if (count($requiredChecks) !== count(array_unique($requiredChecks))) {
    failContract('required_checks содержит дубликаты');
}
if ($stabilizationChecks !== $requiredChecks) {
    failContract('ветки 1.0 и master должны требовать один и тот же финальный набор checks');
}
if ($candidateChecks !== $requiredChecks) {
    failContract('release_candidate_required_checks должен совпадать с обязательным набором');
}

$workflowByCheck = [
    'release-gate' => ['.github/workflows/release-gate.yml', 'release-gate'],
    'notes-browser-lifecycle' => ['.github/workflows/notes-browser-lifecycle.yml', 'notes-browser-lifecycle'],
    'tasks-browser-lifecycle' => ['.github/workflows/tasks-browser-lifecycle.yml', 'tasks-browser-lifecycle'],
    'file-manager-browser-lifecycle' => ['.github/workflows/file-manager-browser-lifecycle.yml', 'file-manager-browser-lifecycle'],
    'profile-browser-lifecycle' => ['.github/workflows/profile-browser-lifecycle.yml', 'profile-browser-lifecycle'],
    'admin-browser-lifecycle' => ['.github/workflows/admin-browser-lifecycle.yml', 'admin-browser-lifecycle'],
    'storage-db-failure' => ['.github/workflows/fault-injection-browser.yml', 'storage-db-failure'],
    'browser-wss-e2e' => ['.github/workflows/browser-wss-e2e.yml', 'browser-wss-e2e'],
    'release-evidence' => ['.github/workflows/release-evidence.yml', 'release-evidence'],
    'one-zero-one-upgrade-rollback' => ['.github/workflows/1.0.1-to-1.0.2-upgrade-drill.yml', 'one-zero-one-upgrade-rollback'],
    'windows-contract (8.1)' => ['.github/workflows/windows-hosting-compat.yml', 'windows-contract'],
    'windows-contract (8.3)' => ['.github/workflows/windows-hosting-compat.yml', 'windows-contract'],
    'websocket-deployment-contract (8.1)' => ['.github/workflows/websocket-deployment-contract.yml', 'websocket-deployment-contract'],
    'websocket-deployment-contract (8.3)' => ['.github/workflows/websocket-deployment-contract.yml', 'websocket-deployment-contract'],
    'messenger-realtime-fallback' => ['.github/workflows/messenger-realtime-fallback.yml', 'messenger-realtime-fallback'],
    'two-factor-contract (8.1)' => ['.github/workflows/two-factor-auth.yml', 'two-factor-contract'],
    'two-factor-contract (8.3)' => ['.github/workflows/two-factor-auth.yml', 'two-factor-contract'],
    'online-update-access (8.1)' => ['.github/workflows/online-update-access.yml', 'online-update-access'],
    'online-update-access (8.3)' => ['.github/workflows/online-update-access.yml', 'online-update-access'],
    'admin-update-ui (8.1)' => ['.github/workflows/admin-update-ui.yml', 'admin-update-ui'],
    'admin-update-ui (8.3)' => ['.github/workflows/admin-update-ui.yml', 'admin-update-ui'],
];

$checkedWorkflows = [];
foreach ($requiredChecks as $check) {
    if (!is_string($check) || !isset($workflowByCheck[$check])) {
        failContract('неизвестный обязательный check: ' . var_export($check, true));
    }

    [$workflowPath, $jobId] = $workflowByCheck[$check];
    $workflowText = requireFileText($root . '/' . $workflowPath);

    if (preg_match('/^\s{2}' . preg_quote($jobId, '/') . ':\s*$/m', $workflowText) !== 1) {
        failContract("job {$jobId} не найден в {$workflowPath}");
    }

    if (isset($checkedWorkflows[$workflowPath])) {
        continue;
    }

    $pullRequestBody = pullRequestTriggerBody($workflowText);
    if (!str_contains($pullRequestBody, 'master') || !str_contains($pullRequestBody, "'1.0'")) {
        failContract("обязательный workflow не запускается для PR в master и 1.0: {$workflowPath}");
    }
    if (str_contains($pullRequestBody, 'paths:') || str_contains($pullRequestBody, 'paths-ignore:')) {
        failContract("обязательный workflow имеет path filter и может не запуститься: {$workflowPath}");
    }

    $checkedWorkflows[$workflowPath] = true;
}

$upgradeWorkflow = requireFileText($root . '/.github/workflows/1.0.1-to-1.0.2-upgrade-drill.yml');
if (!str_contains($upgradeWorkflow, 'workflow_dispatch:')) {
    failContract('exact 1.0.1 -> 1.0.2 drill должен поддерживать ручной запуск');
}

$futureChecks = $policy['required_checks_after_product_e2e_merge'] ?? null;
if ($futureChecks !== []) {
    failContract('все Product E2E checks уже должны находиться в required_checks');
}

$template = requireFileText($templatePath);
foreach ([
    'Master release gate',
    'BASE_PATH=/workspace/',
    'DB_ARCHITECTURE.md',
    'independent approval',
    'durable DB/storage',
] as $marker) {
    if (!str_contains($template, $marker)) {
        failContract("pull request template не содержит marker: {$marker}");
    }
}

$releaseGate = requireFileText($releaseGatePath);
if (!str_contains($releaseGate, 'php tests/integration/release_governance_contract.php')) {
    failContract('release-gate.yml не запускает release_governance_contract.php');
}
if (preg_match("/pull_request:\\s*\\n\\s*branches:\\s*\\[master,\\s*'1\\.0'\\]/m", $releaseGate) !== 1) {
    failContract('release-gate.yml должен запускаться на PR в master и 1.0');
}

$docs = requireFileText($root . '/docs/RELEASE_GOVERNANCE.md');
if (preg_match('/Настройки branch\\s+protection живут вне истории Git/u', $docs) !== 1) {
    failContract('документация должна явно описывать внешнюю границу GitHub Settings');
}
if (!str_contains($docs, 'Защита ветки 1.0')) {
    failContract('документация должна определять защиту ветки 1.0');
}

fwrite(STDOUT, "Контракт release governance: OK\n");
