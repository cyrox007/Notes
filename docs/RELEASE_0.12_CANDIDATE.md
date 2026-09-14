# Workspace Organizer 0.12 usable-alpha release candidate

Эта ветка закрывает 0.12 Usable Baseline и должна мержиться только после полностью зелёной PR-матрицы.

## Prerequisite PRs

Все пять prerequisite-блоков уже находятся в `master`:

- #68 — DB architecture: canonical `*_schema.sql` + compatibility upgrade SQL.
- #69 — Product Browser E2E: Profile, Admin и DB/storage fault injection.
- #70 — Usability: shared feedback, Notes draft protection и inline actions.
- #71 — Findability: bounded server-side search/pagination и URL state.
- #72 — Release governance: required-check policy и review contract.

Release branch пересобрана поверх объединённого `master`, поэтому PR содержит только финальную release-фиксацию и последний usability polish.

## Definition of Done evidence

В одном source tree подтверждены:

1. Failed durable DB writes не производят user-visible success.
2. File storage mutations имеют безопасный DB↔filesystem lifecycle и reconciliation path.
3. Root и `/workspace/` установки проходят browser baseline.
4. Notes, Tasks, File Manager, Profile и Admin имеют реальные browser lifecycle.
5. Storage quota проверяется настоящим upload flow.
6. Browser fault injection доказывает, что реальный DB metadata failure не оставляет false success или orphan file.
7. Notes/Tasks/Admin list views имеют bounded server-side findability.
8. Shared feedback, Notes draft protection и inline actions интегрированы.
9. File Manager имеет поиск/сортировку текущей папки и существующий per-file upload progress/error flow.
10. Release governance представлен machine-readable policy и проверяется `Master release gate`.
11. `docs/USABLE_BASELINE_0.12.md` не содержит незакрытых P1 release-scope пунктов.
12. Version, README и changelog идентифицируют `0.12.0-alpha` с датой `2026-09-14`.

`tests/integration/usable_alpha_readiness.php` валидирует эти source-level evidence и печатает каждую отсутствующую предпосылку отдельно.

## Database contract

Fresh install строится из canonical `database/*_schema.sql`. `bin/migrate.php` используется только как compatibility-upgrade runner для существующих установок; `schema_migrations` — внутренний ledger filename/checksum, а не отдельный источник canonical schema.

## Repository governance boundary

Repository-side policy, checklist и drift checks входят в release. Фактическое включение branch-protection enforcement относится к GitHub Administration state и выполняется one-time через Settings по `docs/RELEASE_GOVERNANCE.md`; GitHub App соединение проекта не имеет права самостоятельно менять эту настройку.

## Final verification

Перед merge этого PR должны быть зелёными:

- `0.12 usable-alpha readiness`;
- `Master release gate`;
- Notes/Tasks/File Manager/Profile/Admin browser lifecycle;
- browser fault injection;
- BASE_PATH hardening;
- installer/upgrade/security/production operations workflows.

После merge уже объединённый `master` должен повторно пройти `Master release gate`. Тег/GitHub Release создаётся только после этого пост-merge результата.
