# Workspace Organizer 1.0 — журнал задач релиза

> **Обновление статуса — 21 сентября 2026.** `v1.0.1` опубликован. Таблица ниже сохранена как исторический журнал стабилизации 1.0/1.0.1. Активная maintenance-разработка перешла к `1.0.2`, scope которой — production delivery/operator flow updater-а и эксплуатационная проверка перед `1.1.0`. См. `docs/ROADMAP.md` и `docs/releases/v1.0.2.md`.

Этот файл — stop/reopen ledger для стабилизации 1.0. Он разделяет **реализацию в исходниках**, **операторские действия для релиза** и **финальную фазу тестирования точного head**, чтобы завершённая engineering-задача не открывалась заново только потому, что release evidence ещё ожидаются.

Исторические audit IDs сохраняются для непрерывности. Source item открывается заново только при новом воспроизводимом нарушении его acceptance contract.

| Пункт аудита | Состояние исходников | Evidence в исходниках | Оставшийся release gate | Статус до финальной тестовой фазы |
|---|---|---|---|---|
| R01 Files baseline | Реализовано | Исправления quota asset/runtime модуля Files объединены; последующие Files lifecycle и HTTP workflows используют module-owned paths | Exact-head browser/HTTP/durable regression | Source закрыт |
| R02 Profile migration | Реализовано | PR #146 merged; Profile изолирован, lifecycle workflow актуален | Exact-head Profile lifecycle | Source закрыт |
| R03 независимость модулей | Реализовано | Admin/Messenger isolation, удаление transitional loader, composition-aware DB ownership и Core recovery control plane объединены (#148–#152) | Exact-head composition/module regression | Source закрыт |
| R04 схождение CI | Реализовано | Source convergence 1.0.0 завершён; post-tag module onboarding объединён, а 1.0.1 добавляет signed user-seat licensing с обновлёнными release contracts | Повторить полный набор exact-head gates на финальном SHA 1.0.1 | Source закрыт; exact-head rerun 1.0.1 ожидается |
| R05 GitHub merge governance | Реализовано; требуется повторное действие оператора | Release governance contract и protection applicator объединены. Visibility репозитория переключалась private/public во время восстановления CI, поэтому protection/rulesets нужно повторно применить и проверить перед публикацией | Повторно применить checked-in protection к `1.0` и `master`, проверить required checks и запрет force-push/deletion, затем оставить защиту включённой до публикации | Ожидается действие оператора |
| R06 production trust roots | Public roots закоммичены | Независимые production license/update public Ed25519 roots находятся под отдельными immutable key IDs; release CI проверяет непустые и независимые registries | Подтвердить офлайн custody private keys и выполнить license/update canaries без раскрытия private material | Ожидаются operator canaries |
| R07 оставшиеся обязательства 1.0 | Реализовано | Data-key rotation, nonce CSP, security observability, retention/permanent purge и cross-browser/load evidence harness объединены (#153, #154, #158–#160) | Exact-head evidence и operational acceptance | Source закрыт |
| R08 финальная release acceptance | Подготовка релиза 1.0.1 | `v1.0.0` остаётся immutable historical cut; maintenance candidate — `1.0.1` / version code `10001`, добавляющий module onboarding, signed `max_users` licensing, durable user-action audit и cleanup legacy templates | Exact-head CI/evidence 1.0.1, восстановленная branch protection, backup/restore + P0/P1 acceptance, immutable bundle 1.0.1, офлайн update signature, tag `v1.0.1` и GitHub Release | Финальная публикация ожидается |

## Финальная корректировка source convergence

Поздний repository-wide аудит release paths обнаружил устаревшие assumptions CI/package, которые не были видны при первоначальном закрытии ledger. Они рассматриваются как конкретные воспроизводимые нарушения исходников, а не как повторное открытие уже завершённой архитектурной работы.

Финальный patch source-convergence исправляет:

- pre-isolation Messenger, Notes, Profile и Files paths, на которые всё ещё ссылались release-relevant workflows;
- legacy ожидания 27 tables, сохранившиеся после composition-aware контракта 32 tables;
- legacy readiness fixtures, которые всё ещё утверждали историческую структуру исходников/version state вместо compatibility guarantees;
- hosting package path после Messenger isolation;
- автоматическую публикацию public GitHub Release до офлайн update-signing ceremony;
- provenance release candidate через запись ZIP SHA-256 и точного source SHA;
- попадание repository-only `tests/` и `tools/` в customer bundle, а также Apache denial для `config/`, `tests/`, `tools/` и прямого доступа к `core.php`;
- ошибку serialization status-context в branch-protection applicator, найденную при первом реальном применении.

Миграция на self-hosted runner не входит в source candidate 1.0. Release workflows остаются на GitHub-hosted runners, если это явно не изменено последующей принятой source task.

## Текущее правило заморозки исходников

Не начинайте финальную тестовую фазу, пока продолжают добавляться новые source tasks. После завершения operator setup R05 и R06 выберите один точный SHA `1.0` как release candidate и запустите весь согласованный набор evidence на этом SHA.

Ошибка на этой фазе должна быть классифицирована до изменения кода:

1. product defect;
2. test/fixture defect;
3. CI environment defect;
4. stale workflow/base defect.

Только воспроизводимое product/source violation повторно открывает соответствующий R item. Infrastructure failures не превращают уже принятую архитектурную работу обратно в открытую design task.

## Операторская граница перед финальными тестами

Два release action намеренно не могут быть завершены только кодом репозитория:

- применить checked-in GitHub protection policy с авторизованным repository owner/admin;
- провести production license/update Ed25519 key ceremony в контролируемом offline storage и закоммитить только public trust roots.

Private signing material нельзя коммитить, загружать в CI, включать в release bundle, устанавливать на customer system или вставлять в issue/chat logs.

## Финальная фаза тестирования

После выполнения operator boundary:

1. заморозьте точный SHA release candidate;
2. запустите все release-relevant checks на этом SHA через checked-in GitHub-hosted workflow configuration;
3. выполните backup/restore и production-style operational acceptance;
4. проверьте production trust canaries;
5. соберите immutable bundle, проверьте записанные SHA-256/source SHA, подпишите update manifest и проверьте подпись;
6. объедините принятое содержимое 1.0.1 в `master`, создайте `v1.0.1` и публикуйте только при выполнении всех required gates.

Авторитетные процедуры: `docs/RELEASE_ACCEPTANCE.md`, `docs/RELEASE_GOVERNANCE.md` и `docs/PRODUCTION_TRUST_CEREMONY.md`.
