# Установка Workspace Organizer на обычный хостинг

Цель fresh-install сценария: **не требовать SSH, Composer, ручного импорта SQL или ручного создания `.env` на сервере**.

Рекомендуемый способ — использовать готовый hosting bundle из GitHub Release. Такой ZIP уже содержит production `vendor/` и web-installer.

## Что нужно от хостинга

Минимум:

- PHP 8.1+ как технический минимум; для публичного production рекомендуется поддерживаемая ветка PHP, сейчас 8.3+;
- MySQL 8.x;
- extensions `mysqli`, `pdo_mysql`, `mbstring`, `sodium`, `fileinfo`, `gd`;
- Argon2id в `password_hash`;
- возможность PHP записывать в каталог приложения во время установки;
- возможность PHP создать private каталог вне document root;
- Apache `mod_rewrite` либо эквивалентный routing в панели/Nginx;
- для realtime Messenger — POSIX-compatible host, PHP CLI extensions `pcntl` и `posix`, возможность держать долгоживущий PHP/Workerman process и проксировать `/ws`.

Если тариф не позволяет long-running processes/WebSocket proxy, остальные web-модули устанавливаются, но realtime Messenger нельзя считать полностью развёрнутым на таком тарифе.

## Fresh install без CLI

1. Скачайте ZIP `workspace-organizer-v*.zip` из GitHub Release.
2. Загрузите и распакуйте его в нужный каталог сайта, например `public_html/workspace`.
3. Создайте MySQL пользователя/базу в панели хостинга, если тариф не разрешает приложению `CREATE DATABASE`.
4. Откройте в браузере:

```text
https://example.com/workspace/install.php
```

5. Installer сам проверит PHP/extensions, `vendor/`, writable runtime dirs и путь private storage.
6. Укажите MySQL credentials. Остальные значения мастер определяет автоматически:
   - `SITEURL`;
   - `BASE_PATH`;
   - `WS_PUBLIC_URL`;
   - `WS_ALLOWED_ORIGINS`;
   - private storage path;
   - crypto/WebSocket secrets.
7. Создайте первого администратора.
8. После успешного завершения `.env` блокирует повторный доступ к installer.

## Что installer делает автоматически

- при наличии MySQL privilege пытается создать отсутствующую БД;
- импортирует 6 canonical schema-файлов и проверяет 22 обязательные таблицы;
- создаёт `system_settings` и `user_storage_quotas` для системного лимита File Manager и персональных quota overrides;
- создаёт private storage вне document root;
- создаёт внутри него `file_manager`, `messenger`, `notes`, `users`, `rate-limit`, `logs`, `legacy`;
- создаёт `cache` и `compile`;
- генерирует отдельные `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET`;
- формирует production `.env` с `LOG_LEVEL=INFO`;
- создаёт первого superadmin;
- учитывает установку приложения в подкаталог;
- выставляет same-site WebSocket URL вида `/ws`;
- не создаёт `.env` до успешной финализации admin account.

Фактический объём занятого File Manager storage не хранится отдельным счётчиком: он вычисляется из canonical `user_files`. Это исключает рассинхронизацию usage counter после удаления или восстановления файлов. Квота проверяется до записи файла, а параллельные загрузки одного пользователя сериализуются на время проверки и записи.

## Если база не существует

Installer сначала пробует создать её сам. На shared hosting это часто запрещено MySQL-пользователю. В таком случае это **единственное обязательное действие в панели хостинга**: создайте пустую БД и пользователя, затем повторите шаг 2.

Не импортируйте SQL вручную — web-installer сделает это сам.

## Private storage

На типичном shared hosting приложение расположено примерно так:

```text
/home/account/public_html/workspace
```

Installer попытается создать private storage примерно так:

```text
/home/account/.workspace-organizer-private-<id>
```

То есть browser не сможет получить private files как static content.

Если hosting запрещает запись вне `public_html`, такой тариф не соответствует security contract проекта. Не размещайте `PRIVATE_STORAGE_PATH` внутри web root ради обхода этой проверки.

## WebSocket / realtime Messenger

Installer автоматически записывает:

```env
WS_PUBLIC_URL=wss://example.com/workspace/ws
WS_ALLOWED_ORIGINS=https://example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

**Установка web-части сама по себе не запускает realtime Messenger.** После installer необходимо отдельно запустить долгоживущий Workerman process и настроить reverse proxy `/ws` на локальный `WS_PORT`.

Быстрый ручной запуск из корня приложения:

```bash
php ws_server/server.php start
```

Для проверки:

```bash
php ws_server/server.php status
```

В production Workerman должен работать под process manager с автоматическим restart, а browser должен подключаться через `wss://`, не напрямую к `27800`.

Полная инструкция — переменные `.env`, Nginx `/ws`, systemd, Supervisor, shared hosting, диагностика `502`/Origin/ticket и порядок рестарта после deploy — находится в:

**[`docs/MESSENGER_SERVER.md`](MESSENGER_SERVER.md)**

Если панель хостинга предлагает разделы вроде **Background processes / Supervisor / WebSocket / Reverse proxy**, используйте их для запуска Workerman и проксирования публичного `/ws` на `127.0.0.1:27800`.

Если тариф не позволяет long-running process и WebSocket reverse proxy, Notes/Tasks/Files/Profile работают, но realtime Messenger на таком тарифе полноценно не поддерживается.

## Upgrade существующей установки

Web-installer предназначен **только для fresh install**. Если в БД обнаружена старая или неполная схема, он не изменяет существующие данные.

Upgrade выполняется compatibility upgrade runner:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

`database/*_schema.sql` остаются canonical схемой fresh install. `bin/migrate.php` применяется только к существующей установке и ведёт filename/checksum ledger уже применённых compatibility SQL.

Перед upgrade обязательны backup БД, private storage и crypto keys.

## Как собирается hosting bundle

Workflow `Build hosting package`:

- ставит Composer dependencies на CI;
- добавляет production `vendor/` в ZIP;
- исключает `.env`, `.git`, `.github`, logs и локальные runtime-файлы;
- для tag `v*` прикладывает ZIP к GitHub Release;
- для ручного запуска сохраняет ZIP как Actions artifact.

Это специально сделано так, чтобы Composer не требовался на конечном shared hosting.
