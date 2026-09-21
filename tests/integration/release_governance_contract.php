<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$policyPath = $root . '/.github/release-governance.json';
$templatePath = $root . '/.github/pull_request_template.md';
$releaseGatePath = $root . '/.github/workflows/release-gate.yml';

function failContract(string $message): never
{
    fwrite(STDERR, "Release governance contract failed: {$message}\n");
    exit(1);
}

function requireFileText(string $path): string
{
    if (!is_file($path)) {
        failContract('missing file: ' . $path);
    }
    $content = file_get_contents($path);
    if ($content === false) {
        failContract('cannot read file: ' . $path);
    }
    return $content;
}

$policy = json_decode(requireFileText($policyPath), true);
if (!is_array($policy) || json_last_error() !== JSON_ERROR_NONE) {
    failContract('invalid JSON policy');
}

if (($policy['protected_branch'] ?? null) !== 'master') {
    failContract('protected_branch must be master');
}
if (($policy['stabilization_branch'] ?? null) !== '1.0') {
    failContract('stabilization_branch must be 1.0');
}

foreach ([
    'require_pull_request',
    'require_branch_up_to_date',
    'dismiss_stale_reviews',
    'block_force_pushes',
    'block_branch_deletion',
] as $flag) {
    if (($policy[$flag] ?? false) !== true) {
        failContract("{$flag} must be true");
    }
}

if ((int) ($policy['required_approvals_when_independent_reviewer_exists'] ?? 0) !== 1) {
    failContract('independent-review approval count must be 1');
}

$requiredChecks = $policy['required_checks'] ?? null;
if (!is_array($requiredChecks) || $requiredChecks === []) {
    failContract('required_checks must be a non-empty array');
}
if (count($requiredChecks) !== count(array_unique($requiredChecks))) {
    failContract('required_checks contains duplicates');
}

$stabilizationChecks = $policy['stabilization_required_checks'] ?? null;
if ($stabilizationChecks !== ['release-gate']) {
    failContract('1.0 stabilization branch must require exactly the always-on release-gate');
}

$workflowByCheck = [
    'release-gate' => '.github/workflows/release-gate.yml',
    'notes-browser-lifecycle' => '.github/workflows/notes-browser-lifecycle.yml',
    'tasks-browser-lifecycle' => '.github/workflows/tasks-browser-lifecycle.yml',
    'file-manager-browser-lifecycle' => '.github/workflows/file-manager-browser-lifecycle.yml',
    'profile-browser-lifecycle' => '.github/workflows/profile-browser-lifecycle.yml',
    'admin-browser-lifecycle' => '.github/workflows/admin-browser-lifecycle.yml',
    'storage-db-failure' => '.github/workflows/fault-injection-browser.yml',
];

foreach ($requiredChecks as $check) {
    if (!is_string($check) || !isset($workflowByCheck[$check])) {
        failContract('unknown current required check: ' . var_export($check, true));
    }
    $workflowText = requireFileText($root . '/' . $workflowByCheck[$check]);
    if (preg_match('/^\s{2}' . preg_quote($check, '/') . ':\s*$/m', $workflowText) !== 1) {
        failContract("workflow job id {$check} not found in {$workflowByCheck[$check]}");
    }

    if (preg_match('/pull_request:\s*\n(?<body>(?:\s{4}.*\n)*)/m', $workflowText, $match) !== 1) {
        failContract("required workflow lacks pull_request trigger: {$workflowByCheck[$check]}");
    }
    $pullRequestBody = (string) ($match['body'] ?? '');
    if (!str_contains($pullRequestBody, 'master')) {
        failContract("required workflow does not run for master PRs: {$workflowByCheck[$check]}");
    }
    if (str_contains($pullRequestBody, 'paths:') || str_contains($pullRequestBody, 'paths-ignore:')) {
        failContract("required workflow is path-filtered and can leave a required check pending: {$workflowByCheck[$check]}");
    }
}

$futureChecks = $policy['required_checks_after_product_e2e_merge'] ?? null;
if ($futureChecks !== []) {
    failContract('all Product E2E checks are now promoted into required_checks');
}

$template = requireFileText($templatePath);
foreach ([
    'Master release gate',
    'BASE_PATH=/workspace/',
    'DB_ARCHITECTURE.md',
    'независимый approval',
    'durable DB/storage',
] as $marker) {
    if (!str_contains($template, $marker)) {
        failContract("pull request template missing marker: {$marker}");
    }
}

$releaseGate = requireFileText($releaseGatePath);
if (!str_contains($releaseGate, 'php tests/integration/release_governance_contract.php')) {
    failContract('release-gate.yml must execute release_governance_contract.php');
}
if (preg_match("/pull_request:\\s*\\n\\s*branches:\\s*\\[master,\\s*'1\\.0'\\]/m", $releaseGate) !== 1) {
    failContract('release-gate.yml must run on pull requests to master and 1.0');
}

$docs = requireFileText($root . '/docs/RELEASE_GOVERNANCE.md');
if (!str_contains($docs, 'настройки GitHub branch protection находятся вне Git history')) {
    failContract('governance documentation must state the external Settings boundary');
}
if (!str_contains($docs, 'Обязательная защита `1.0`')) {
    failContract('governance documentation must define 1.0 protection');
}

fwrite(STDOUT, "Release governance contract: OK\n");
