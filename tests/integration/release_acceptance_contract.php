<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function releaseAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[ОШИБКА] контракт release acceptance: {$message}\n");
        exit(1);
    }
}

function releaseAcceptanceText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    releaseAcceptanceAssert(is_file($full), "отсутствует файл {$path}");
    $text = file_get_contents($full);
    releaseAcceptanceAssert(is_string($text), "не удалось прочитать {$path}");
    return $text;
}

function releaseAcceptanceDisabledFunctions(string $workflow): array
{
    if (preg_match('/disable_functions=([^"\\s]+)/', $workflow, $matches) !== 1) {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
}

$readme = releaseAcceptanceText($root, 'README.md');
releaseAcceptanceAssert(
    !str_contains($readme, 'Пока остаётся'),
    'README всё ещё содержит устаревшее описание CSP debt'
);
releaseAcceptanceAssert(
    str_contains($readme, '## 1.0 release readiness'),
    'README не содержит актуальный раздел release readiness'
);
foreach ([
    'nonce-based CSP без',
    'explicit retention/permanent-purge contract',
    'structured security observability',
    'GitHub branch protection/ruleset',
    'production license/update Ed25519 keypairs',
    'кросс-браузерная и мобильная проверка',
] as $marker) {
    releaseAcceptanceAssert(str_contains($readme, $marker), "README release readiness не содержит marker {$marker}");
}

$doc = releaseAcceptanceText($root, 'docs/RELEASE_ACCEPTANCE.md');
foreach ([
    'Gate A — контракт репозитория и исходников',
    'Gate B — управление репозиторием',
    'Gate C — production trust roots',
    'Gate D — автоматические доказательства exact-head',
    'Gate E — эксплуатационная приёмка',
    'Gate F — дефекты и ручная приёмка',
    'visual acceptance',
    'OSPanel 5.2.2',
    'Gate G — неизменяемый артефакт и подпись',
    'Gate H — финальный merge и tag',
    'v1.0.15',
    'нет открытых P0/P1 дефектов с риском потери данных',
    'нет открытых P0/P1 дефектов безопасности',
    'ручной pre-tag проверки',
    'tag-driven `Build hosting package`',
    'публикации GitHub Release',
] as $marker) {
    releaseAcceptanceAssert(str_contains($doc, $marker), "runbook release acceptance не содержит marker {$marker}");
}

$previousStableUpgrade = releaseAcceptanceText($root, '.github/workflows/1.0.14-to-1.0.15-upgrade.yml');
$disabledFunctions = releaseAcceptanceDisabledFunctions($previousStableUpgrade);
$requiredDisabledFunctions = [
    'proc_open',
    'proc_get_status',
    'proc_terminate',
    'proc_close',
    'exec',
    'system',
    'passthru',
    'shell_exec',
    'popen',
];
releaseAcceptanceAssert(
    str_contains($previousStableUpgrade, 'name: 1.0.14 → 1.0.15 обновление без переустановки')
    && str_contains($previousStableUpgrade, 'git archive --format=tar v1.0.14')
    && str_contains($previousStableUpgrade, '--min-source-version-code=10014')
    && str_contains($previousStableUpgrade, 'node tests/e2e/admin-update-apply.mjs')
    && str_contains($previousStableUpgrade, 'admin_update_preservation_probe.php --verify')
    && array_diff($requiredDisabledFunctions, $disabledFunctions) === [],
    'exact upgrade 1.0.14 → 1.0.15 не закреплён как web-only проверка с сохранением данных'
);

$windowsAcceptance = releaseAcceptanceText($root, 'docs/WINDOWS_OSPANEL_ACCEPTANCE_1.0.15.md');
releaseAcceptanceAssert(
    str_contains($windowsAcceptance, '# Релизная приёмка Windows / OSPanel для 1.0.15')
    && str_contains($windowsAcceptance, 'существующая установка 1.0.14 должна обновляться до 1.0.15 без переустановки с нуля')
    && str_contains($windowsAcceptance, '1.0.14 → 1.0.15')
    && str_contains($windowsAcceptance, 'повторный `bootstrap-1.0.12-updater.php` запрещён как ненужный')
    && str_contains($windowsAcceptance, 'rollback_failed'),
    'Windows/OSPanel acceptance не закрепляет прямой переход 1.0.14 → 1.0.15 и recovery-контракт'
);

$preflight = releaseAcceptanceText($root, 'bin/release_acceptance.php');
foreach ([
    "'strict'",
    "'branch-protection-confirmed'",
    "'ci-green'",
    "'release-evidence-green'",
    "'backup-restore-current'",
    "'operational-acceptance-green'",
    "'visual-acceptance-green'",
    "'ospanel-acceptance-green'",
    "'update-bootstrap-acceptance-green'",
    "'one-click-update-acceptance-green'",
    "'two-factor-acceptance-green'",
    "'one-zero-one-drill-green'",
    "'p0p1-clear'",
    "'trust-canaries-green'",
    "'artifact-signed'",
    'production_public_trust_roots',
    'release_evidence_harness',
    'private_signing_material_absent',
    "Version::VERSION === '1.0.15'",
    "Version::STATUS === 'stable'",
    'exit(3)',
] as $marker) {
    releaseAcceptanceAssert(str_contains($preflight, $marker), "release preflight не содержит marker {$marker}");
}

$roadmap = releaseAcceptanceText($root, 'docs/ROADMAP.md');
releaseAcceptanceAssert(
    str_contains($roadmap, 'все шесть встроенных production-модулей')
    && !str_contains($roadmap, 'Оставшиеся P0 blockers перед 1.0.0')
    && !str_contains($roadmap, 'Profile — PR #146, Draft'),
    'ROADMAP снова содержит устаревшее состояние миграции 1.0'
);

$isolationDoc = releaseAcceptanceText($root, 'docs/MODULE_RUNTIME_ISOLATION_1.0.md');
releaseAcceptanceAssert(
    str_contains($isolationDoc, 'Изолированы все шесть встроенных модулей')
    && str_contains($isolationDoc, 'Этот список теперь **исторический**')
    && !str_contains($isolationDoc, 'core.php still contains a recursive'),
    'документ изоляции модулей не соответствует текущему runtime'
);

$supportDiagnostics = releaseAcceptanceText($root, 'docs/SUPPORT_DIAGNOSTICS.md');
releaseAcceptanceAssert(
    str_contains($supportDiagnostics, 'hosting-profile.json')
    && str_contains($supportDiagnostics, 'Authorization: Bearer')
    && str_contains($supportDiagnostics, 'X-Notes-Diagnostics-SHA256')
    && str_contains($supportDiagnostics, 'CREATE ROUTINE')
    && str_contains($supportDiagnostics, 'ALTER ROUTINE'),
    'документация диагностики не закрепляет ZIP, Bearer-доступ и профиль ограниченного хостинга'
);

$releaseNotes = releaseAcceptanceText($root, 'docs/releases/v1.0.15.md');
foreach ([
    '## Назначение релиза',
    '## Messenger и переключение вкладок',
    '## Файловый менеджер без ручного F5',
    '## Регистрация и приглашения',
    '## Мобильные действия Messenger',
    '## Проверяемый путь обновления',
    '## Совместимость и восстановление',
] as $marker) {
    releaseAcceptanceAssert(
        str_contains($releaseNotes, $marker),
        "описание релиза 1.0.15 не содержит русский раздел {$marker}"
    );
}

releaseAcceptanceAssert(
    str_contains($releaseNotes, 'exact v1.0.14')
    && str_contains($releaseNotes, '1.0.15 (10015)')
    && str_contains($releaseNotes, 'не требуется')
    && str_contains($releaseNotes, 'bootstrap-1.0.12-updater.php')
    && str_contains($releaseNotes, 'Переустановка с нуля не считается проверкой upgrade-path')
    && str_contains($releaseNotes, 'private storage'),
    'описание 1.0.15 не фиксирует прямой upgrade-path и сохранность данных'
);


$protectionScript = releaseAcceptanceText($root, 'tools/release/apply-github-protection.sh');
releaseAcceptanceAssert(
    str_contains($protectionScript, 'Проверка branch protection не пройдена:')
    && str_contains($protectionScript, 'Проверка фактических настроек:')
    && !str_contains($protectionScript, 'Protection verification failed:'),
    'скрипт branch protection снова содержит английскую операторскую обратную связь'
);

$rotationCli = releaseAcceptanceText($root, 'bin/rotate_data_keys.php');
releaseAcceptanceAssert(
    str_contains($preflight, 'Команда доступна только из CLI.')
    && str_contains($preflight, 'Использование: php bin/release_acceptance.php')
    && !str_contains($preflight, 'Non-strict mode reports source failures'),
    'release acceptance CLI снова содержит английскую операторскую справку'
);
releaseAcceptanceAssert(
    str_contains($rotationCli, 'Ротация ключей данных:')
    && str_contains($rotationCli, 'Сырые значения ключей намеренно не принимаются')
    && !str_contains($rotationCli, 'Data-key rotation:'),
    'CLI ротации ключей снова содержит английскую операторскую обратную связь'
);

$governance = json_decode(
    releaseAcceptanceText($root, '.github/release-governance.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
releaseAcceptanceAssert(($governance['stabilization_branch'] ?? null) === '1.0', 'ветка стабилизации должна быть 1.0');
$requiredChecks = $governance['required_checks'] ?? null;
releaseAcceptanceAssert(is_array($requiredChecks) && $requiredChecks !== [], 'финальный набор required checks отсутствует');
releaseAcceptanceAssert(
    ($governance['stabilization_required_checks'] ?? null) === $requiredChecks,
    '1.0 должна требовать тот же набор checks, что и master'
);
releaseAcceptanceAssert(
    ($governance['release_candidate_required_checks'] ?? null) === $requiredChecks,
    'release candidate check set расходится с branch protection policy'
);
foreach ([
    'one-zero-one-upgrade-rollback',
    'browser-wss-e2e',
    'release-evidence',
    'windows-contract (8.1)',
    'windows-contract (8.3)',
    'messenger-realtime-fallback',
    'two-factor-contract (8.1)',
    'two-factor-contract (8.3)',
    'online-update-access (8.1)',
    'online-update-access (8.3)',
    'admin-update-ui (8.1)',
    'admin-update-ui (8.3)',
    'admin-update-e2e',
    'previous-stable-upgrade',
] as $requiredCheck) {
    releaseAcceptanceAssert(
        in_array($requiredCheck, $requiredChecks, true),
        "в обязательном наборе отсутствует {$requiredCheck}"
    );
}

$twoFactorDoc = releaseAcceptanceText($root, 'docs/TWO_FACTOR_AUTH.md');
releaseAcceptanceAssert(
    str_contains($twoFactorDoc, 'Обязательно для всех пользователей')
    && str_contains($twoFactorDoc, 'Персональный режим')
    && str_contains($twoFactorDoc, 'Ротация UNIQUE_KEY'),
    'документация 2FA не фиксирует системную/персональную политику и ротацию ключа'
);

$autoPublish = releaseAcceptanceText($root, '.github/workflows/prerelease-autotag.yml');
releaseAcceptanceAssert(
    str_contains($autoPublish, 'workflows: ["Stable release gate"]')
    && str_contains($autoPublish, 'github.event.workflow_run.event == \'push\'')
    && str_contains($autoPublish, "github.event.workflow_run.head_branch == 'master'")
    && str_contains($autoPublish, 'gh workflow run hosting-package.yml --ref "$TAG" -f version="$TAG"'),
    'автопубликация релиза не привязана к успешному push-gate master'
);

$releaseGate = releaseAcceptanceText($root, '.github/workflows/release-gate.yml');
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php tests/integration/release_acceptance_contract.php'),
    'Stable release gate не запускает release acceptance contract'
);
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php bin/release_acceptance.php --json'),
    'Stable release gate не запускает non-strict release preflight'
);
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php tests/integration/two_factor_contract.php'),
    'Stable release gate не запускает контракт двухфакторной аутентификации'
);

fwrite(STDOUT, "[OK] финальный контракт release acceptance 1.0.15 выполнен\n");
