# Workspace 0.12 — Usable Baseline

Цель релиза: довести существующие модули до состояния, в котором ежедневные пользовательские сценарии надёжны, предсказуемы и защищены от потери/рассинхронизации данных.

## P0 — Data integrity и File Manager

- [x] Ввести единый контракт для queued DB writes: ошибка commit не может молча превращаться в успешный пользовательский ответ.
- [x] Пройти все callers `queueInsert/queueUpdate/queueDelete + commit()` и убрать unchecked commits.
- [x] Исправить File Manager upload/create folder/rename/delete так, чтобы DB failure не приводил к false success.
- [x] Исключить потерю физического файла при неудачном soft-delete metadata.
- [x] Определить атомарный lifecycle для private storage: durable metadata/state transition -> physical cleanup -> reconciliation.
- [x] Исправить soft-delete папок: потомки не должны оставаться активными/учитываться в quota после удаления родителя.
- [x] Добавить reconciliation-команду для расхождений DB ↔ filesystem.
- [x] Исправить регистрацию: не редиректить на login после неудачного commit.

## P0 — Storage quotas / merged PR #61

- [x] Закрыть false-success upload при неудачной записи metadata через общий fail-closed commit contract.
- [x] Добавить настоящий HTTP multipart integration test через router/middleware/controller chain.
- [x] Проверить CSRF/auth/role/quota/concurrency/upload-vs-admin-update end-to-end.
- [x] Сделать migration settings/quota безопасной для partial legacy schema.
- [x] Показывать обычному пользователю `used / quota / remaining` в File Manager.

## P0 — BASE_PATH contract

- [x] Убрать root-relative application URLs из шаблонов и JS там, где они ломают subdirectory install.
- [x] Унифицировать URL generation через `route_path`, `base_url` и JS base-path helper.
- [x] Добавить E2E установки и работы приложения в `/workspace/`.

## P1 — Product browser E2E

Минимальные реальные browser flows:

- [ ] Notes: create → edit → attachment → share → delete.
- [ ] Tasks: create → edit → subtask → status → delete.
- [ ] Files: folder → upload → preview/download → rename → delete → quota error.
- [ ] Profile: edit profile → avatar upload/remove.
- [ ] Admin: user status → quota update.
- [ ] Fault injection: DB failure не должен давать success и не должен оставлять опасную storage inconsistency.

## P1 — Usability pass

- [ ] Общий toast/inline-error/confirmation component вместо `alert/confirm/prompt`.
- [ ] Сократить full-page reload для мелких операций.
- [ ] Notes: dirty-state warning / autosave, поиск.
- [ ] Tasks: поиск и более быстрые inline actions.
- [ ] Files: поиск, сортировка, понятный progress/error по каждому файлу.

## P1 — Pagination / findability

- [ ] Notes: server-side `q/page/limit/sort`.
- [ ] Tasks: server-side `q/page/limit/sort`.
- [ ] Admin users: server-side `q/page/limit/sort`.
- [ ] Сохранять фильтры и сортировку в URL.

## P1 — Security / governance hardening

- [x] `LoginRequared` должен проверять и `role`, и `is_active`.
- [x] `IsAdmin` должен проверять и `role`, и `is_active`.
- [ ] Сделать release/browser checks обязательными для merge в default branch.
- [ ] При наличии второго участника проекта — минимум одно независимое approval для merge.

## P2 — После Usable Baseline

- [ ] Полезный dashboard: ближайшие задачи, последние заметки, непрочитанные сообщения, storage usage, быстрые действия.
- [ ] Улучшенный Notes editor.
- [ ] Mobile polish.
- [ ] Unified command/search.
- [ ] Постепенное удаление legacy inline JS/CSS и `unsafe-inline`.

## Definition of Done для 0.12

Релиз можно считать usable alpha, когда:

1. Ни один пользовательский запрос не сообщает success после неудачной durable DB write.
2. Ошибка БД при файловой операции не приводит к потере физического файла или неучтённому orphan без reconciliation path.
3. Root install и install в `/workspace/` проходят одинаковый browser E2E baseline.
4. Каждый основной модуль имеет хотя бы один реальный create → modify → delete browser flow.
5. Storage quota закрыта не только service-level тестами, но и настоящим upload flow.

## Порядок выполнения

1. Data integrity + File Manager lifecycle.
2. Storage quota correctness поверх уже смерженного PR #61.
3. BASE_PATH contract.
4. Product browser E2E.
5. Usability pass.
6. Pagination/findability.
7. Security/governance hardening.
8. Product UX следующего релиза.
