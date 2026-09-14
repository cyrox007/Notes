# Workspace Organizer 0.13 — Product UX

## Цель

После 0.12 usable-alpha следующий этап переводит интерфейс из «рабочего административного приложения» в цельный ежедневный workspace. Сайдбар остаётся визуальным ориентиром: остальной продукт должен догнать его по плотности, иерархии, цвету и качеству состояний.

## P0 — визуальный фундамент

- [ ] Современная единая палитра content area: мягкий холодный фон, белые/elevated поверхности, спокойные borders, выразительный primary без кислотного контраста.
- [ ] Единые размеры и состояния buttons / inputs / cards / panels / badges.
- [ ] Нормальные hover / focus-visible / disabled / loading / empty states.
- [ ] Уменьшить ощущение «огромных пустых белых листов» на широком экране.
- [ ] Сохранить тёмный sidebar как сильную визуальную основу, не превращать приложение в тёмную тему.
- [ ] Mobile/tablet polish для новых layouts.

## P1 — Tasks как рабочая доска

Цель: приблизить UX к Trello/kanban, не копируя внешний вид буквально.

- [ ] Board view по статусам: «Новые», «В работе», «Готово», при необходимости «Отменено».
- [ ] Компактные task cards вместо формоподобного списка.
- [ ] Priority/category labels с понятной цветовой системой.
- [ ] Deadline, progress subtasks и быстрые действия видны прямо на карточке.
- [ ] Drag-and-drop между статусами с сохранением через существующий update contract.
- [ ] Быстрое создание задачи в колонке.
- [ ] List/board переключатель, если старый list view остаётся полезным.
- [ ] Поиск/filter state не теряется при переключении представления.

## P1 — Notes editor

- [ ] Убрать ощущение «большой textarea + огромная кнопка».
- [ ] Нормальный editor shell: title, toolbar/action row, autosave state, content canvas.
- [ ] Кнопка ручного Save не должна визуально доминировать при работающем autosave.
- [ ] Attachments/share/voice вынести в понятные секции или side panel.
- [ ] Вложения отображать как аккуратный список/карточки с типом, размером и действиями.
- [ ] Empty/loading/error состояния редактора.
- [ ] Улучшить Notes list cards и визуальную иерархию поиска/сортировки.

## P1 — Voice notes

Голос должен восприниматься как отдельный способ создать контент заметки, а не как техническое file attachment.

- [ ] Явная кнопка «Записать голос» в editor actions.
- [ ] Record timer, recording state, pause/cancel/save.
- [ ] Playback внутри заметки: play/pause, duration, progress.
- [ ] Список нескольких voice notes в одной заметке.
- [ ] MIME/size/private-storage ограничения остаются fail-closed.
- [ ] Browser lifecycle для записи/загрузки/playback metadata.

## P1 — Profile hub

### Свой профиль

- [ ] Компактный hero: avatar, имя, `@username`, контакты/поля профиля.
- [ ] Быстрые карточки «Мои заметки», «Мои задачи», «Мои файлы».
- [ ] Счётчики и/или краткий activity summary без тяжёлых dashboard-запросов.
- [ ] Storage usage/limit как полезный account metric.
- [ ] Edit profile/avatar/password/deactivation не должны занимать основной экран, а открываются как settings/edit mode.

### Чужой профиль

- [ ] Отдельный read-only route пользователя.
- [ ] Никогда не показывать private email/phone/property без явного public contract.
- [ ] Показывать только контент, который пользователь **явно** опубликовал.
- [ ] Share-link сам по себе не означает «показывать в публичном профиле».
- [ ] Для Notes/Tasks/Files ввести явный publication contract до появления public listing.
- [ ] Empty state «Пользователь пока ничего не публиковал».

## P1 — Messenger UX / эксплуатация

- [x] Отдельная инструкция запуска realtime Workerman/WSS server: `docs/MESSENGER_SERVER.md`.
- [ ] В UI показывать понятный reconnect/offline state без технических формулировок.
- [ ] Проверить визуальную консистентность voice/media/search/group flows с остальным 0.13 UI.

## P2 — File Manager

- [ ] Более выраженная toolbar/navigation hierarchy.
- [ ] Upload progress/error state сделать частью общего file row/card UX.
- [ ] Grid/list view при достаточной ценности для пользователя.
- [ ] Drag/drop upload после отдельной browser accessibility проверки.

## P2 — Profile/publication model

Публичность — security contract, а не CSS-функция. До реализации публичного профиля нужно определить для каждого типа данных:

- `private` — доступ только владельцу;
- `shared_by_link` — доступ по capability link, но не виден в public profile;
- `public_profile` — владелец явно разрешил показывать объект в своём публичном профиле.

Не переиспользовать существующий Notes share-token как автоматический признак публичной публикации.

## Definition of Done 0.13 UX

- sidebar и content area воспринимаются как одна дизайн-система;
- Tasks имеет настоящий kanban flow без потери текущих lifecycle guarantees;
- Notes editor комфортен для длинной ежедневной работы и имеет заметный voice-note flow;
- собственный Profile полезен как hub, чужой Profile не раскрывает private data;
- ключевые UX flows проходят root и `/workspace/` browser regression;
- новые действия не возвращают native `alert/confirm/prompt` и не увеличивают legacy inline JS/CSS debt.
