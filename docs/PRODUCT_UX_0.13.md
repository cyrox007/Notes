# Workspace Organizer 0.13 — Product UX

## Цель

После 0.12 usable-alpha следующий этап переводит интерфейс из «рабочего административного приложения» в цельный ежедневный workspace. Сайдбар остаётся визуальным ориентиром: остальной продукт должен догнать его по плотности, иерархии, цвету и качеству состояний.

> Статус синхронизирован с `master` после PR #74–#81. Галочка означает, что изменение уже находится в `master` и имеет соответствующий regression/browser contract там, где он нужен.

## P0 — визуальный фундамент

- [x] Современная единая палитра content area: мягкий холодный фон, белые/elevated поверхности, спокойные borders, выразительный primary без кислотного контраста.
- [x] Единый 0.13 visual layer для buttons / inputs / cards / panels и focus states.
- [ ] Довести hover / focus-visible / disabled / loading / empty states до единого уровня во всех модулях.
- [x] Уменьшить ощущение «огромных пустых белых листов» на ключевых переработанных экранах.
- [x] Сохранить тёмный sidebar как сильную визуальную основу, не превращая приложение в тёмную тему.
- [ ] Mobile/tablet polish для новых layouts.

## P1 — Tasks как рабочая доска

Цель: приблизить UX к Trello/kanban, не копируя внешний вид буквально.

- [x] Board view по статусам: «Новые», «В работе», «Готово», «Отменено».
- [x] Компактные task cards вместо формоподобного списка.
- [ ] Дополировать priority/category labels как единую визуальную систему.
- [ ] Дополировать deadline/progress subtasks/quick actions прямо на карточке.
- [x] Drag-and-drop между статусами с сохранением через существующий ownership-checked update contract.
- [x] Быстрое создание задачи в колонке.
- [x] List/board переключатель с локальным сохранением режима.
- [x] Existing server-side search/filter/sort contract сохранён; board работает поверх текущего bounded result set.

## P1 — Notes editor

- [x] Убрано ощущение «большой textarea + огромная кнопка».
- [x] Новый editor shell: title, actions, autosave state, writing canvas.
- [x] Ручной Save больше не доминирует над editor workflow.
- [x] Attachments/share/voice вынесены в понятные секции editor workspace.
- [x] Вложения и voice notes отображаются отдельными карточками/элементами с действиями.
- [ ] Довести empty/loading/error states Notes до общего 0.13 UI contract.
- [ ] Улучшить Notes list cards и визуальную иерархию поиска/сортировки.

## P1 — Voice notes

Голос должен восприниматься как отдельный способ создать контент заметки, а не как техническое file attachment.

- [x] Явная кнопка записи голоса в editor actions.
- [x] Recording state, timer, stop/cancel/save и preview.
- [x] Playback внутри заметки и сохранение duration metadata.
- [x] Несколько voice attachments поддерживаются существующей моделью вложений заметки.
- [x] MIME/size/private-storage ограничения остаются fail-closed.
- [x] Browser/backend lifecycle для voice upload, persisted metadata и private playback.
- [ ] Отдельный pause/resume UX записи, если он остаётся нужен после usability review.

## P1 — Profile hub

### Свой профиль

- [x] Компактный hero: avatar, имя, `@username`, account/profile context.
- [x] Быстрые карточки «Мои заметки», «Мои задачи», «Мои файлы».
- [ ] Счётчики и/или краткий activity summary без тяжёлых dashboard-запросов.
- [ ] Storage usage/limit как полезный account metric.
- [ ] Edit profile/avatar/password/deactivation вынести из основного hub в более компактный settings/edit mode.

### Чужой профиль

- [x] Отдельный authenticated read-only route пользователя.
- [x] Private email/phone/property не загружаются в foreign-profile view.
- [x] Показывается только контент, который пользователь явно опубликовал.
- [x] Share-link сам по себе не означает «показывать в публичном профиле».
- [x] Для Notes/Tasks/Files введён явный `is_profile_public` contract, default private.
- [x] Есть safe empty state, если пользователь ничего не публиковал.

## P1 — Messenger UX / эксплуатация

- [x] Отдельная инструкция запуска realtime Workerman/WSS server: `docs/MESSENGER_SERVER.md`.
- [ ] В UI показывать понятный reconnect/offline state без технических формулировок.
- [ ] Проверить визуальную консистентность voice/media/search/group flows с остальным 0.13 UI.

## P2 — File Manager

- [x] Более выраженная toolbar/navigation hierarchy, локальный поиск и сортировка.
- [ ] Upload progress/error state сделать частью общего file row/card UX.
- [x] Grid/list view с сохранением пользовательского выбора.
- [x] Drag/drop upload через существующий hardened upload pipeline и отдельный Chromium contract.

## P2 — Profile/publication model

Публичность реализована как отдельный security contract:

- [x] `private` — объект не показывается в чужом профиле;
- [x] `shared_by_link` — capability link не делает объект публичным в профиле;
- [x] `public_profile` — только явное действие владельца выставляет `is_profile_public=1`.
- [x] Public Profile использует whitelist metadata и не публикует note content, task description, storage path, private download URL или share token.
- [x] Fresh install и compatibility upgrade содержат publication schema contract.

## Инсталлятор / release safety

- [x] Hosting installer проходит реальный HTTP wizard в hosting-like `/workspace/`.
- [x] Compatibility upgrade проверяется отдельным migration contract.
- [x] HTTPS/WSS E2E реально запускает PHP + Workerman + Nginx proxy и Chromium smoke.
- [ ] Смержить отдельный 0.13 installer regression gate (#82) после review, чтобы новые schema fields проверялись явно и на будущих изменениях.

## Definition of Done 0.13 UX

- [ ] Sidebar и content area воспринимаются как одна дизайн-система на всех основных экранах.
- [x] Tasks имеет настоящий kanban flow без потери lifecycle guarantees.
- [x] Notes editor пригоден для ежедневной работы и имеет заметный voice-note flow.
- [x] Собственный Profile полезен как hub, чужой Profile не раскрывает private data и показывает только явно опубликованное.
- [x] Ключевые переработанные UX flows имеют `/workspace/` browser regression.
- [ ] Новые/оставшиеся действия не возвращают native `alert/confirm/prompt` и не увеличивают legacy inline JS/CSS debt.

## Следующий порядок работ

1. Messenger reconnect/offline UX + визуальная консистентность media/voice/search/group.
2. Profile metrics: counters + storage usage + компактный settings/edit mode.
3. Notes list polish и единые empty/loading/error states.
4. File Manager inline upload progress/error UX.
5. Mobile/tablet pass по Tasks / Notes / Profile / File Manager / Messenger.
6. 0.13 release candidate: version/changelog/readiness contract после закрытия пунктов выше.
