# Статус релизной линии Workspace Organizer 1.0.x

> **Обновлено 23 сентября 2026.** Опубликованный baseline — `v1.0.1`. Активный maintenance-кандидат — `1.0.2` / код версии `10002`. Этот файл — текущий stop/reopen ledger финальной церемонии выпуска 1.0.2; исторические задачи исходников для 1.0.0/1.0.1 остаются закрытыми, пока не появится новый воспроизводимый регресс, нарушающий их acceptance contract.

## Текущая релизная топология

- опубликованный baseline: `v1.0.1` на точном опубликованном commit, закреплённом upgrade-drill;
- активная source-ветка: `dev`;
- ветка стабилизации и заморозки RC: `1.0`;
- финальная цель после приёмки: `master` + тег `v1.0.2`;
- feature work для `1.1.0` остаётся вне scope, пока не завершены gates 1.0.2 ниже.

Не замораживайте и не подписывайте RC, пока остаётся хотя бы одно принятое изменение исходников 1.0.2. Любое source change после exact-head acceptance аннулирует затронутые доказательства и требует повторного прохождения соответствующих gates.

## Сходимость исходников

Исходные задачи аудита 1.0 закрыты на уровне кода. Текущая стабилизация 1.0.2 добавляет эксплуатационное и релизное укрепление, а не переоткрывает архитектурную работу.

| Область | Текущее состояние исходников | Доказательства / реализация | Оставшийся gate |
|---|---|---|---|
| Изоляция модулей / Core control plane | Реализовано | актуальны module platform/isolation, lifecycle, router/security и browser lifecycle gates | exact-head rerun после финальной source freeze |
| Signed updater / rollback | Реализовано | единый operator flow, readiness doctor, remote delivery, external candidate, проверенный backup кода+MySQL, автоматический rollback/recovery и retention | финальная церемония production-signed artifact + exact final upgrade acceptance |
| Точный upgrade 1.0.1 -> 1.0.2 | Реализовано в CI | опубликованный `v1.0.1` устанавливается реальным installer; проверяются подписанный synthetic 1.0.2 success и принудительный rollback БД/кода после switch | повторить обязательную operator acceptance на финальных неизменяемых production-signed artifacts |
| Совместимость Windows | Реализовано в CI | Windows updater/path/runtime contracts для PHP 8.1/8.3 | финальная ручная приёмка OSPanel 5.2.2 на точных финальных artifacts |
| Realtime Messenger | Реализовано | native WebSocket fast path + автоматический аутентифицированный HTTP long-poll fallback; общий DB revision bridge; recovery со свежим ticket; защита worker/session-lock | финальная OSPanel/browser transport acceptance (#173) |
| 2FA/TOTP | Реализовано в объединённом кандидате | персональная настройка в Profile; общесистемная обязательность в Admin; TOTP/recovery codes/rate limit/security events; ротация TOTP-секретов вместе с `UNIQUE_KEY`; отдельные PHP 8.1/8.3 checks | финальная ручная проверка персонального и обязательного сценария на exact frozen RC |
| Диагностика WebSocket | Реализовано | подробный startup preflight, диагностика CLI PHP, bind-confirmed `[RUNNING]`, `ws_doctor`, runbook одного удалённого WS-узла и актуальная русская диагностика | exact-head rerun после финальной source freeze |
| Релизная документация | Актуализируется для 1.0.2 | release notes, hosting/deployment/operations/production docs должны описывать WebSocket-first + HTTP fallback и текущий контракт установки на 34 таблицы | сохранять синхронизацию с финальным frozen source |
| Визуальная система продукта | Source implementation присутствует | структурная light/dark переработка и последующие UX fixes находятся в линии 1.0 | live visual/operator acceptance на exact final RC (#172) |

## Текущие ручные и операторские gates

### G1 — управление репозиторием

Перед публикацией проверьте фактическую защиту `1.0` и `master` по `.github/release-governance.json` / `docs/RELEASE_GOVERNANCE.md`:

- merge только через PR;
- обязательные always-on checks;
- требование актуальной ветки;
- сброс устаревших approvals;
- запрет force-push и удаления;
- независимый approval, если существует другой квалифицированный reviewer.

Настройки репозитория живут вне истории Git. Source contracts не заменяют эту проверку. Если текущая GitHub-интеграция не может читать administration endpoints branch protection, сохраните подтверждение из owner/admin-сессии `gh`, а не делайте вывод по косвенным признакам.

### G2 — финальная заморозка исходников и exact-head CI

После merge всех принятых source PR для 1.0.2:

1. зафиксируйте один точный HEAD `dev` как финальный source candidate;
2. прекратите добавлять source changes;
3. выполните все релизно-значимые workflows на этом точном HEAD;
4. не подменяйте failed/skipped/unrun check зелёным результатом со старого SHA;
5. до изменения исходников классифицируйте любое падение как product defect, test/fixture defect, CI environment defect или stale workflow/base defect;
6. перенесите принятый exact source candidate в `1.0` и зафиксируйте новый frozen RC SHA.

### G3 — ручная визуальная приёмка (#172)

Повторите live visual/operator QA на точном frozen RC/artifact. Проверьте:

- Home, Notes, Tasks, Files, Messenger, Profile и Admin;
- light, dark и system themes;
- compact laptop / narrow desktop layout;
- ранее исправленное поведение Messenger composer и глобальных unread/notification;
- текущие состояния подключения Messenger: `WebSocket · в сети` и `Long Poll · резервный канал`.

CI/DOM checks являются поддерживающим доказательством, но не заменяют это решение.

### G4 — OSPanel 5.2.2 realtime + upgrade acceptance (#173)

На реальном целевом стеке и точных финальных artifacts:

1. подтвердите, что local/browser WebSocket fast path достигает `101 Switching Protocols` + application `Authorized`;
2. проверьте обычную доставку сообщений в состоянии `WebSocket · в сети`;
3. остановите/заблокируйте WS endpoint и подтвердите автоматическую durable-синхронизацию через `Long Poll · резервный канал` без reload;
4. восстановите WS и подтвердите автоматический возврат в `WebSocket · в сети`;
5. выполните финальную updater acceptance `1.0.1 -> 1.0.2` по `docs/WINDOWS_OSPANEL_ACCEPTANCE.md`;
6. зафиксируйте версии PHP/OSPanel, точный source SHA, SHA-256 bundle, signing key ID, результат обновления и healthcheck.

Ранее сохранённый CLI smoke полезен как история, но не заменяет browser acceptance exact-final-artifact.

### G5 — приёмка 2FA/TOTP

На точном frozen RC подтвердите оба режима политики:

1. при необязательной политике пользователь включает TOTP в Profile, повторный вход требует второй фактор;
2. резервный код завершает вход только один раз;
3. пользователь может перевыпустить резервные коды после подтверждения пароля и существующего второго фактора;
4. администратор включает «Обязательно для всех пользователей» с подтверждением собственного пароля;
5. аккаунт без TOTP после правильного пароля не получает обычную сессию и проходит обязательную настройку;
6. при обязательной политике пользователь не может отключить собственную 2FA;
7. после возврата политики в «По выбору пользователя» уже настроенная персональная 2FA сохраняется;
8. прямая ротация `UNIQUE_KEY`, возобновление после прерывания и rollback сохраняют рабочие TOTP-секреты.

Зафиксируйте точный source SHA и результат проверки. Не подтверждайте gate только на основании статического контракта или старой test-only ветки.

### G6 — backup / restore / эксплуатационная приёмка

На репрезентативном deployment:

- создайте свежий проверенный backup MySQL + `PRIVATE_STORAGE_PATH`;
- выполните restore drill в изолированное окружение;
- запустите `php bin/healthcheck.php --json`;
- проверьте защищённые данные Notes/Messenger/File Manager;
- просмотрите observability и retention preview;
- подтвердите возможность записи во внешнее updater/private state и достаточное свободное место.

### G7 — production trust и неизменяемые artifacts

Приватный signing material остаётся offline и никогда не должен коммититься, загружаться в CI, попадать в customer bundle или вставляться в логи/чат.

Для точного принятого SHA:

1. один раз соберите финальный hosting ZIP;
2. проверьте записанные source SHA + ZIP SHA-256;
3. соберите update manifest для версии `1.0.2` / кода `10002`;
4. подпишите точные bytes manifest offline-приватным ключом update-domain;
5. проверьте package hash/signature публичным trust registry из bundle;
6. выполните production license/update trust canaries;
7. после принятия checksum/signature не перепаковывайте и не изменяйте принятый ZIP.

## Финальная команда приёмки

Только после появления всех предыдущих доказательств выполните строгий acceptance preflight с правдивыми operator attestations:

```bash
php bin/release_acceptance.php --strict --json \
  --branch-protection-confirmed \
  --ci-green \
  --release-evidence-green \
  --backup-restore-current \
  --operational-acceptance-green \
  --visual-acceptance-green \
  --ospanel-acceptance-green \
  --two-factor-acceptance-green \
  --one-zero-one-drill-green \
  --p0p1-clear \
  --trust-canaries-green \
  --artifact-signed
```

Не передавайте attestation только ради зелёного результата команды.

## Финальная последовательность merge / публикации

После успешной строгой приёмки:

1. убедитесь, что exact frozen `1.0` содержит принятый source candidate без дополнительных изменений;
2. перенесите это exact accepted content в `master` через защищённый PR flow;
3. создайте подписанный/аннотированный тег `v1.0.2`;
4. опубликуйте exact previously accepted ZIP вместе с `update.json` и detached signature;
5. проверьте опубликованные checksum, source provenance, update feed и download metadata;
6. сохраните rollback/recovery evidence на релизное окно.

Если после любого шага acceptance/signing меняются исходники, аннулируйте затронутые доказательства и повторите соответствующие gates.

## Правило остановки

Не переоткрывайте завершённые архитектурные задачи 1.0 только из-за исторического текста аудита. Переоткрывайте только конкретное воспроизводимое нарушение текущего contract. И наоборот, не закрывайте #172, #173, governance verification или production signing по предположению: это явные human/operator boundaries.

Авторитетные процедуры:

- `docs/RELEASE_ACCEPTANCE.md`;
- `docs/RELEASE_GOVERNANCE.md`;
- `docs/PRODUCTION_TRUST_CEREMONY.md`;
- `docs/WINDOWS_OSPANEL_ACCEPTANCE.md`;
- `docs/releases/v1.0.2.md`.
