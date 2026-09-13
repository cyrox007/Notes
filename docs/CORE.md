# Workspace Organizer — документация ядра

Актуально для `0.10.0-alpha` и ветки hardening от 13.09.2026.

## 1. Точка входа и загрузка приложения

HTTP-запрос приходит в `index.php`. Он определяет `SITEPATH`, настраивает PHP error log, подключает `core.php`, затем `core/Router.php` и `core/routerConfig.php`.

`core.php`:
- подключает Composer autoload, если установлен `vendor/autoload.php`;
- загружает `.env` через `vlucas/phpdotenv`;
- регистрирует project autoload по namespace/path;
- подключает базовые классы из `core/`;
- рекурсивно загружает `app/models`, `app/services`, `app/controllers`, `app/socket`, `app/handlers`, `app/middlewares`.

Новые сервисы рекомендуется помещать в `app/services`, HTTP-контроллеры — в `app/controllers`, WebSocket handlers — в `app/socket`.

## 2. Router

Конфигурация маршрутов находится в `core/routerConfig.php`. Router — singleton: `Router::getInstance()`.

Базовая регистрация:

```php
$router->add(
    'GET',
    '/profile/',
    [ProfileController::class, 'index'],
    [LoginRequared::class],
    'profile'
);
```

Группы:

```php
$router->group('/notes')
    ->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes')
    ->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create')
    ->endGroup();
```

Поддерживаемые динамические параметры:
- `{int:id}` → `\d+` и передаётся контроллеру как `int`;
- `{str:uid}` → `[\w-]+`, поэтому подходит в том числе для UUID с дефисами.

Router нормализует пути с завершающим `/`, сравнивает HTTP method, выполняет middleware по порядку и затем вызывает controller method, передавая `Request` первым аргументом.

Именованные маршруты используются через `Router::redirect()` и Smarty helper `{route_path ...}`.

## 3. Request

`Core\Request` создаётся на каждый HTTP request и инкапсулирует:
- GET;
- POST;
- FILES;
- SERVER;
- JSON body;
- session.

Основные методы:

```php
$request->get('sort', 'created_at');
$request->post('name', '');
$request->json('action');
$request->session('user_id');
$request->setSession('key', $value);
$request->hasFile('file');
$request->file('file');
```

JSON/string input проходит HTML escaping в `Request::sanitize()`. Это не заменяет domain validation: ID, enum, длины, MIME, ownership и business rules должны проверяться отдельно.

Для `sort` и `direction` существует общий syntax barrier, но экраны, где набор сортируемых колонок известен, должны дополнительно использовать собственный allowlist колонок.

## 4. Controller и Smarty

`Core\Controller`:
- создаёт `Request`;
- инициализирует Smarty;
- включает HTML escaping;
- регистрирует template helpers;
- применяет `CSRFMiddleware` ко всем POST request, проходящим через Controller.

Smarty helpers:
- `{route_path name="..." ...}`;
- `{csrf_token}`;
- `{session key="..."}`;
- `{jsonParse ...}`.

Рендеринг:

```php
$this->render_template('notes_page/edit_view', [
    'note' => $note,
]);
```

В каждый template автоматически передаются `base_url`, `sitename`, `version`, `product_name`.

Не отключайте escaping для пользовательского текста без необходимости. HTML/JS generation из пользовательских полей не допускается.

## 5. Middleware

В `app/middlewares` находятся:
- `LoginRequared` — требует authenticated session/active user для закрытых страниц;
- `IsAdmin` — дополнительная проверка admin routes;
- `CSRFMiddleware` — защита state-changing HTTP requests.

Правило: ownership/participant ACL не заменяются middleware. Middleware отвечает за класс доступа к endpoint, а конкретный Controller/Service обязан проверять, имеет ли этот пользователь право на конкретную note/file/dialog/message.

## 6. DatabaseManager и ORM

В проекте одновременно используются два уровня доступа к БД.

### ORM

`Core\ORM` используется model-классами и предоставляет fluent query API (`select`, `where`, joins, grouping, ordering, `first`, `get` и т. п.). ORM удобен для обычных CRUD/read paths.

### DatabaseManager

`Core\DatabaseManager` используется для:
- parameterized SQL;
- транзакций;
- batch/queue операций;
- сложных запросов и сервисов, где SQL-контракт должен быть явным.

В security-sensitive сервисах Messenger/Notes предпочтителен явный parameterized SQL, если ORM делает ACL или locking неочевидными.

Никогда не подставляйте пользовательские значения в SQL identifier/order fragments без allowlist.

## 7. Crypto contract

### Notes/application data

`App\Helpers\CryptMethods`:
- master secret: `UNIQUE_KEY`;
- минимум 32 символа;
- key derivation через HKDF-SHA256;
- cipher: libsodium XChaCha20-Poly1305;
- поддерживает AAD, для Notes в качестве AAD используется UID заметки;
- ошибки преобразуются в `CryptographicFailure` и должны оставаться fail-closed.

Нельзя сохранять plaintext, если операция была объявлена encrypted.

### Messenger

Messenger использует отдельный `MSG_SECRET_KEY` и отдельный crypto compatibility layer. Это server-side encryption at rest, не end-to-end encryption.

Legacy AES-CBC допускается только как временный read path при явно заданном `MSG_LEGACY_SECRET_KEY`; новые записи должны использовать современный versioned payload.

## 8. Private storage

Корневая директория задаётся:

```env
PRIVATE_STORAGE_PATH=/var/lib/notes/private
```

Она обязана находиться вне document root.

Текущая раскладка:

```text
PRIVATE_STORAGE_PATH/
├── file_manager/
├── messenger/
└── notes/
```

Принцип выдачи файлов:
1. browser получает opaque ID/UID, а не physical path;
2. endpoint заново проверяет session/share/membership ACL;
3. realpath обязан оставаться внутри разрешённого storage root;
4. сервер выставляет `X-Content-Type-Options: nosniff`;
5. media endpoints могут поддерживать byte ranges;
6. клиентский MIME не считается доверенным — upload проверяется `finfo` + extension allowlist.

Notes attachment bytes в версии 0.10 пока не шифруются at-rest. Их `is_encrypted=0` — это намеренный truthful contract. Текст Notes при этом шифруется.

## 9. WebSocket / Messenger

WebSocket entry point: `ws_server/server.php`.

Security contract:
- browser сначала получает short-lived socket ticket через authenticated HTTP endpoint;
- `SocketTicket` привязывает соединение к user identity на сервере;
- client-supplied `user_uid/user_id/from_user_id` удаляются из payload;
- origin сверяется с `WS_ALLOWED_ORIGINS`;
- вызываемые `Socket:method` находятся в явном `$allowedRoutes` allowlist;
- один пользователь может иметь несколько physical connections, события fan-out отправляются всем его активным соединениям.

Socket handler должен быть тонким: authorization/business logic хранится в service-классе, socket отвечает за validation transport payload и broadcast.

## 10. Environment

Минимально важные production variables:

```env
DBDRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBUSER=...
DBPASS=...
DBNAME=...

SITEURL=https://workspace.example.com
BASE_PATH=/

UNIQUE_KEY=<random secret >=32 chars>
MSG_SECRET_KEY=<random secret >=32 chars>
WS_TICKET_SECRET=<random secret >=32 chars>

PRIVATE_STORAGE_PATH=/var/lib/notes/private
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
```

Полный список и комментарии находятся в `default.env`.

## 11. Миграции

Fresh-install source of truth находится в `database/*_schema.sql`. Для уже существующей БД применяются SQL-файлы из `database/migrations/` в хронологическом порядке, относящиеся к установленной версии.

Перед миграцией production:
- создать backup БД;
- проверить backup файлового private storage;
- выполнить миграции на staging;
- не менять crypto keys одновременно с schema migration без отдельного плана re-encryption.

## 12. Как добавлять новый модуль

Рекомендуемая последовательность:
1. определить DB contract/migration;
2. создать Model только для простого persistence, при сложной логике — Service;
3. реализовать Controller/Socket, оставив business rules в Service;
4. зарегистрировать route/action в explicit config/allowlist;
5. добавить owner/role/membership ACL;
6. обновить template/JS;
7. добавить integration workflow;
8. обновить `README.md`, `CHANGELOG.md`, `docs/USER_GUIDE.md` и эту документацию, если изменилось ядро.

## 13. Инварианты безопасности

При разработке считаются обязательными:
- state-changing HTTP action не должен быть GET;
- POST/DELETE должен проходить CSRF policy;
- user identity нельзя доверять из browser payload, если она уже известна из session/socket;
- uploads никогда не исполняются веб-сервером;
- physical storage path не возвращается клиенту;
- crypto failure не приводит к plaintext fallback;
- SQL identifiers/sort fields выбираются из allowlist;
- soft-delete учитывается во всех ACL/read paths;
- realtime broadcast не должен расширять права по сравнению с обычным HTTP/service read path.
