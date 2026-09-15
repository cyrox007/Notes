# Workspace Organizer 0.14 — системные требования

Этот документ фиксирует **проверенный технический минимум** платформы и отдельно отличает его от рекомендуемой production-конфигурации.

## 1. PHP runtime

### Технический минимум

**PHP 8.1+**.

Это не условная цифра из документации, а фактическая нижняя граница текущего исходного кода:

- ядро использует PHP enums (`enum LogLevel`) — PHP 8.1;
- module platform использует `readonly` properties — PHP 8.1;
- module manifest validation использует `array_is_list()` — PHP 8.1;
- startup error boundary использует return type `never` — PHP 8.1.

Поэтому PHP 8.0 и ниже не являются поддерживаемыми даже если отдельные legacy-файлы на них синтаксически совместимы.

Current Composer lock также не поднимает floor выше 8.1: зафиксированные Workerman, Smarty и phpdotenv поддерживают более старые PHP ветки, поэтому нижнюю границу задаёт именно код Workspace Organizer, а не vendor dependencies.

### Production recommendation

PHP 8.1 является **compatibility floor**, а не рекомендацией для нового Internet-facing deployment. Для production следует использовать поддерживаемую upstream ветку PHP; для текущего 0.14 baseline рекомендуется **PHP 8.3+**.

CI обязан доказывать совместимость с 8.1 отдельно от основного latest/stable пути. Нельзя снова повышать заявленный minimum без конкретной несовместимости и failing compatibility test.

## 2. Обязательные PHP extensions для web-платформы

- `mysqli`;
- `pdo_mysql`;
- `mbstring`;
- `sodium`;
- `fileinfo`;
- `gd`;
- Argon2id support в `password_hash()`.

`json` является частью современного PHP runtime и отдельно как optional extension не рассматривается.

## 3. Realtime Messenger / Workerman

Realtime Messenger имеет дополнительные требования, которые **не должны искусственно блокировать установку остальных модулей**:

- PHP CLI той же поддерживаемой версии (technical minimum 8.1+);
- POSIX-compatible production OS;
- PHP extensions `pcntl` и `posix`;
- возможность держать long-running process;
- reverse proxy с WebSocket Upgrade;
- production browser traffic через WSS;
- `ext-event`/аналогичный event backend — optional performance improvement, не базовый hard requirement.

Если hosting не предоставляет `pcntl`/`posix`, background process или WebSocket proxy, Notes/Tasks/Files/Profile/Admin могут оставаться совместимыми, но Messenger realtime runtime должен считаться недоступным/degraded.

Это важно для 0.14 module platform: системные требования должны вычисляться по **активной композиции модулей**, а не быть одним глобальным списком на все возможные поставки.

## 4. Database

Поддерживаемый и проверяемый production path — **MySQL 8.x**. CI использует MySQL 8.4 для installer, migrations, data-integrity и browser/runtime contracts.

MariaDB/MySQL 5.x не объявляются совместимыми без отдельной compatibility matrix.

## 5. Web server / storage

Обязательно:

- Apache + `mod_rewrite` либо Nginx/другой reverse proxy с эквивалентным front-controller routing;
- writable `compile` и `cache`;
- writable `PRIVATE_STORAGE_PATH` вне document root и application root;
- для production — HTTPS;
- права файлов/каталогов, позволяющие сохранять private data без public static exposure.

Для установки из готового GitHub Release Composer на hosting не требуется. При deploy из source tree требуется Composer 2 и успешный `composer install --no-dev --optimize-autoloader`.

## 6. Compatibility proof

0.14 вводит два уровня проверки:

1. `PHP runtime compatibility` — matrix PHP 8.1 / 8.2 / 8.3, Composer platform requirements, полный PHP lint и core/module security contracts.
2. `Hosting installer` — настоящий HTTP fresh install + MySQL + generated `.env` + final healthcheck выполняется на **PHP 8.1**, то есть на минимально заявленной версии.

Основные security/browser workflows могут продолжать работать на PHP 8.3 как production reference environment. Это не отменяет minimum-version gate.

## 7. Правило для модулей 0.14+

Каждый изолированный модуль должен в manifest декларировать дополнительные runtime requirements, если они выходят за core baseline: PHP extensions, OS/process capabilities, storage/network capabilities и внешние services.

Module registry/update resolver обязан блокировать включение или обновление несовместимого модуля **до исполнения его кода**. Отсутствие optional runtime capability не должно ломать unrelated modules.
