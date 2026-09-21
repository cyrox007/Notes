# Управление релизами

Workspace Organizer использует `master` как release branch, а `1.0` — как стабильную ветку release candidate. Код репозитория определяет и проверяет ожидаемую policy, но **настройки GitHub branch protection находятся вне Git history** и должны применяться в Settings репозитория пользователем с правом Administration.

## Обязательная защита `1.0`

Ветка стабилизации должна быть защищена перед финальной release ceremony 1.0:

1. Требовать pull request перед merge.
2. Требовать актуальность branch относительно target перед merge.
3. Требовать always-on status check `release-gate`.
4. Сбрасывать устаревшие approvals PR при появлении новых commits.
5. Блокировать force push и удаление ветки.
6. Если доступен другой независимый участник, способный проводить review, требовать одно approving review.

Как repository-required можно настраивать только checks, запускающиеся **на каждом** PR в `1.0`. Workflows с path filters остаются обязательным evidence, когда они запускаются, но делать их repository-required нельзя: иначе несвязанный PR, который корректно их не запускает, окажется заблокирован навсегда.

## Обязательная защита `master`

Целевая policy:

1. Требовать pull request перед merge.
2. Требовать актуальность branch перед merge.
3. Требовать status checks, перечисленные в `.github/release-governance.json`.
4. Сбрасывать устаревшие approvals PR при появлении новых commits.
5. Блокировать force push и удаление ветки.
6. Если в репозитории есть другой независимый участник, способный проводить review, требовать одно approving review. Approval самого автора PR не удовлетворяет требованию независимого review.

Обязательные status checks:

- `release-gate`
- `notes-browser-lifecycle`
- `tasks-browser-lifecycle`
- `file-manager-browser-lifecycle`
- `profile-browser-lifecycle`
- `admin-browser-lifecycle`
- `storage-db-failure`

Эти семь checks намеренно настроены на запуск для каждого PR в `master`; ни один не использует pull-request path filter. Это не позволяет branch protection бесконечно ждать обязательный check, который не запустился. Другие release-relevant workflows могут оставаться path-filtered, но не настраиваются как repository-required contexts.

Проверка policy на уровне исходников не заменяет enforcement на стороне репозитория; применяйте checked-in policy командой owner/admin ниже.

## Правило merge

PR в `1.0` или `master` допускается к релизу только если:

- все release-relevant checks прошли на текущем head;
- branch актуальна относительно target;
- изменения БД соответствуют `docs/DB_ARCHITECTURE.md`;
- пользовательские storage mutations не сообщают об успехе до durable persistence;
- поведение root и `BASE_PATH=/workspace/` не регрессировало;
- изменения, влияющие на browser, либо расширяют существующий lifecycle test, либо объясняют, почему обновление lifecycle не требуется;
- независимый approval присутствует, если существует другой квалифицированный reviewer.

Не используйте administrator bypass для merge красного или устаревшего PR в обычной разработке. После аварийного bypass должен следовать corrective PR и письменная причина в timeline PR.

## Применение защиты на стороне репозитория

В репозитории есть `tools/release/apply-github-protection.sh`, с помощью которого owner/admin применяет checked-in policy через авторизованный GitHub CLI.

Из доверенного checkout текущей ветки `1.0`:

```bash
bash tools/release/apply-github-protection.sh cyrox007/Notes
```

Скрипт:

- читает required check IDs из `.github/release-governance.json`;
- защищает одновременно `1.0` и `master`;
- требует актуальность branches перед merge;
- блокирует force-push и удаление;
- сбрасывает stale reviews;
- применяет policy также к администраторам;
- определяет, существует ли другой direct collaborator с permission write/maintain/admin, и только в этом случае требует один approval;
- выводит итоговое состояние GitHub protection для проверки.

Скрипт меняет только настройки GitHub repository. Он не создаёт, не хранит и не изменяет credentials, кроме использования уже авторизованной session `gh`.

## Почему policy разделена между кодом и Settings

GitHub Actions и repository files не могут безопасно самостоятельно выдавать себе Administration permission. Поэтому репозиторий хранит ожидаемый protection contract в `.github/release-governance.json` и проверяет части, наблюдаемые из source. Enforcement на стороне репозитория остаётся явной owner/admin операцией и отслеживается отдельно от корректности исходников.

## Проверка

`tests/integration/release_governance_contract.php` проверяет:

- policy file валиден и содержит `master` и `1.0`;
- `1.0` требует always-on `release-gate`;
- все required check IDs соответствуют workflow job IDs, присутствующим в репозитории;
- каждый required master check always-on для pull requests и не имеет path filter;
- release gate запускается на pull requests как в `master`, так и в `1.0`;
- pull-request template содержит release/browser/database review prompts;
- release gate сам запускает governance contract.

Это не позволяет policy documentation незаметно разойтись с workflows, которые должны защищать release branch.
