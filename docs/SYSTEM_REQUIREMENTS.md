# Системные требования Workspace Organizer

Актуально для стабильной линии **1.0.16** и разрабатываемой линии **1.1**.

## PHP

Технический минимум стабильной линии 1.0.x: **PHP 8.1+**.

Технический минимум линии 1.1 и будущего релиза 1.1.0: **PHP 8.2+**. Код ветки `1.1` может использовать синтаксис и возможности PHP 8.2 и не обязан запускаться на PHP 8.1.

Для нового публичного production-развёртывания рекомендуется поддерживаемая upstream-ветка PHP; текущая рекомендация проекта — **PHP 8.3+** при сохранении обязательной проверки минимальной версии 8.2.

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

CI проверяет основной MySQL-контур; совместимость с MariaDB остаётся частью эксплуатационной приёмки.

## Web-сервер и хранилище

Нужны:

- Apache с `mod_rewrite` либо Nginx/эквивалентный reverse proxy;
- доступный для записи `PRIVATE_STORAGE_PATH` вне document root;
- HTTPS для production;
- возможность записывать в каталог приложения во время установки и обновления.

## Composer и vendor

Runtime не зависит от Composer и каталога `vendor/`. Готовый GitHub Release и исходный tree запускаются встроенным autoload/runtime.

Composer допускается как инструмент разработки и фиксирует минимальную версию PHP, но не является требованием production.

## Messenger

Полный функционал Messenger работает через аутентифицированный HTTP Long Poll.

WebSocket — необязательный ускоритель. Для него нужны:

- PHP CLI той же поддерживаемой версии;
- возможность держать долгоживущий PHP-процесс;
- WebSocket endpoint или reverse proxy;
- на Unix для режима управления процессом рекомендуется `pcntl`.

Отсутствие WebSocket не должно блокировать остальные модули или основные функции Messenger.

## Windows / OSPanel

Windows/Open Server поддерживается как локальная и тестовая среда. Для Internet-facing production рекомендуемый baseline — Linux.

Для 1.1 выбранный PHP-профиль OSPanel должен быть **8.2 или новее**. Перед обновлением с 1.0.x проверяется доступность требуемых расширений и переключение PHP без потери конфигурации сайта.

Подробности:

- [DEPLOYMENT_COMPATIBILITY.md](DEPLOYMENT_COMPATIBILITY.md)
- [HOSTING_INSTALL.md](HOSTING_INSTALL.md)
- [OPEN_SERVER_WEBSOCKET.md](OPEN_SERVER_WEBSOCKET.md)
- [SHARED_HOSTING_COMPATIBILITY.md](SHARED_HOSTING_COMPATIBILITY.md)

## Правило минорных линий

Patch-релизы внутри 1.0.x не повышают системный минимум. Повышение PHP до 8.2 выполняется только в новой минорной линии 1.1 и сопровождается полным рефакторингом и отдельной проверкой перехода с последней 1.0.x.

План работ: [plans/1.1-php82-refactor.md](plans/1.1-php82-refactor.md).
