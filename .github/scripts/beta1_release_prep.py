from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    if old not in text:
        raise SystemExit(f"marker missing in {path}: {old[:120]!r}")
    p.write_text(text.replace(old, new, 1))


replace_once('core/Version.php', "public const VERSION = '0.13.0-alpha';", "public const VERSION = '0.14.0-beta.1';")
replace_once('core/Version.php', "public const STATUS = 'alpha';", "public const STATUS = 'beta';")
replace_once('core/Version.php', 'public const VERSION_CODE = 1300;', 'public const VERSION_CODE = 1401;')
replace_once('core/Version.php', "public const RELEASE_DATE = '2026-09-14';", "public const RELEASE_DATE = '2026-09-15';")
replace_once(
    'core/Version.php',
    "    public static function isAlpha(): bool\n    {\n        return self::STATUS === 'alpha';\n    }\n",
    "    public static function isAlpha(): bool\n    {\n        return self::STATUS === 'alpha';\n    }\n\n    public static function isBeta(): bool\n    {\n        return self::STATUS === 'beta';\n    }\n",
)

replace_once('README.md', '**Версия:** `0.13.0-alpha`', '**Версия:** `0.14.0-beta.1`')
replace_once('README.md', '**Актуально на:** 14 сентября 2026', '**Актуально на:** 15 сентября 2026')
replace_once('README.md', '**Статус:** product-complete alpha / beta candidate baseline', '**Статус:** beta.1 / modular security-hardening baseline; следующая основная цель — `1.0.0` stable')
replace_once(
    'README.md',
    'К 0.13 базовые security-, schema-contract, data-integrity, installer, browser/WSS и production-operations риски уже закрыты 0.12 usable baseline, а 0.13 завершает основной alpha-цикл продуктового UX: Tasks получил kanban, Notes — writing-first editor и first-class voice notes, Profile — workspace metrics и explicit publication model, File Manager — grid/list/search/sort/drag-drop workspace, Messenger — понятный reconnect/offline lifecycle. После 0.13 крупные новые функции замораживаются до beta-hardening.',
    'Версия `0.14.0-beta.1` переводит проект из alpha в beta: поверх product-complete 0.13 зафиксированы hardening Router/session/redirect boundary, формальные module manifests и registry, persisted module lifecycle, compatibility/dependency contracts и полный regression baseline, включая HTTPS/WSS. Beta.1 — это первая стабилизационная точка, а не финальная 1.0: дальнейшая работа в `master` направлена на завершение изоляции модулей, signed updates/recovery, licensing, observability и остальные stable blockers.',
)
replace_once('README.md', 'импортирует 7 canonical schemas и создаёт current contract из 26 обязательных таблиц;', 'импортирует 8 canonical schemas и создаёт current contract из 27 обязательных таблиц;')
replace_once('README.md', 'database/settings_schema.sql\n```', 'database/settings_schema.sql\ndatabase/module_lifecycle_schema.sql\n```')
replace_once('README.md', 'Fresh contract включает 26 обязательных таблиц.', 'Fresh contract включает 27 обязательных таблиц, включая persisted `module_lifecycle` registry.')
replace_once('README.md', 'проверяет subdirectory detection, private storage вне document root, 26-table contract, quota seed, admin account, generated `.env`', 'проверяет subdirectory detection, private storage вне document root, 27-table contract, quota seed, admin account, generated `.env`')
replace_once('README.md', '`0.13 alpha readiness` проверяет наличие 0.12 durable baseline, ключевых 0.13 UX артефактов, закрытый release scope и синхронизацию Version/README/CHANGELOG.', '`0.14 beta readiness` проверяет beta identity, module/security lifecycle artifacts, release publishing contract и синхронизацию Version/README/CHANGELOG.')

readme = Path('README.md')
text = readme.read_text()
marker = '## После 0.13: путь к beta / stable\n'
if marker not in text:
    raise SystemExit('README stable-path marker missing')
head = text.split(marker, 1)[0]
readme.write_text(head + '''## 0.14 beta и путь к `1.0.0` stable

`0.14.0-beta.1` — первая официальная beta-точка. Beta patch releases при необходимости публикуются как `v0.14.0-beta.N`; они не открывают новый feature cycle. Основная ветка после beta.1 развивается в сторону `1.0.0` stable.

Перед `1.0.0` должны быть закрыты оставшиеся platform/stability blockers:

- module-owned bootstrap/routes/assets/socket registration и фактическая изоляция отключённых модулей;
- deterministic package compositions и dependency preflight для разных наборов модулей;
- signed core/module update metadata, staged transactional update, rollback/recovery и health verification;
- централизованный licensing/entitlement contract с безопасным offline/expiry behavior без удаления пользовательских данных;
- structured observability, security/audit events, metrics/alerts, load/soak и cross-browser/mobile regression evidence;
- transactional/resumable re-encryption procedure для безопасной ротации `UNIQUE_KEY` / `MSG_SECRET_KEY`;
- постепенный вынос inline Smarty JS/CSS для CSP без `unsafe-inline`;
- явный retention/permanent-purge contract и подтверждённый beta-период без P0/P1 data-loss/security дефектов;
- scalable encrypted-search architecture только если beta load tests покажут, что bounded decrypt scan не соответствует заявленному масштабу.

Подробный hardening roadmap: [`docs/BETA_HARDENING_0.14.md`](docs/BETA_HARDENING_0.14.md).
''')

changelog = Path('CHANGELOG.md')
text = changelog.read_text()
unreleased = text.index('## Unreleased')
historical = text.index('## 0.13.0-alpha — 2026-09-14')
new_head = '''## Unreleased

Основная цель после первого beta — `1.0.0` stable. Крупный пользовательский feature scope остаётся заморожен; приоритеты: полная runtime-изоляция модулей, package compositions, signed updater/recovery, licensing, observability, upgrade/load/soak/cross-browser evidence, key re-encryption и CSP hardening.

## 0.14.0-beta.1 — 2026-09-15

### Beta transition
- Проект официально переведён из product-complete alpha в первый beta hardening baseline.
- Release identity синхронизирован между `Core\\Version`, README, CHANGELOG и отдельным beta-readiness contract.
- Теги prerelease вида `v*-*` публикуются как GitHub **prerelease**, а stable tags — как обычные Releases.

### Core / security hardening
- Усилен Router/session/redirect boundary: strict session cookie policy, local-only redirect policy, typed route parameters и fail-closed malformed request handling.
- Bootstrap/security failures не должны раскрывать внутренние stack/path details браузеру; security contracts закреплены CI.
- Сохранены и повторно пройдены security baseline, production healthcheck, installer/upgrade, crypto migration и fault-injection gates.

### Modular platform
- Добавлены строгие data-only module manifests и `ModuleRegistry` с path/symlink confinement, compatibility/dependency/capability validation и deterministic load order.
- Notes, Tasks, Files, Messenger, Profile и Administration зарегистрированы как формальные модули; legacy runtime остаётся временным migration boundary.
- Добавлен persisted `module_lifecycle` registry с configured/effective state, состояниями `discovered/installed/enabled/disabled/incompatible/degraded/quarantined/uninstalled`, dependency-aware transitions и non-destructive disable/uninstall semantics.
- Fresh install и compatibility upgrade включают canonical `module_lifecycle` schema; current fresh contract содержит 27 таблиц.
- Для Workerman lifecycle reconciliation выполняется post-fork, поэтому worker не наследует pre-fork PDO connection; HTTPS/WSS Chromium regression подтверждает reconnect и realtime delivery.

### Beta boundary / next target
- Beta.1 не объявляет завершёнными module-owned runtime isolation, signed updater/recovery или licensing: эти P0/P1 platform items остаются в `Unreleased` на пути к `1.0.0`.
- Полный план остаётся в `docs/BETA_HARDENING_0.14.md`; крупные новые пользовательские функции до stable не добавляются.

'''
changelog.write_text(text[:unreleased] + new_head + text[historical:])

replace_once(
    'docs/BETA_HARDENING_0.14.md',
    '# Workspace Organizer 0.14 — Beta Hardening / Modular Platform\n',
    '# Workspace Organizer 0.14 — Beta Hardening / Modular Platform\n\n> Release channel: **beta**. `0.14.0-beta.1` фиксирует первый hardening baseline; незакрытые P0/P1 пункты этого документа продолжаются в `master` как blockers на пути к `1.0.0` stable.\n',
)

Path('docs/releases').mkdir(parents=True, exist_ok=True)
Path('docs/releases/v0.14.0-beta.1.md').write_text('''# Workspace Organizer 0.14.0-beta.1

Первый официальный beta hardening release после product-complete 0.13 alpha.

## Что фиксирует beta.1

- hardened Router/session/redirect boundary и security regression contracts;
- formal module manifests + registry с compatibility/dependency/capability validation;
- persisted module lifecycle с configured/effective state и безопасными dependency-aware transitions;
- canonical lifecycle schema/upgrade path и 27-table fresh-install contract;
- полный HTTP/browser/installer/security regression baseline, включая authenticated HTTPS/WSS reconnect/realtime smoke;
- корректный Workerman post-fork lifecycle reconciliation без наследования PDO connection.

## Важная граница beta

Это **beta**, не `1.0.0`: legacy `app/*`/central route registration ещё мигрируют за module-owned runtime boundaries. Signed updater/recovery, licensing, observability/load/soak evidence, transactional key re-encryption и CSP cleanup остаются blockers следующего этапа.

После публикации этой версии основная разработка идёт к **`1.0.0` stable**. При необходимости исправления beta публикуются как `v0.14.0-beta.N` без открытия нового feature cycle.
''')

Path('tests/integration/beta_readiness_014.php').write_text(r'''<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$require = static function (string $relative, string $reason) use ($root, &$errors): void {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = "missing {$relative} — {$reason}";
    }
};

foreach ([
    'core/SessionSecurity.php' => 'session hardening is missing',
    'core/RedirectPolicy.php' => 'redirect policy is missing',
    'core/ModuleManifest.php' => 'module manifest contract is missing',
    'core/ModuleRegistry.php' => 'module registry is missing',
    'core/ModuleLifecycleStore.php' => 'persisted module lifecycle is missing',
    'database/module_lifecycle_schema.sql' => 'canonical module lifecycle schema is missing',
    'database/migrations/20260915_module_lifecycle.sql' => 'module lifecycle compatibility migration is missing',
    'docs/CORE_SECURITY_AUDIT_0.14.md' => 'core security audit is missing',
    'docs/MODULE_PLATFORM_0.14.md' => 'module platform contract is missing',
    'docs/BETA_HARDENING_0.14.md' => 'beta hardening roadmap is missing',
    'docs/releases/v0.14.0-beta.1.md' => 'curated beta release notes are missing',
    '.github/workflows/core-security-phase2.yml' => 'core security regression gate is missing',
    '.github/workflows/module-platform-contract.yml' => 'module platform regression gate is missing',
    '.github/workflows/module-lifecycle-contract.yml' => 'module lifecycle regression gate is missing',
    '.github/workflows/rbac-foundation.yml' => 'RBAC foundation gate is missing',
    '.github/workflows/rbac-enforcement.yml' => 'RBAC enforcement gate is missing',
    '.github/workflows/browser-wss-e2e.yml' => 'HTTPS/WSS browser gate is missing',
] as $file => $reason) {
    $require($file, $reason);
}

$versionPath = $root . '/core/Version.php';
$version = is_file($versionPath) ? (string) file_get_contents($versionPath) : '';
foreach ([
    "public const VERSION = '0.14.0-beta.1';",
    "public const STATUS = 'beta';",
    'public const VERSION_CODE = 1401;',
    "public const RELEASE_DATE = '2026-09-15';",
] as $marker) {
    if (!str_contains($version, $marker)) {
        $errors[] = "version marker missing: {$marker}";
    }
}

$readme = is_file($root . '/README.md') ? (string) file_get_contents($root . '/README.md') : '';
foreach (['**Версия:** `0.14.0-beta.1`', '`1.0.0` stable', '27 обязательных таблиц'] as $marker) {
    if (!str_contains($readme, $marker)) {
        $errors[] = "README beta marker missing: {$marker}";
    }
}

$changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
if (!str_contains($changelog, '## 0.14.0-beta.1 — 2026-09-15')) {
    $errors[] = 'CHANGELOG beta release section is missing';
}
if (!str_contains($changelog, 'Основная цель после первого beta — `1.0.0` stable')) {
    $errors[] = 'CHANGELOG must point Unreleased at 1.0.0 stable';
}

$packageWorkflow = is_file($root . '/.github/workflows/hosting-package.yml')
    ? (string) file_get_contents($root . '/.github/workflows/hosting-package.yml')
    : '';
foreach (['--prerelease', 'EXPECTED_TAG', 'docs/releases/${GITHUB_REF_NAME}.md'] as $marker) {
    if (!str_contains($packageWorkflow, $marker)) {
        $errors[] = "hosting release contract missing: {$marker}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Workspace 0.14 beta readiness: BLOCKED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Workspace 0.14 beta readiness: OK\n");
''')

Path('.github/workflows/beta-readiness-014.yml').write_text('''name: 0.14 beta readiness

on:
  push:
    branches: [0.14-beta1-release-prep]
  pull_request:
    branches: [master]

permissions:
  contents: read

jobs:
  beta-readiness:
    runs-on: ubuntu-latest
    timeout-minutes: 5
    steps:
      - name: Checkout
        uses: actions/checkout@v4
      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - name: Lint beta readiness contract
        run: php -l tests/integration/beta_readiness_014.php
      - name: Verify Workspace 0.14 beta.1 baseline
        run: php tests/integration/beta_readiness_014.php
''')

Path('.github/workflows/usable-alpha-readiness.yml').unlink(missing_ok=True)

p = Path('.github/workflows/hosting-package.yml')
text = p.read_text()
old = '''      - name: Publish tagged release asset
        if: startsWith(github.ref, 'refs/tags/v')
        env:
          GH_TOKEN: ${{ github.token }}
        shell: bash
        run: |
          set -euo pipefail
          if gh release view "$GITHUB_REF_NAME" >/dev/null 2>&1; then
            gh release upload "$GITHUB_REF_NAME" "$BUNDLE" --clobber
          else
            gh release create "$GITHUB_REF_NAME" "$BUNDLE" \\
              --title "$GITHUB_REF_NAME" \\
              --generate-notes
          fi
'''
new = '''      - name: Verify tag matches application version
        if: startsWith(github.ref, 'refs/tags/v')
        shell: bash
        run: |
          set -euo pipefail
          EXPECTED_TAG="v$(php -r "require 'core/Version.php'; echo Core\\\\Version::VERSION;")"
          test "$GITHUB_REF_NAME" = "$EXPECTED_TAG"
          echo "Release tag matches application version: $EXPECTED_TAG"

      - name: Publish tagged release asset
        if: startsWith(github.ref, 'refs/tags/v')
        env:
          GH_TOKEN: ${{ github.token }}
        shell: bash
        run: |
          set -euo pipefail
          RELEASE_FLAGS=()
          if [[ "$GITHUB_REF_NAME" == *-* ]]; then
            RELEASE_FLAGS+=(--prerelease)
          fi

          NOTES_FLAGS=(--generate-notes)
          NOTES_FILE="docs/releases/${GITHUB_REF_NAME}.md"
          if [[ -f "$NOTES_FILE" ]]; then
            NOTES_FLAGS=(--notes-file "$NOTES_FILE")
          fi

          if gh release view "$GITHUB_REF_NAME" >/dev/null 2>&1; then
            gh release upload "$GITHUB_REF_NAME" "$BUNDLE" --clobber
          else
            gh release create "$GITHUB_REF_NAME" "$BUNDLE" \\
              --title "$GITHUB_REF_NAME" \\
              "${RELEASE_FLAGS[@]}" \\
              "${NOTES_FLAGS[@]}"
          fi
'''
if old not in text:
    raise SystemExit('hosting-package publish block marker missing')
p.write_text(text.replace(old, new, 1))

Path('.github/workflows/_beta1-release-prep-once.yml').unlink(missing_ok=True)
Path('.github/scripts/beta1_release_prep.py').unlink(missing_ok=True)
