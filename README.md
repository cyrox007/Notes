# Workspace Organizer

**Версия:** `1.0.14`  
**Актуально на:** 1 октября 2026  
**Статус:** stable

Workspace Organizer — self-hosted PHP-приложение для корпоративной работы: заметки, личные и общие задачи, файлы, профиль, администрирование и real-time Messenger.

`1.0.14` — исправляющий выпуск линии 1.0 после проблемного эксплуатационного перехода 1.0.12 → снятый 1.0.13. Он устраняет повторный запуск уже вошедших в старую версию миграций при отсутствии `schema_migrations`, делает rollback переносимым для ограниченных прав MySQL без чужого `DEFINER`, завершает подтверждённый rollback без зависшего web-lease, инвалидирует OPcache после пофайловой замены и добавляет одноразовый диагностический пакет поддержки. Единственный обязательный путь приёмки — `1.0.12 → встроенный подписанный updater → 1.0.14`.

## Возможности

- **Заметки** — зашифрованный текст, вложения, голосовые заметки, общий доступ только для просмотра, автосохранение, поиск и ограничения по ролям.
- **Задачи** — личные и общие доски, список и канбан, статусы, приоритеты, сроки, категории, подзадачи, исполнители, фильтры и поиск.
- **Файлы** — личные папки и файлы в закрытом хранилище, просмотр, поиск, сортировка, загрузка перетаскиванием, квоты и ограничения по ролям.
- **Messenger** — личные и групповые диалоги, «Сохранённые сообщения», пересылка, медиа и голос, ответы, редактирование, удаление, реакции, статусы доставки/прочтения и работа с нескольких устройств. HTTP Long Poll является надёжным каналом, WebSocket — ускорителем.
- **Профиль** — аватар, настройки аккаунта, показатели хранилища и безопасная публикация профиля без раскрытия закрытого содержимого.
- **Администрирование** — пользователи, регистрация и приглашения, роли и разрешения, политики модулей, пользовательские поля, квоты, лицензия и управление составом модулей.
- **Адаптивный интерфейс** — единая система оформления для настольных и мобильных экранов, клавиатурный фокус, уменьшение анимаций и общий механизм обратной связи.

## Модель безопасности

Основные свойства текущей версии:

- пароли хранятся через `password_hash` / Argon2id;
- текст заметок шифруется XChaCha20-Poly1305 с `UNIQUE_KEY`, UID заметки используется как AAD;
- текст и подписи Messenger шифруются отдельным `MSG_SECRET_KEY`;
- ошибки расшифровки новых зашифрованных данных обрабатываются закрыто;
- WebSocket использует подписанный сервером ticket и не доверяет UID, переданному клиентом;
- разрешённые источники и действия WebSocket ограничены;
- файлы, медиа Messenger, вложения заметок и аватары хранятся в `PRIVATE_STORAGE_PATH` вне document root;
- MIME загружаемых файлов определяется на сервере через `finfo` и проверяется по разрешённому списку;
- изменяющие HTTP-запросы защищаются политикой CSRF;
- вход, регистрация и загрузка файлов ограничиваются по частоте;
- RBAC отвечает за разрешения действий, а `role_module_policies` — за количественные и типовые ограничения;
- публичная регистрация по умолчанию закрыта; приглашения хранятся только в виде SHA-256 hash;
- состояние пользователя повторно проверяется как в HTTP, так и в WebSocket.

> Messenger использует **серверное шифрование данных при хранении**, а не сквозное шифрование. Сервер способен расшифровать сообщения.

> Вложения и аватары защищены закрытой файловой системой и ACL. Они не считаются отдельно зашифрованными при хранении, если конкретный поток хранения явно не реализует такое шифрование.

## Требования

- PHP `8.1+` — технический compatibility floor; для Internet-facing production рекомендуется поддерживаемая ветка PHP, сейчас `8.3+`;
- БД: MySQL `8.0+` или MariaDB `10.5+`; CI проверяет MySQL 8.4 и MariaDB 10.11;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `ctype`, `fileinfo`, `sodium`, `openssl`, `zlib`, `gd`; доступны `ini_get`/`getenv`/`putenv`, рабочие PHP-сессии и upload temp;
- Messenger работает через обычный authenticated HTTP long poll даже без WebSocket process; для низкой задержки и меньшей нагрузки рекомендуется PHP CLI + long-running native WebSocket process и WebSocket endpoint/proxy; daemon mode на Unix дополнительно требует `pcntl`;
- Argon2id support в `password_hash`;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS для production; WSS рекомендуется для низколатентного Messenger fast path, при его недоступности работает authenticated HTTP long poll.

Подробная матрица Open Server 6+, legacy-compatible Open Server 5.4.x, shared hosting и VPS/VDS: [`docs/DEPLOYMENT_COMPATIBILITY.md`](docs/DEPLOYMENT_COMPATIBILITY.md).

**Composer не является runtime-зависимостью 1.0.** Готовый hosting bundle и source tree запускаются без `vendor/`; Composer может использоваться только как development/tooling utility, но production package не зависит от него.

## Fresh install на обычном хостинге

Fresh install должен поднимать проект **без ручного импорта SQL, ручного создания `.env` и запуска Composer/CLI на хостинге**.

Рекомендуемый сценарий:

1. Скачайте `workspace-organizer-v*.zip` из GitHub Release.
2. Загрузите и распакуйте его в нужный каталог сайта.
3. Если MySQL-пользователь хостинга не имеет `CREATE DATABASE`, один раз создайте пустую БД через панель хостинга.
4. Откройте в браузере:

```text
https://example.com/install.php
```

или, при установке в подкаталог:

```text
https://example.com/workspace/install.php
```

5. Укажите MySQL credentials и создайте первого администратора.

Web-installer автоматически:

- проверяет PHP 8.1+, необходимые extensions, `ini_get/getenv/putenv`, PHP session/upload temp, `flock`/atomic rename, Argon2id, лимиты загрузки и низкий `memory_limit`; production runtime не требует `vendor/`;
- проверяет MySQL 8.0+ / MariaDB 10.5+, права `CREATE/ALTER/TRIGGER/DROP` и пытается создать отсутствующую БД, если учётная запись БД это разрешает;
- импортирует composition-aware canonical schemas и создаёт current contract из 35 обязательных таблиц;
- создаёт `cache`/`compile`;
- подбирает и создаёт `PRIVATE_STORAGE_PATH` вне document root;
- создаёт private пространства `file_manager`, `messenger`, `notes`, `users`, `rate-limit`, `logs`, `legacy`;
- определяет `SITEURL` и `BASE_PATH`, включая установку в подкаталог;
- формирует same-site `WS_PUBLIC_URL` вида `/ws` и `WS_ALLOWED_ORIGINS`;
- генерирует отдельные `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET`;
- создаёт первого superadmin;
- только после успешной финализации атомарно создаёт `.env` и блокирует повторный запуск installer.

Если установка оборвалась до создания admin, `.env` ещё не существует и мастер можно безопасно запустить повторно.

Подробная пошаговая инструкция: [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md).

### Установка из исходников

Исходный tree 1.0 является vendor-free и не требует `composer install` для запуска. После checkout/deploy можно использовать `/install.php`; вручную копировать `default.env` и импортировать SQL для **fresh install** не требуется.

### Private storage

Пример production-структуры:

```text
/var/lib/notes/private/
├── file_manager/
├── messenger/
├── notes/
├── users/
├── rate-limit/
├── logs/
└── legacy/
```

Canonical root вложений Notes — `PRIVATE_STORAGE_PATH/notes/`; browser никогда не получает этот physical path как URL.

На shared hosting installer предпочитает каталог в домашнем каталоге аккаунта, **выше `public_html` / document root**. Если тариф запрещает PHP запись вне web-root, такой тариф не соответствует security contract проекта.

### Database

Canonical fresh schemas:

```text
database/messenger_schema.sql
database/notes_schema.sql
database/file_manager_schema.sql
database/user_fields_schema.sql
database/tasks_schema.sql
database/access_control_schema.sql
database/audit_schema.sql
database/settings_schema.sql
database/module_lifecycle_schema.sql
```

Fresh contract включает 35 обязательных таблиц: persisted `module_lifecycle`, RBAC + `role_module_policies`, а также `task_boards`, `task_board_members`, `task_board_items` и `task_board_assignees`. `system_settings` хранит редактируемые системные значения, а `user_storage_quotas` — только персональные overrides лимита; фактический used space всегда рассчитывается из canonical `user_files`, чтобы не поддерживать рассинхронизируемый usage counter. `install.php` предназначен только для новой/пустой БД. Для существующих установок используются compatibility upgrade SQL; они не заменяют canonical `*_schema.sql` как описание текущей схемы.

После успешной установки наличие `.env` блокирует повторный запуск web-installer.

### Registration

По умолчанию публичная регистрация закрыта. Администратор управляет политикой в `/admin/registration` и может выбрать один из трёх режимов:

- `disabled` — самостоятельная регистрация запрещена;
- `open` — свободная регистрация;
- `invite` — регистрация только по действующему управляемому инвайту.

В invite-only режиме администратор создаёт приглашения с названием, сроком действия и лимитом использований. Полный код показывается один раз; в `system_settings` сохраняется только SHA-256 hash и служебные метаданные. Инвайт можно отозвать, а исчерпанный или просроченный код автоматически перестаёт действовать. Создание аккаунта и расход инвайта выполняются одной транзакцией.

Администратор также может создавать обычных пользователей напрямую из `/admin/` независимо от публичного режима регистрации. Такое создание не выдаёт admin/superadmin права: новый аккаунт получает каноническую RBAC-роль `user`. Суперадминистратор управляет прикладными ролями, permission assignment и module policies через `/admin/roles`.

`REGISTRATION_INVITE_CODE` из `.env` сохранён только как compatibility fallback для старых установок, пока администратор ни разу не сохранил новую явную политику в БД. После сохранения режима env-код больше не является отдельным authorization path.

### WebSocket

Installer записывает same-site URL вида:

```env
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

На production hosting WebSocket остаётся рекомендуемым ускорителем realtime: публичный `/ws` обычно проксируется на локальный native WebSocket process, а long-running PHP process запускается отдельно через hosting background-process manager, systemd/Supervisor или аналогичный process manager:

```bash
php ws_server/server.php check
php ws_server/server.php start
php bin/ws_doctor.php
```

Если WebSocket недоступен, browser автоматически переключает Messenger на `/messenger/realtime/poll` + `/messenger/realtime/action`. Fallback использует те же server-side permissions/license/maintenance gates и тот же Messenger dispatcher; после восстановления WebSocket клиент бесшовно возвращается на него. `MESSENGER_LONG_POLL_TIMEOUT_SECONDS` по умолчанию равен 15 секундам (допустимо 5–25).

Поддерживается также **один отдельный WebSocket-узел**, например `wss://ws.example.com/ws`, при условии одинакового release/commit, общей application DB, согласованных `WS_TICKET_SECRET`/`MSG_SECRET_KEY`, общего Messenger private storage и общего maintenance `UPDATE_STATE_PATH`. Shared DB realtime revision bridge синхронизирует durable HTTP-fallback mutations с активными WS-клиентами. Несколько одновременно активных WS instances одной installation пока не поддерживаются как HA/load-balancing topology.

Полный runbook: [`docs/MESSENGER_SERVER.md`](docs/MESSENGER_SERVER.md).

## Upgrade existing DB

Web-installer **не используется для upgrade** и намеренно отказывается изменять старую/частичную БД.

До обновления сделайте backup БД, `PRIVATE_STORAGE_PATH` и действующих crypto keys.

Проверка состояния compatibility upgrades:

```bash
php bin/migrate.php --status
```

Dry-run:

```bash
php bin/migrate.php --dry-run
```

Apply:

```bash
php bin/migrate.php
```

`bin/migrate.php` — upgrade runner для уже существующих SQL-скриптов совместимости, а не источник canonical schema. Внутренняя таблица `schema_migrations` хранит filename + SHA-256 checksum уже применённых upgrade scripts, чтобы повторный запуск был идемпотентным и изменение ранее применённого SQL обнаруживалось fail-closed. Уже применённый upgrade SQL не переписывается задним числом — добавляется новый compatibility script.

### Legacy crypto migration

Сначала dry-run:

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
```

Затем bounded/resumable migration:

```bash
php bin/migrate_crypto.php --scope=all --limit=1000
php bin/migrate_crypto.php --scope=messenger --limit=1000 --after-id=5000
```

`--allow-plaintext-notes` используйте только для вручную подтверждённых legacy Notes, которые исторически были помечены encrypted, но фактически содержали plaintext.

После успешной миграции legacy Messenger ciphertext удалите временный `MSG_LEGACY_SECRET_KEY`.

## Production healthcheck

```bash
php bin/healthcheck.php
php bin/healthcheck.php --json
```

Healthcheck проверяет PHP/extensions, secrets, private storage и его размещение вне application root, HTTPS/WSS/origin consistency, DB connection и current 35-table schema contract. Ненулевой exit code означает, что deployment нельзя считать healthy.

## Rate limiting

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Single-node limiter хранит state под `PRIVATE_STORAGE_PATH/rate-limit` и использует `flock`. Для multi-node deployment задайте отдельный `RATE_LIMIT_STORAGE_PATH` на общем POSIX volume с рабочими advisory locks; `DEPLOYMENT_NODE_COUNT>1` заставляет healthcheck требовать такой shared path. Заголовки `X-Real-IP`/`X-Forwarded-For` учитываются только от адресов из явного `TRUSTED_PROXY_IPS`.

## HTTP / CSP baseline

Repository `.htaccess` отвечает за static/access headers и routing; Content-Security-Policy формируется PHP Core:

- запрещает directory listing;
- закрывает от прямой HTTP-выдачи `app`, `bin`, `core`, `database`, `docs`, `vendor`, `ws_server`, `.github`, `.git`, `.logs`, `.env/default.env` и repository metadata;
- блокирует `install.php` после появления `.env`;
- задаёт `nosniff`, Referrer Policy, SAMEORIGIN, Permissions Policy и COOP;
- Core CSP запрещает objects, ограничивает base/forms/frame ancestors и использует per-request nonce;
- внешние JS CDN не требуются;
- `unsafe-eval` удалён после отказа от браузерного code runner в File Manager.

CSP формируется Core на каждый HTML request с криптографическим nonce. `script-src-attr 'none'` и `style-src-attr 'none'` запрещают inline event/style attributes, а `unsafe-inline` больше не входит в policy. Intentional inline `<script>/<style>` допускаются только с per-request nonce и контролируются отдельным CSP contract.

HSTS намеренно задаётся на production TLS reverse proxy, а не в repository `.htaccess`.

## Интерфейс и пользовательские модули

Интерфейс формируется native PHP views без отдельного frontend build pipeline и без runtime-зависимости от Smarty.

Текущий слой интерфейса включает:

- единые design tokens и адаптивный shell;
- desktop collapse и mobile drawer sidebar;
- видимость разделов по активным модулям и RBAC;
- Tasks: kanban/list, общие доски, ACL и исполнители;
- Notes: writing-first editor, вложения, sharing и голосовые заметки;
- Profile: hub, avatar, настройки и явную публикацию разрешённых объектов;
- File Manager: grid/list, поиск, сортировку, drag-and-drop upload и безопасный preview;
- Messenger: private/group chats, media/voice, реакции, поиск, Long Poll и WebSocket;
- Admin: пользователи, роли, политики, модули, системные настройки и лицензирование;
- keyboard focus, reduced-motion и mobile/touch сценарии.

История UX-цикла 0.13 сохранена только как архив: [`docs/PRODUCT_UX_0.13.md`](docs/PRODUCT_UX_0.13.md).

## Основные URL

```text
/                    главная
/auth/login          вход
/notes/              заметки
/tasks/              личные задачи
/tasks/boards        общие доски задач
/files/              личные файлы
/messenger/          Messenger
/profile/            профиль
/admin/              панель администратора
/admin/roles         роли и политики
/admin/modules       управление модулями
/admin/settings      системные настройки
/system/license      восстановление лицензии
```

Core-маршруты находятся в `core/routerConfig.php`; прикладные маршруты регистрируются providers активных модулей.

## Кратко о модулях

### Notes

Зашифрованный текст, attachments, voice notes, view-only sharing, search/pagination и role policies.

### Tasks

Личные задачи и общие boards, kanban/list, статусы, сроки, подзадачи, категории, ACL, участники и исполнители.

### Messenger

Private/group dialogs, Saved Messages, forwarding, media/voice, reply/edit/delete, delivery/read state, reactions, multi-device, pin/mute/archive и bounded encrypted search. Long Poll — гарантированный transport, WebSocket — быстрый канал.

### Profile

Private avatar, настройки аккаунта, безопасная деактивация, workspace metrics и явная публикация разрешённых Notes/Tasks/Files.

### File Manager

Private storage, папки/файлы, protected download, media/text preview, quotas, поиск/сортировка и sharing.

### Admin

Управление пользователями, регистрацией, ролями, permissions, module policies, включением/отключением модулей, лицензией и системными настройками.

## Плановое обслуживание

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемый cron/systemd timer: каждые 15–60 минут.

Также контролируйте свободное место, private storage, upgrade state, журналы, backup/restore и временные legacy keys.

## CI

Обязательная матрица проверяет:

- PHP/runtime и security contracts;
- canonical schemas и compatibility upgrades;
- installer на MySQL и MariaDB;
- lifecycle Notes/Tasks/Files/Profile/Admin;
- Messenger Long Poll/WebSocket и HTTPS/WSS;
- updater success/rollback/recovery;
- Windows compatibility;
- cross-browser/mobile release evidence;
- fault injection DB/storage;
- release governance и production healthcheck.

Некоторые workflow сохраняют исторические имена 0.14/Beta4 ради стабильности check contexts. Их название не означает, что соответствующий старый roadmap остаётся активным.

`Build hosting package` собирает самодостаточный release ZIP без runtime-зависимости от Composer/vendor.

`Master release gate` выполняется для релизных PR и объединённого `master` и проверяет текущий commit, а не доказательства со старого SHA.

## Документация

Главная точка входа: [`docs/README.md`](docs/README.md).

Оттуда документация разделена на:

- пользовательскую и административную;
- эксплуатационную;
- архитектурную;
- план развития;
- релизные материалы;
- исторический архив.

Актуальный план: [`docs/ROADMAP.md`](docs/ROADMAP.md). Идеи без назначенной версии: [`docs/PRODUCT_BACKLOG.md`](docs/PRODUCT_BACKLOG.md).

## 1.0 release readiness

Линия 1.0 функционально завершена на **v1.0.14** и находится в режиме сопровождения.

Закрытые платформенные и эксплуатационные контракты:

- vendor-free runtime без обязательного Composer/`vendor`;
- изолированный runtime модулей и composition-aware lifecycle;
- явные лицензионные `workspace.*` entitlement;
- signed updater с staging, backup, transactional apply, durable recovery и rollback кода/БД;
- production license/update Ed25519 keypairs; в customer bundle находятся только публичные trust roots;
- structured security observability;
- nonce-based CSP без `unsafe-inline`;
- explicit retention/permanent-purge contract;
- GitHub branch protection/ruleset и обязательные release checks;
- browser lifecycle основных модулей;
- кросс-браузерная и мобильная проверка;
- Windows/OSPanel и Long Poll/WebSocket контракты;
- модульное включение/отключение без потери данных.

Опубликованный `v1.0.13` выявил дефект реального web-only обновления и снят с канала доставки. `v1.0.14` проходит исправляющую приёмку; до публикации должен быть полностью зелёным путь `1.0.12 → 1.0.14`, включая rollback/recovery на ограниченном виртуальном хостинге.

Новые 1.0.x выпускаются только при обнаружении дефектов или необходимости небольшой совместимой доработки текущей функциональности.

Следующая продуктовая линия: **1.1 — Календарь и ежедневник**.

Актуальный статус: [`docs/RELEASE_STATUS_1.0.md`](docs/RELEASE_STATUS_1.0.md). Финальная релизная матрица: [`docs/RELEASE_ACCEPTANCE.md`](docs/RELEASE_ACCEPTANCE.md).


### Быстрые ссылки по архитектуре и использованию

- Ядро: [docs/CORE.md](docs/CORE.md)
- Руководство пользователя: [docs/USER_GUIDE.md](docs/USER_GUIDE.md)
