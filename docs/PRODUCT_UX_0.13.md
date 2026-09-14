# Workspace Organizer 0.13 — Product UX

Статус релиза: **закрыт**.

0.13 завершает alpha-цикл продуктовой переработки поверх 0.12 usable baseline. После этого релиза новые крупные функции замораживаются до beta-hardening: приоритет смещается на эксплуатационную надёжность, совместимость, наблюдаемость и доказанный upgrade/recovery contract.

## Цель 0.13

Перевести интерфейс из «рабочего административного приложения» в цельный ежедневный workspace, не ослабляя уже закрытые в 0.12 контракты целостности данных, private storage, installer/upgrade, BASE_PATH и browser E2E.

## Доставлено в 0.13

### Визуальный фундамент

- [x] Единая спокойная палитра content area, согласованная с тёмным sidebar.
- [x] Общий 0.13 visual layer для buttons / inputs / cards / panels / focus states.
- [x] Ключевые экраны избавлены от ощущения больших пустых технических форм.
- [x] Новые layouts учитывают reduced-motion и responsive breakpoints.

### Tasks

- [x] Kanban board: «Новые», «В работе», «Готово», «Отменено».
- [x] Компактные task cards.
- [x] Drag-and-drop между статусами через существующий ownership-checked update contract.
- [x] Быстрое создание задачи в целевой колонке.
- [x] List/board переключатель с локальным сохранением режима.
- [x] Existing bounded server-side search/filter/sort contract сохранён.

### Notes / Voice notes

- [x] Writing-first editor shell вместо legacy textarea layout.
- [x] Title/actions/autosave state/writing canvas собраны в единый editor workspace.
- [x] Attachments, share и voice вынесены в понятные секции.
- [x] Явная запись голоса: recording state, timer, stop/cancel/save и preview.
- [x] Playback внутри заметки и duration metadata.
- [x] Несколько voice attachments поддерживаются существующей моделью.
- [x] Private-storage/MIME/size ограничения и browser/backend lifecycle сохранены.

### Profile hub / publication

- [x] Компактный собственный Profile hub.
- [x] Быстрые переходы в Notes / Tasks / Files.
- [x] Profile metrics: counters + storage usage/quota без тяжёлого dashboard query.
- [x] Компактный settings/edit entry для профиля, пароля, avatar и deactivation.
- [x] Отдельный authenticated read-only профиль другого пользователя.
- [x] Private email/phone/property не загружаются в foreign-profile view.
- [x] `is_profile_public` contract для Notes / Tasks / Files, default private.
- [x] Share/capability link сам по себе не публикует объект в профиле.
- [x] Public Profile использует whitelist metadata и не раскрывает content, storage path, private URL или share token.

### File Manager

- [x] Toolbar/navigation hierarchy, локальный поиск и сортировка.
- [x] Grid/list workspace views с сохранением выбора.
- [x] Drag/drop upload использует существующий hardened upload pipeline.
- [x] Quota/storage contract и durable File Manager lifecycle не ослаблены.

### Messenger

- [x] Production runbook Workerman/WSS: `docs/MESSENGER_SERVER.md`.
- [x] Messenger reconnect/offline UX: online / reconnecting / offline / session-ended states.
- [x] Fresh short-lived WebSocket ticket перед reconnect.
- [x] Recovery после browser online/sleep/background с bounded backoff.
- [x] HTTPS/WSS Chromium regression доказывает reconnect и последующую realtime delivery.

### Installer / release safety

- [x] Hosting installer проходит реальный HTTP wizard при `BASE_PATH=/workspace/`.
- [x] Compatibility upgrade проверяется отдельным migration contract.
- [x] HTTPS/WSS E2E реально запускает PHP + Workerman + Nginx proxy и Chromium smoke.
- [x] 0.13 installer schema contract явно проверяет publication fields, voice duration и Messenger config.

## Definition of Done 0.13

- [x] 0.12 durable-data/security baseline сохранён.
- [x] Tasks имеет настоящий kanban flow без потери lifecycle guarantees.
- [x] Notes editor пригоден для ежедневной работы и имеет first-class voice-note flow.
- [x] Собственный Profile полезен как hub; чужой Profile раскрывает только явно опубликованные metadata.
- [x] File Manager получил современный workspace UX поверх hardened storage backend.
- [x] Messenger показывает понятные connection states и реально восстанавливает WSS session.
- [x] Ключевые переработанные UX flows имеют `/workspace/` browser regression.
- [x] Fresh install / compatibility upgrade / production release gate остаются зелёными.
- [x] `Core\Version`, README, CHANGELOG и readiness contract синхронизированы для `0.13.0-alpha` в release PR.

## 0.14 beta backlog

Следующие пункты осознанно **не блокируют 0.13 alpha**. Они переходят в beta-hardening, где новые крупные функции заморожены.

- Единый pass по hover / focus-visible / disabled / loading / empty states во всех модулях.
- Mobile/tablet polish для Tasks / Notes / Profile / File Manager / Messenger.
- Tasks: унифицировать priority/category labels, deadline/progress subtasks и quick actions на карточках.
- Notes: улучшить list cards и визуальную иерархию поиска/сортировки; унифицировать list empty/loading/error states.
- Voice notes: pause/resume recorder UX только если подтвердится потребность после beta usability review.
- Messenger: дополнительная визуальная унификация media/voice/search/group flows.
- File Manager: перенести upload progress/error из modal-flow непосредственно в file card/row UX.
- Продолжить вынос legacy inline JS/CSS без добавления нового CSP debt.

Отдельный beta roadmap должен дополнить это эксплуатационными задачами: observability/alerts, upgrade matrix, cross-browser/mobile coverage, soak/load baseline, data-retention contract и реальное enforcement release governance.
