# Системные требования Workspace Organizer

Актуально для стабильной линии **1.0.13**.

## PHP

Технический минимум линии 1.0.x: **PHP 8.1+**.

Для нового публичного production-развёртывания рекомендуется поддерживаемая upstream-ветка PHP; текущая рекомендация проекта — **PHP 8.3+**.

Обязательные расширения:

- `mysqli`;
- `pdo_mysql`;
- `mbstring`;
- `ctype`;
- `fileinfo`;
- `sodium`;
- `openssl`;
- `zlib`;
- `gd`.

Также требуются рабочие PHP-сессии, upload temp, `flock`, атомарное переименование файлов, `getenv`/`putenv` и Argon2id в `password_hash()`.

## База данных

Поддерживаются:

- MySQL 8.0+;
- MariaDB 10.5+.

CI линии 1.0 проверяет MySQL 8.4 и MariaDB 10.11.

## Web-сервер и storage

Нужны:

- Apache с `mod_rewrite` либо Nginx/эквивалентный reverse proxy;
- writable `PRIVATE_STORAGE_PATH` вне document root;
- HTTPS для production;
- возможность записывать в каталог приложения во время установки и обновления.

## Composer и vendor

Runtime 1.0.13 не зависит от Composer и каталога `vendor/`. Готовый GitHub Release и исходный tree запускаются встроенным autoload/runtime.

Composer допускается как инструмент разработки, но не является требованием production.

## Messenger

Полный функционал Messenger работает через authenticated HTTP Long Poll.

WebSocket — необязательный ускоритель. Для него нужны:

- PHP CLI той же поддерживаемой версии;
- возможность держать долгоживущий PHP-процесс;
- WebSocket endpoint или reverse proxy;
- на Unix для daemon/process-control режима рекомендуется `pcntl`.

Отсутствие WebSocket не должно блокировать остальные модули или основные функции Messenger.

## Windows / OSPanel

Windows/Open Server поддерживается как локальная и тестовая среда. Для Internet-facing production рекомендуемый baseline — Linux.

Подробности:

- [DEPLOYMENT_COMPATIBILITY.md](DEPLOYMENT_COMPATIBILITY.md)
- [HOSTING_INSTALL.md](HOSTING_INSTALL.md)
- [OPEN_SERVER_WEBSOCKET.md](OPEN_SERVER_WEBSOCKET.md)
- [SHARED_HOSTING_COMPATIBILITY.md](SHARED_HOSTING_COMPATIBILITY.md)

## Следующие линии

Минимальная версия PHP для будущих минорных линий определяется дорожной картой и должна быть проверена до релиз-кандидата. Patch-релизы внутри одной линии не повышают системный минимум.
