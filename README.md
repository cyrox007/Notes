# Workspace Organizer

**Версия:** `0.10.0-alpha`  
**Дата:** 13 сентября 2026  
**Статус:** active alpha / security & contract hardening

Workspace Organizer — внутреннее PHP-приложение для корпоративной работы: заметки, задачи, личные файлы, профиль, администрирование и real-time Messenger.

## Что уже есть

- **Notes** — зашифрованный текст, вложения, голосовые заметки, публичные view-only ссылки.
- **Tasks** — задачи, статусы, приоритеты, сроки, подзадачи и категории.
- **File Manager** — личные папки/файлы с private storage и авторизованной выдачей.
- **Messenger v2** — private/group chats, media, voice, reply/edit/delete, delivery/read, reactions, search, pin/mute/archive, group roles/avatars и multi-device realtime.
- **Profile** — данные пользователя, пароль и account actions.
- **Admin panel** — управление пользователями и custom profile fields.

Messenger для стартовой корпоративной версии считается функционально достаточным; основной roadmap теперь направлен на согласование Notes/Tasks и production hardening.

## Security model

Ключевые принципы текущей версии:

- passwords — `password_hash` / Argon2id;
- Notes text — `UNIQUE_KEY` + XChaCha20-Poly1305;
- Messenger text/captions — отдельный `MSG_SECRET_KEY` + versioned XChaCha20-Poly1305 payload;
- crypto failures — fail-closed, plaintext fallback для новых encrypted данных запрещён;
- File Manager, Messenger media и hardened Notes attachments находятся вне document root;
- upload MIME проверяется сервером через `finfo` + allowlist;
- protected files выдаются только через ACL endpoints;
- POST actions проходят CSRF policy;
- WebSocket identity определяется socket ticket на сервере, а не client UID;
- WebSocket actions и origins находятся в allowlist.

> Важно: server-side encryption at rest Messenger не является end-to-end encryption. Notes attachment bytes в текущем hardening-пакете также пока не шифруются at-rest; для них используется private filesystem + ACL и truthful `is_encrypted=0`.

## Требования

- PHP `8.3+`;
- MySQL `8.x` / совместимая MariaDB для основного production path;
- Composer;
- PHP extensions: `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`;
- Apache + mod_rewrite или Nginx с эквивалентным front-controller routing;
- writable private directory вне document root;
- TLS/WSS для production.

## Быстрый запуск

### 1. Dependencies

```bash
composer install
```

### 2. Environment

```bash
cp default.env .env
```

Минимально задайте реальные секреты и подключение к БД:

```env
DBDRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBUSER=workspace
DBPASS=<strong-db-password>
DBNAME=workspace

SITEURL=https://workspace.example.com
BASE_PATH=/

UNIQUE_KEY=<random-secret-at-least-32-chars>
MSG_SECRET_KEY=<random-secret-at-least-32-chars>
WS_TICKET_SECRET=<random-secret-at-least-32-chars>

PRIVATE_STORAGE_PATH=/var/lib/notes/private
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
```

Секреты удобно сгенерировать, например:

```bash
openssl rand -hex 32
```

Не коммитьте `.env`.

### 3. Private storage

Создайте каталог вне web root и выдайте PHP-процессу минимально необходимые права на запись:

```text
/var/lib/notes/private/
├── file_manager/
├── messenger/
└── notes/
```

Для Notes canonical attachment root — `PRIVATE_STORAGE_PATH/notes/`. Физический путь хранится только на сервере и никогда не используется как browser URL.

Не настраивайте эти каталоги как static/public locations веб-сервера.

### 4. База данных

Fresh-install schema, которая присутствует в репозитории:

```bash
mysql -u root -p workspace < database/messenger_schema.sql
mysql -u root -p workspace < database/notes_schema.sql
mysql -u root -p workspace < database/file_manager_schema.sql
mysql -u root -p workspace < database/user_fields_schema.sql
```

Для существующих установок дополнительно применяются нужные файлы из `database/migrations/`.

**Tasks:** модуль работает в существующих установках, но отдельный canonical `tasks_schema.sql` на текущий момент отсутствует. Согласование Task models/schema/routes — следующий блок аудита; не считайте fresh-install Tasks contract завершённым до соответствующего PR.

### 5. HTTP routing

Для Apache корневой `.htaccess` уже содержит hardening/routing policy. Для Nginx запросы, которые не соответствуют реальному static asset, должны уходить в `index.php`, а private storage не должен находиться внутри server root.

### 6. WebSocket

Запуск Workerman:

```bash
php ws_server/server.php start
```

В production запускайте процесс через systemd/supervisor/container orchestration и публикуйте его только через WSS/reverse proxy.

## Основные URL

```text
/                    главная
/auth/login          вход
/notes/              заметки
/tasks/              задачи
/files/              личные файлы
/messenger/          Messenger
/profile/            профиль
/admin/              admin panel
```

Полный route contract находится в `core/routerConfig.php`.

## Notes

Текст Notes шифруется через `CryptMethods` с UID заметки как AAD.

Hardened attachment flow:
- upload: `POST /notes/upload/{uid}`;
- owner read: `GET /notes/attachment/{fileUid}`;
- delete: `POST /notes/attachment/delete/{id}`;
- create view share: `POST /notes/share/{uid}`;
- disable share: `POST /notes/unshare/{uid}`;
- public view: `GET /notes/shared/{token}`;
- shared attachment: `GET /notes/shared/{token}/attachment/{fileUid}`.

Физический `file_path` никогда не является browser URL.

## Messenger

На `master` уже находятся:
- socket-ticket auth/origin/action allowlists;
- private/group dialogs;
- reply/edit/delete-for-me/delete-for-all;
- explicit delivered/read cursors;
- private media + byte-range streaming;
- multi-device events;
- pin/mute/archive;
- owner/admin/member group management;
- private group avatars;
- media replies и orphan cleanup;
- bounded encrypted search;
- browser voice recording/player;
- realtime reactions.

Forwarding + «Сохранённые сообщения» находятся в отдельном PR #50 и не считаются частью `master`, пока PR не слит.

## Tests / CI

GitHub Actions проверяют комбинацию:
- Composer validate/install/audit;
- PHP syntax;
- Messenger JavaScript syntax;
- clean-install schema contracts;
- crypto fail-closed behavior;
- service/integration tests;
- отдельные Messenger groups/media/search/voice/reactions workflows.

Новый security-sensitive функционал должен сопровождаться integration test, а не только lint.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история версий и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — архитектура ядра, Router/Request/DB/Crypto/WebSocket/private storage.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — функции сайта и пользовательские сценарии.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — историческая документация модуля Tasks; её contract будет пересмотрен на следующем этапе аудита.
- [`default.env`](default.env) — актуальные environment variables с комментариями.

## Roadmap после Messenger

1. Notes: private attachments, sharing/ACL, truthful crypto/storage contract — **текущий этап**.
2. Tasks: canonical schema + models/routes/subtasks/categories + per-screen sort allowlists.
3. Profile/User residual contract и storage policy для avatar.
4. Legacy crypto/data migration и удаление временных compatibility paths.
5. Production readiness: runtime smoke, deployment/WSS, permissions, backup/restore, rate limits, observability и key rotation procedures.

## Обновление существующей установки

Перед обновлением:
1. сделайте backup БД;
2. сделайте backup `PRIVATE_STORAGE_PATH`;
3. сохраните действующие crypto keys;
4. примените миграции на staging;
5. прогоните GitHub Actions/runtime smoke;
6. только после этого обновляйте production.
