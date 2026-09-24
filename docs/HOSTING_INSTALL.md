# Установка Workspace Organizer на обычный хостинг

Цель 1.0 fresh-install сценария: **не требовать SSH, Composer, ручного импорта SQL или ручного создания `.env` на сервере**.

Рекомендуемый способ — использовать готовый hosting bundle из GitHub Release. ZIP содержит весь внутренний runtime приложения и web-installer; каталога `vendor/` в 1.0 bundle нет и он не нужен.

## Что нужно от хостинга

Минимум:

- PHP 8.1+; для публичного production рекомендуется поддерживаемая ветка PHP, сейчас 8.3+;
- MySQL 8.x;
- extensions `mysqli`, `pdo_mysql`, `mbstring`, `sodium`, `fileinfo`, `gd`;
- Argon2id в `password_hash`;
- возможность PHP записывать в каталог приложения во время установки;
- возможность PHP создать private storage вне document root;
- Apache `mod_rewrite` либо эквивалентный routing в Nginx/панели;
- для Messenger fallback — обычные длительные HTTP requests и достаточная параллельность PHP workers;
- для рекомендуемого низколатентного WebSocket fast path — PHP CLI, возможность держать долгоживущий native PHP process и WebSocket reverse proxy `/ws`.

Composer, Smarty и Workerman для runtime не требуются.

Если тариф не позволяет long-running process/WebSocket proxy, Messenger автоматически работает через authenticated HTTP long poll. WebSocket рекомендуется включать при возможности: он уменьшает задержку и нагрузку на PHP workers.

## Fresh install без CLI

1. Скачайте ZIP `workspace-organizer-v*.zip` из GitHub Release.
2. Загрузите и распакуйте его, например в `public_html/workspace`.
3. Создайте MySQL пользователя/базу в панели, если тариф не разрешает приложению `CREATE DATABASE`.
4. Откройте:

```text
https://example.com/workspace/install.php
```

5. Installer проверит PHP/extensions, встроенный core runtime, native WebSocket runtime, writable runtime dirs и private storage.
6. Укажите MySQL credentials. Мастер определит/создаст `SITEURL`, `BASE_PATH`, `WS_PUBLIC_URL`, `WS_ALLOWED_ORIGINS`, private storage и секреты. На Windows/OpenServer с layout `domains\\...` и HTTP installer автоматически предлагает direct-host профиль вида `ws://notes.local:27800`, чтобы Messenger можно было тестировать без reverse proxy. Для HTTPS остаётся `wss://.../ws` через proxy.
7. Создайте первого администратора.
8. После успешного завершения `.env` блокирует повторный доступ к installer.

## Что installer делает автоматически

- при наличии MySQL privilege создаёт отсутствующую БД;
- импортирует canonical schemas и проверяет текущий contract из **34 обязательных таблиц**;
- создаёт RBAC + `role_module_policies`, shared task boards, settings/quota и module lifecycle schema;
- создаёт private storage вне document root;
- создаёт пространства `file_manager`, `messenger`, `notes`, `users`, `rate-limit`, `logs`, `legacy`;
- создаёт writable runtime dirs `cache`/`compile`;
- генерирует отдельные `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET`;
- записывает `WS_MAX_CONNECTIONS` и `WS_MAX_PAYLOAD_BYTES` для native WebSocket runtime;
- формирует production `.env` с `LOG_LEVEL=INFO`;
- создаёт первого superadmin и каноническую RBAC assignment;
- учитывает установку в подкаталог;
- выставляет same-site WebSocket URL вида `/ws`;
- не создаёт `.env` до успешной финализации admin account.

## Если база не существует

Installer сначала пробует создать её сам. Если MySQL-пользователь не имеет `CREATE DATABASE`, создайте пустую БД в панели хостинга и повторите шаг установки.

Не импортируйте SQL вручную — web-installer делает это сам.

## Private storage

Типичный shared hosting:

```text
/home/account/public_html/workspace
```

Private storage должен находиться выше web-root, например:

```text
/home/account/.workspace-organizer-private-<id>
```

Если hosting запрещает PHP запись вне `public_html`, такой тариф не соответствует security contract проекта.

## Realtime Messenger: WebSocket + HTTP fallback

Installer записывает примерно:

```env
WS_PUBLIC_URL=wss://example.com/workspace/ws
WS_ALLOWED_ORIGINS=https://example.com
WS_HOST=127.0.0.1
WS_PORT=27800
WS_MAX_CONNECTIONS=256
WS_MAX_PAYLOAD_BYTES=2097152
```

Web-installer не может универсально запустить долгоживущий процесс на любой панели. Messenger после web-install уже может работать через HTTP long poll; для рекомендуемого WebSocket fast path process запускается отдельно:

```bash
php ws_server/server.php check
php ws_server/server.php start
```

Проверка:

```bash
php ws_server/server.php status
php bin/ws_doctor.php
```

В production при включённом WebSocket встроенный native server должен работать под process manager с automatic restart, а браузер подключается через `wss://` reverse proxy, не напрямую к `27800`. Если WS недоступен, клиент автоматически переключается на `/messenger/realtime/poll` и `/messenger/realtime/action`, продолжает durable-синхронизацию и в фоне пытается вернуть WebSocket.

Fallback использует те же Messenger handlers, RBAC/role policies, maintenance и license gates. Long-poll request освобождает PHP session lock; `MESSENGER_LONG_POLL_TIMEOUT_SECONDS` по умолчанию равен 15 секундам (5–25).

Полная инструкция — `docs/MESSENGER_SERVER.md`. Для Open Server — `docs/OPEN_SERVER_WEBSOCKET.md`.

Если панель предлагает **Background processes / Supervisor / WebSocket / Reverse proxy**, используйте их для `php ws_server/server.php start` и проксирования публичного `/ws` на `127.0.0.1:27800`.

## Upgrade существующей установки

Web-installer предназначен только для fresh install. Он **не нужен** для обновления поверх уже работающей установки и не должен использоваться как способ обновить существующую БД.

Если новая версия распаковывается поверх старой вручную, сразу после замены файлов и **до открытия приложения в браузере** выполните compatibility migration runner:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
php bin/healthcheck.php --json
```

Начиная с 1.0.3 HTTP-runtime сам проверяет фактический контракт схемы. Если файлы уже новые, а БД ещё старая, приложение отвечает диагностическим HTTP 503 «Требуется обновление базы данных» вместо случайных 500 на отдельных страницах. После успешной миграции достаточно обновить страницу.

Перед upgrade обязательны backup БД, private storage и crypto keys.

## Как собирается hosting bundle

Workflow `Build hosting package`:

- проверяет vendor-free runtime без `vendor/`;
- запускает native view/RFC6455 contracts;
- собирает ZIP без `.env`, `.git`, `.github`, logs, private storage и `vendor/`;
- проверяет наличие `install.php`, `NativeViewRenderer` и `NativeMessengerServer`;
- для tag `v*` прикладывает ZIP к GitHub Release;
- для ручного запуска сохраняет ZIP как Actions artifact.

То есть конечный сервер получает self-contained PHP runtime проекта без сторонних Composer packages.
